<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\RateLimiter;

class AdminStaffAuthService
{
    public function __construct(
        private SchoolDbService $schools,
        private TokenService $tokens,
        private StudentAuthService $students,
    ) {}

    /**
     * There is no guest login for these roles, so this is the only way to reach Set PIN.
     * Unknown usernames, wrong passwords and the wrong user type all look the same.
     */
    public function verify(string $role, string $flowToken, int $schlId, string $username, string $password): array
    {
        $flow = $this->tokens->readFlowToken($flowToken, [TokenService::STEP_MOBILE_VERIFIED]);

        if ($flow['role'] !== $role) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        $limitKey = 'admin_staff_password:'.$schlId.':'.mb_strtolower($username);

        $this->students->ensureNotLimited($limitKey);

        $school = $this->schools->findSchool($schlId);
        $account = $this->schools->findAdminStaffAccount($this->schools->connectToSchool($school), $username, $role);

        // password_verify, because Hash::check refuses the $2a$ hashes in the users table.
        if (! $account || ! password_verify($password, $account->password)) {
            RateLimiter::hit($limitKey, config('pathshala.login_limit.seconds'));

            throw new ApiException('The information you entered is not valid');
        }

        RateLimiter::clear($limitKey);

        $this->students->ensureNumberIsAvailable($flow['mobile_number'], $role, (int) $school->schl_id, (string) $account->id);

        return [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_READY, [
                'mobile_number' => $flow['mobile_number'],
                'role' => $role,
                'schl_id' => $school->schl_id,
                'school_name' => $school->schl_name,
                'class_code' => null,
                'class_name' => null,
                'student_id' => null,
                'student_name' => null,
                'account_user_id' => $account->id,
                'account_name' => $account->user_full_name,
                'is_verified' => true,
                'verified_by' => 'password',
            ]),
            'next' => 'set_pin',
        ];
    }
}
