<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use Illuminate\Support\Facades\RateLimiter;

class TeacherAuthService
{
    public function __construct(
        private SchoolDbService $schools,
        private TokenService $tokens,
        private StudentAuthService $students,
    ) {}

    /**
     * Unknown usernames and wrong passwords look the same, so usernames cannot be discovered.
     */
    public function verify(string $flowToken, int $schlId, string $username, string $password): array
    {
        $flow = $this->teacherFlow($flowToken);

        $limitKey = 'teacher_password:'.$schlId.':'.mb_strtolower($username);

        $this->students->ensureNotLimited($limitKey);

        $school = $this->schools->findSchool($schlId);
        $account = $this->schools->findTeacherAccount($this->schools->connectToSchool($school), $username);

        // password_verify, because Hash::check refuses the $2a$ hashes in the users table.
        if (! $account || ! password_verify($password, $account->password)) {
            RateLimiter::hit($limitKey, config('pathshala.login_limit.seconds'));

            throw new ApiException('The information you entered is not valid');
        }

        RateLimiter::clear($limitKey);

        $this->students->ensureNumberIsAvailable($flow['mobile_number'], $flow['role'], (int) $school->schl_id, (string) $account->id);

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_READY, [
                'mobile_number' => $flow['mobile_number'],
                'role' => $flow['role'],
                'schl_id' => $school->schl_id,
                'school_name' => $school->schl_name,
                'class_code' => null,
                'class_name' => null,
                'student_id' => null,
                'student_name' => null,
                'teacher_user_id' => $account->id,
                'teacher_name' => $account->teacher_name,
                'is_verified' => true,
                'verified_by' => 'password',
            ]),
            'next' => 'set_pin',
        ];
    }

    /**
     * Guest login with a typed school name ("Others"). A school from the list always needs a login.
     */
    public function guest(string $flowToken, string $schoolName): array
    {
        $flow = $this->teacherFlow($flowToken);

        $this->students->ensureNumberIsAvailable($flow['mobile_number'], $flow['role'], null, null);

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_READY, [
                'mobile_number' => $flow['mobile_number'],
                'role' => $flow['role'],
                'schl_id' => null,
                'school_name' => $schoolName,
                'class_code' => null,
                'class_name' => null,
                'student_id' => null,
                'student_name' => null,
                'is_verified' => false,
                'verified_by' => null,
            ]),
            'next' => 'set_pin',
        ];
    }

    private function teacherFlow(string $flowToken): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_MOBILE_VERIFIED]);

        if ($flow['role'] !== AppUser::ROLE_TEACHER) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        return $flow;
    }
}
