<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Support\Phone;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proving a guardian's link to a child: school, class and Student ID, then an OTP to the
 * contact number in the school's records, or to the login number if the child has none.
 * The same steps are used at first login, for a forgotten PIN (a child already on the
 * account), and to add a child after login.
 */
class GuardianAuthService
{
    public function __construct(
        private SchoolDbService $schools,
        private OtpService $otp,
        private TokenService $tokens,
        private StudentAuthService $students,
    ) {}

    public function check(string $flowToken, int $schlId, int $classCode, string $studentId): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_MOBILE_VERIFIED, TokenService::STEP_GUARDIAN_CHILD_FOUND]);

        if ($flow['role'] !== AppUser::ROLE_GUARDIAN || isset($flow['app_user_id'])) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        return $this->findChild($flow['mobile_number'], $schlId, $classCode, $studentId);
    }

    public function sendOtp(string $flowToken): array
    {
        return $this->sendChildOtp($this->childFlow($flowToken, null));
    }

    /**
     * The child is saved at Set PIN, together with the account.
     */
    public function verifyOtp(string $flowToken, string $otp): array
    {
        $flow = $this->childFlow($flowToken, null);

        $this->otp->verify($this->contactNumber($flow), OtpService::PURPOSE_GUARDIAN_CHILD, $otp);

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_READY, [
                'mobile_number' => $flow['mobile_number'],
                'role' => $flow['role'],
                'schl_id' => null,
                'school_name' => null,
                'class_code' => null,
                'class_name' => null,
                'student_id' => null,
                'student_name' => null,
                'is_verified' => true,
                'verified_by' => 'contact_otp',
                'guardian_child' => ['schl_id' => $flow['schl_id'], 'student_id' => $flow['student_id']],
            ]),
            'next' => 'set_pin',
        ];
    }

    public function checkNewChild(AppUser $user, int $schlId, int $classCode, string $studentId): array
    {
        $this->ensureRoomForChild($user);

        $isAdded = AppGuardianChild::where('app_user_id', $user->id)
            ->where('schl_id', $schlId)
            ->where('student_id', $studentId)
            ->exists();

        if ($isAdded) {
            throw new ApiException('This child is already added.', 409);
        }

        return $this->findChild($user->mobile_number, $schlId, $classCode, $studentId, $user);
    }

    public function sendNewChildOtp(AppUser $user, string $flowToken): array
    {
        return $this->sendChildOtp($this->childFlow($flowToken, $user));
    }

    public function addChild(AppUser $user, string $flowToken, string $otp): AppGuardianChild
    {
        $flow = $this->childFlow($flowToken, $user);

        $this->ensureRoomForChild($user);
        $this->otp->verify($this->contactNumber($flow), OtpService::PURPOSE_GUARDIAN_CHILD, $otp);

        return AppGuardianChild::firstOrCreate([
            'app_user_id' => $user->id,
            'schl_id' => $flow['schl_id'],
            'student_id' => $flow['student_id'],
        ]);
    }

    /**
     * The child's name is not returned until the contact OTP is verified, so Student IDs
     * cannot be tried to collect names. Both failures look the same, as in the student login.
     * Only failures count towards the limit, so finding a real child cannot clear it.
     */
    private function findChild(string $mobileNumber, int $schlId, int $classCode, string $studentId, ?AppUser $user = null): array
    {
        $limitKey = "guardian_check:{$mobileNumber}";

        $this->students->ensureNotLimited($limitKey);

        $school = $this->schools->findSchool($schlId);
        $tenant = $this->schools->connectToSchool($school);

        $student = $this->schools->findStudent($tenant, $studentId);
        $placement = $student ? $this->schools->currentPlacement($tenant, $studentId) : null;
        $allowed = $user || $this->students->guardianCanUseChild($mobileNumber, $schlId, $studentId);

        if (! $placement || (int) $placement->class_code !== $classCode || ! $allowed) {
            RateLimiter::hit($limitKey, config('pathshala.login_limit.seconds'));

            throw new ApiException('The information you entered is not valid', 404);
        }

        $contact = Phone::normalize($student->contact_number) ?? $mobileNumber;

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_GUARDIAN_CHILD_FOUND, [
                'mobile_number' => $mobileNumber,
                'role' => AppUser::ROLE_GUARDIAN,
                'app_user_id' => $user?->id,
                'schl_id' => $schlId,
                'student_id' => $studentId,
            ]),
            'masked_number' => Phone::mask($contact),
            'next' => 'send_child_otp',
        ];
    }

    /**
     * Each send can cost an SMS to someone else's phone, so sends count per guardian as well.
     */
    private function sendChildOtp(array $flow): array
    {
        $limitKey = "guardian_child_otp:{$flow['mobile_number']}";

        $this->students->ensureNotLimited($limitKey);

        $contact = $this->contactNumber($flow);
        $sent = $this->otp->send($contact, OtpService::PURPOSE_GUARDIAN_CHILD);

        RateLimiter::hit($limitKey, config('pathshala.login_limit.seconds'));

        return ['masked_number' => Phone::mask($contact), 'otp' => $sent];
    }

    /**
     * A flow started after login only works for that account, and one started before login
     * only works before login. Otherwise adding a child would open a way to change the PIN.
     */
    private function childFlow(string $flowToken, ?AppUser $user): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_GUARDIAN_CHILD_FOUND]);

        if (($flow['app_user_id'] ?? null) !== $user?->id) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        return $flow;
    }

    /**
     * Always the number in the school's records today, not one saved earlier.
     * A child with no usable number there falls back to the guardian's login number.
     */
    private function contactNumber(array $flow): string
    {
        $tenant = $this->schools->connectToSchool($this->schools->findSchool($flow['schl_id']));

        $student = $this->schools->findStudent($tenant, $flow['student_id'])
            ?? throw new ApiException('The information you entered is not valid', 404);

        return Phone::normalize($student->contact_number) ?? $flow['mobile_number'];
    }

    private function ensureRoomForChild(AppUser $user): void
    {
        $max = config('pathshala.guardian.max_children');

        if (AppGuardianChild::where('app_user_id', $user->id)->count() >= $max) {
            throw new ApiException("A guardian can add at most {$max} children.", 409);
        }
    }
}
