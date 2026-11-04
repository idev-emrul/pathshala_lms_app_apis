<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Models\AppUserLog;
use App\Support\Phone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class StudentAuthService
{
    private const CAN_USE_GUEST = ['can_continue_as_guest' => true];

    public function __construct(
        private SchoolDbService $schools,
        private OtpService $otp,
        private TokenService $tokens,
        private GuardianChildService $guardianChildren,
    ) {}

    /**
     * Lists the user once their mobile is verified. The PIN stays empty until Set PIN,
     * and an existing user's details are left as they are.
     */
    public function recordVerifiedMobile(string $mobileNumber, string $role): void
    {
        AppUser::firstOrCreate(['mobile_number' => $mobileNumber, 'role' => $role]);
    }

    /**
     * A student is only found if the ID exists and their current class is the selected one.
     * Both failures look the same, so the real class is never revealed.
     */
    public function check(string $flowToken, int $schlId, int $classCode, string $studentId): array
    {
        $flow = $this->studentFlow($flowToken);

        $school = $this->schools->findSchool($schlId);
        $tenant = $this->schools->connectToSchool($school);

        $student = $this->schools->findStudent($tenant, $studentId);
        $placement = $student ? $this->schools->currentPlacement($tenant, $studentId) : null;

        if (! $placement || (int) $placement->class_code !== $classCode) {
            throw new ApiException('The information you entered is not valid', 404, self::CAN_USE_GUEST);
        }

        $flowToken = $this->tokens->issueFlowToken(TokenService::STEP_STUDENT_FOUND, [
            'mobile_number' => $flow['mobile_number'],
            'role' => $flow['role'],
            'schl_id' => $schlId,
            'school_name' => $school->schl_name,
            'class_code' => $classCode,
            'class_name' => $placement->class_name,
            'student_id' => $studentId,
            'student_name' => $student->student_name,
        ]);

        return [
            'flow_token' => $flowToken,
            'student_name' => $student->student_name,
            'has_contact_number' => Phone::normalize($student->contact_number) !== null,
        ];
    }

    public function verifyPassword(string $flowToken, string $password): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_STUDENT_FOUND]);
        $limitKey = "student_password:{$flow['schl_id']}:{$flow['student_id']}";

        $this->ensureNotLimited($limitKey);

        $student = $this->schools->findStudent($this->flowTenant($flow), $flow['student_id']);

        if (! $student || ! hash_equals((string) $student->student_auth, $password)) {
            RateLimiter::hit($limitKey, config('pathshala.login_limit.seconds'));

            throw new ApiException('Incorrect password');
        }

        RateLimiter::clear($limitKey);

        return $this->verifiedStep($flow, 'password');
    }

    public function sendContactOtp(string $flowToken): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_STUDENT_FOUND]);
        $contact = $this->contactNumber($flow);

        return [
            'masked_number' => Phone::mask($contact),
            'otp' => $this->otp->send($contact, OtpService::PURPOSE_CONTACT),
        ];
    }

    public function verifyContactOtp(string $flowToken, string $otp): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_STUDENT_FOUND]);

        $this->otp->verify($this->contactNumber($flow), OtpService::PURPOSE_CONTACT, $otp);

        return $this->verifiedStep($flow, 'contact_otp');
    }

    /**
     * Guest login, either with a school from the list or a typed school name ("Others").
     */
    public function guest(string $flowToken, array $input): array
    {
        $flow = $this->studentFlow($flowToken);

        $this->ensureNumberIsAvailable($flow['mobile_number'], $flow['role'], null, null);

        $details = [
            'schl_id' => null,
            'school_name' => $input['school_name'] ?? null,
            'class_code' => null,
            'class_name' => $input['class_name'] ?? null,
        ];

        if (isset($input['schl_id'])) {
            $school = $this->schools->findSchool($input['schl_id']);
            $class = $this->schools->findClass($school, $input['class_code'])
                ?? throw new ApiException('Class not found', 404);

            $details = [
                'schl_id' => $school->schl_id,
                'school_name' => $school->schl_name,
                'class_code' => $class->class_code,
                'class_name' => $class->class_name,
            ];
        }

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_READY, $details + [
                'mobile_number' => $flow['mobile_number'],
                'role' => $flow['role'],
                'student_id' => $input['student_id'],
                'student_name' => null,
                'is_verified' => false,
                'verified_by' => null,
            ]),
            'next' => 'set_pin',
        ];
    }

    /**
     * Saves the PIN and issues the JWT. Running this for an existing user is a PIN reset,
     * which replaces auth_token so every old token stops working.
     */
    public function setPin(string $flowToken, string $pin): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_READY], singleUse: true);

        $child = $flow['guardian_child'] ?? null;

        // Checked again here, because a flow token can be issued before another login finishes.
        if ($child) {
            if (! $this->guardianCanUseChild($flow['mobile_number'], $child['schl_id'], $child['student_id'])) {
                throw new ApiException('This number is already linked to another account. Log in with your PIN or use a different number.', 409);
            }
        } else {
            $verifiedId = $flow['is_verified'] ? (string) ($flow['account_user_id'] ?? $flow['teacher_user_id'] ?? $flow['student_id']) : null;
            $this->ensureNumberIsAvailable($flow['mobile_number'], $flow['role'], $flow['schl_id'], $verifiedId);
        }

        $user = AppUser::firstOrNew(['mobile_number' => $flow['mobile_number'], 'role' => $flow['role']]);
        $isPinReset = $user->pin !== null;
        $dbName = $flow['schl_id'] ? $this->schools->findSchool($flow['schl_id'])->db_name : null;

        $token = DB::connection('master')->transaction(function () use ($user, $flow, $pin, $isPinReset, $dbName, $child) {
            $user->fill([
                'verified_id' => $flow['is_verified'] ? ($flow['account_user_id'] ?? $flow['teacher_user_id'] ?? $flow['student_id']) : null,
                'is_verified' => $flow['is_verified'],
                'verified_by' => $flow['verified_by'],
                'schl_id' => $flow['schl_id'],
                'school_name' => $flow['school_name'],
                'class_code' => $flow['class_code'],
                'class_name' => $flow['class_name'],
                'entered_student_id' => $flow['student_id'],
                'pin' => Hash::make($pin),
                'last_login_at' => now(),
            ])->save();

            if ($child) {
                AppGuardianChild::firstOrCreate(['app_user_id' => $user->id] + $child);
            }

            $token = $this->tokens->issueAppToken($user, $dbName);
            $user->update(['auth_token' => $token]);

            if ($isPinReset) {
                AppUserLog::record($user, AppUserLog::PIN_RESET);
            }
            AppUserLog::record($user, AppUserLog::LOGIN);

            return $token;
        });

        return $this->loginResult($user, $token, $flow['account_name'] ?? $flow['teacher_name'] ?? $flow['student_name'] ?? null, $dbName);
    }

    public function pinLogin(string $mobileNumber, string $role, string $pin): array
    {
        $limitKey = "pin_login:{$role}:{$mobileNumber}";

        $this->ensureNotLimited($limitKey);

        $user = AppUser::where('mobile_number', $mobileNumber)->where('role', $role)->first();

        if (! $user?->pin || ! Hash::check($pin, $user->pin)) {
            RateLimiter::hit($limitKey, config('pathshala.login_limit.seconds'));

            // Not 401, because the app signs the user out on a 401.
            throw new ApiException('Incorrect mobile number or PIN');
        }

        RateLimiter::clear($limitKey);

        $tenant = $user->schl_id ? $this->schools->findSchool($user->schl_id) : null;

        // After a logout there is no current token, so a new one is issued.
        $token = $user->auth_token ?: $this->tokens->issueAppToken($user, $tenant?->db_name);
        $user->update(['auth_token' => $token, 'last_login_at' => now()]);
        AppUserLog::record($user, AppUserLog::LOGIN);

        return $this->loginResult($user, $token, $this->personName($user, $tenant ? $this->schools->connectToSchool($tenant) : null), $tenant?->db_name);
    }

    /**
     * A number with an account logs in with its PIN. An OTP is only sent to reset the PIN.
     */
    public function ensureNoAccountYet(string $mobileNumber, string $role): void
    {
        $user = AppUser::where('mobile_number', $mobileNumber)->where('role', $role)->first();

        if ($user?->pin) {
            throw new ApiException('This number already has an account. Log in with your PIN.', 422, ['next' => 'pin_login']);
        }
    }

    /**
     * A number belongs to one account per role. Once it has a verified login, only that same
     * person can go through again (a PIN reset). Another person or a guest cannot replace them.
     */
    public function ensureNumberIsAvailable(string $mobileNumber, string $role, ?int $schlId, ?string $verifiedId): void
    {
        $user = AppUser::where('mobile_number', $mobileNumber)->where('role', $role)->first();

        if (! $user?->pin || ! $user->is_verified) {
            return;
        }

        if ($verifiedId === null || (int) $user->schl_id !== $schlId || (string) $user->verified_id !== $verifiedId) {
            throw new ApiException('This number is already linked to another account. Log in with your PIN or use a different number.', 409);
        }
    }

    /**
     * A guardian with an account resets their PIN through a child already on it, so that a
     * person holding only the number cannot get in with some other child's contact OTP.
     */
    public function guardianCanUseChild(string $mobileNumber, int $schlId, string $studentId): bool
    {
        $user = AppUser::where('mobile_number', $mobileNumber)->where('role', AppUser::ROLE_GUARDIAN)->first();

        if (! $user?->pin) {
            return true;
        }

        $children = AppGuardianChild::where('app_user_id', $user->id);

        return ! $children->exists() || $children->where('schl_id', $schlId)->where('student_id', $studentId)->exists();
    }

    public function logout(AppUser $user): void
    {
        DB::connection('master')->transaction(function () use ($user) {
            $user->update(['auth_token' => null]);
            AppUserLog::record($user, AppUserLog::LOGOUT);
        });
    }

    /**
     * The profile for /auth/me. Placement details only exist for verified students.
     */
    public function profile(AppUser $user, ?Connection $tenant): array
    {
        $name = $this->personName($user, $tenant);
        [$placement, $subjects] = $this->schoolDetails($user, $tenant);

        $profile = $this->session($user, $name, $placement, $subjects) + $this->userData($user, $name) + [
            'mobile_number' => Phone::mask($user->mobile_number),
            'language_mode' => $user->language_mode,
        ];

        if ($user->role === AppUser::ROLE_GUARDIAN) {
            return $profile + ['children_count' => AppGuardianChild::where('app_user_id', $user->id)->count()];
        }

        if ($user->role === AppUser::ROLE_TEACHER) {
            $teacher = ($user->is_verified && $tenant) ? $this->schools->findTeacher($tenant, $user->verified_id) : null;

            return $profile + ['teacher_code' => $teacher?->teacher_code];
        }

        if (in_array($user->role, [AppUser::ROLE_ADMIN, AppUser::ROLE_STAFF], true)) {
            return $profile;
        }

        return $profile + [
            'entered_student_id' => $user->entered_student_id,
            'placement' => $placement ? [
                'homework_student_id' => (string) $placement->current_status_info_id,
                'session_name' => $placement->session_name,
                'class_name' => $placement->class_name,
                'section_name' => $placement->section_name,
                'shift_name' => $placement->shift_name,
                'group_name' => $placement->group_name,
                'roll_number' => $placement->roll_number,
            ] : null,
        ];
    }

    /**
     * The entry to the student steps. A teacher's flow token cannot be used here.
     */
    private function studentFlow(string $flowToken): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_MOBILE_VERIFIED, TokenService::STEP_STUDENT_FOUND]);

        if ($flow['role'] !== AppUser::ROLE_STUDENT) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        return $flow;
    }

    private function verifiedStep(array $flow, string $verifiedBy): array
    {
        $this->ensureNumberIsAvailable($flow['mobile_number'], $flow['role'], $flow['schl_id'], (string) $flow['student_id']);

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_READY, $flow + [
                'is_verified' => true,
                'verified_by' => $verifiedBy,
            ]),
            'next' => 'set_pin',
        ];
    }

    private function contactNumber(array $flow): string
    {
        $student = $this->schools->findStudent($this->flowTenant($flow), $flow['student_id']);

        return Phone::normalize($student?->contact_number)
            ?? throw new ApiException('No contact number found', 422, self::CAN_USE_GUEST);
    }

    private function flowTenant(array $flow): Connection
    {
        return $this->schools->connectToSchool($this->schools->findSchool($flow['schl_id']));
    }

    /**
     * verified_id is a users.id or a student ID depending on the role, and the two can overlap.
     */
    private function personName(AppUser $user, ?Connection $tenant): ?string
    {
        if (! $user->is_verified || ! $tenant) {
            return null;
        }

        return match ($user->role) {
            AppUser::ROLE_TEACHER => $this->schools->findTeacher($tenant, $user->verified_id)?->teacher_name,
            AppUser::ROLE_ADMIN, AppUser::ROLE_STAFF => $this->schools->findAdminStaffName($tenant, $user->verified_id),
            default => $this->schools->findStudent($tenant, $user->verified_id)?->student_name,
        };
    }

    public function ensureNotLimited(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, config('pathshala.login_limit.attempts'))) {
            throw new ApiException(
                'Too many wrong attempts. Please try again later.',
                429,
                ['retry_after_seconds' => RateLimiter::availableIn($key)],
            );
        }
    }

    private function loginResult(AppUser $user, string $token, ?string $name, ?string $dbName): array
    {
        [$placement, $subjects] = $this->loginSchoolDetails($user, $dbName);

        return $this->session($user, $name, $placement, $subjects) + [
            'access_token' => $token,
            'token' => $token,
            'token_type' => 'bearer',
            'user' => $this->userData($user, $name),
        ];
    }

    /**
     * The login is already saved by now, so a school that is down must not fail it.
     */
    private function loginSchoolDetails(AppUser $user, ?string $dbName): array
    {
        if (! $dbName) {
            return [null, []];
        }

        try {
            return $this->schoolDetails($user, $this->schools->connect($dbName));
        } catch (ApiException|QueryException $e) {
            report($e);

            return [null, []];
        }
    }

    /**
     * @return array{0: ?object, 1: string[]} A student's current class, and a teacher's subjects.
     */
    private function schoolDetails(AppUser $user, ?Connection $tenant): array
    {
        if (! $user->is_verified || ! $tenant) {
            return [null, []];
        }

        if ($user->role === AppUser::ROLE_STUDENT) {
            return [$this->schools->currentPlacement($tenant, $user->verified_id), []];
        }

        if ($user->role === AppUser::ROLE_TEACHER) {
            $teacherCode = (int) $this->schools->findTeacher($tenant, $user->verified_id)?->teacher_code;

            return [null, $teacherCode ? $this->schools->teacherSubjectNames($tenant, $teacherCode) : []];
        }

        return [null, []];
    }

    /**
     * The session object the app builds its screens from. student_id is the id in /students/{id}
     * paths, and linked_students holds the same id for each of a guardian's children.
     */
    private function session(AppUser $user, ?string $name, ?object $placement, array $subjects): array
    {
        $isGuardian = $user->role === AppUser::ROLE_GUARDIAN;
        $children = $isGuardian ? $this->guardianChildren->children($user) : [];

        $session = [
            'user_id' => (string) $user->id,
            'role' => AppUser::appRoleName($user->role),
            'school_id' => $user->schl_id ? (string) $user->schl_id : null,
            'school_name' => $user->school_name,
            'name' => $name,
            'detail' => match ($user->role) {
                AppUser::ROLE_GUARDIAN => $this->childrenDetail($children),
                AppUser::ROLE_TEACHER => implode(', ', $subjects) ?: null,
                default => $this->placementDetail($placement),
            },
            'permissions' => ($user->is_verified || $isGuardian) ? config("pathshala.permissions.{$user->role}", []) : [],
            'feature_flags' => config('pathshala.feature_flags'),
        ];

        if ($isGuardian) {
            return $session + ['linked_students' => collect($children)->pluck('homework_student_id')->filter()->values()->all()];
        }

        if ($user->role === AppUser::ROLE_STUDENT) {
            return $session + ['student_id' => $placement ? (string) $placement->current_status_info_id : null];
        }

        return $session;
    }

    /**
     * Like the contract's "২ জন সন্তান: রহিম আহমেদ, সাদিয়া আহমেদ". A child who cannot be read has no name to show.
     */
    private function childrenDetail(array $children): ?string
    {
        if (! $children) {
            return null;
        }

        $count = $this->banglaDigits(count($children)).' জন সন্তান';
        $names = collect($children)->pluck('student_name')->filter()->implode(', ');

        return $names ? "{$count}: {$names}" : $count;
    }

    private function banglaDigits(int|string $number): string
    {
        return strtr((string) $number, ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯']);
    }

    private function placementDetail(?object $placement): ?string
    {
        if (! $placement) {
            return null;
        }

        $roll = $placement->roll_number ? 'রোল '.$this->banglaDigits($placement->roll_number) : null;

        return collect([$placement->class_name, $placement->section_name, $roll])->filter()->implode(' · ');
    }

    private function userData(AppUser $user, ?string $name): array
    {
        $data = [
            'id' => $user->id,
            'role' => AppUser::appRoleName($user->role),
            'is_verified' => $user->is_verified,
            'school_name' => $user->school_name,
            'class_name' => $user->class_name,
        ];

        // A guardian has no name or school of their own. Those belong to each child.
        return match ($user->role) {
            AppUser::ROLE_GUARDIAN => $data,
            AppUser::ROLE_TEACHER => $data + ['teacher_name' => $name],
            AppUser::ROLE_ADMIN, AppUser::ROLE_STAFF => $data + ['name' => $name],
            default => $data + ['student_name' => $name],
        };
    }
}
