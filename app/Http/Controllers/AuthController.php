<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\OtpService;
use App\Services\StudentAuthService;
use App\Services\TokenService;
use App\Support\ApiResponse;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    private const MOBILE_RULE = ['required', 'string', 'regex:/^01[3-9]\d{8}$/'];

    // Only the student role exists in Phase 1.
    private const ROLE_RULE = ['string', 'in:student'];

    public function __construct(
        private OtpService $otp,
        private TokenService $tokens,
        private StudentAuthService $students,
    ) {}

    public function sendOtp(Request $request): JsonResponse
    {
        $input = $request->validate([
            'mobile_number' => self::MOBILE_RULE,
            'role' => ['required', ...self::ROLE_RULE],
        ]);

        $sent = $this->otp->send($input['mobile_number'], OtpService::PURPOSE_LOGIN);

        return ApiResponse::success('OTP sent to '.Phone::mask($input['mobile_number']), $sent);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $input = $request->validate([
            'mobile_number' => self::MOBILE_RULE,
            'otp' => ['required', 'digits:6'],
            'role' => self::ROLE_RULE,
        ]);

        $role = $input['role'] ?? 'student';

        $this->otp->verify($input['mobile_number'], OtpService::PURPOSE_LOGIN, $input['otp']);
        $this->students->recordVerifiedMobile($input['mobile_number'], $role);

        return ApiResponse::success('Mobile verified', [
            'flow_token' => $this->tokens->issueFlowToken(TokenService::STEP_MOBILE_VERIFIED, [
                'mobile_number' => $input['mobile_number'],
                'role' => $role,
            ]),
        ]);
    }

    public function setPin(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'pin' => ['required', 'digits:4', 'confirmed'],
        ]);

        return ApiResponse::success('Login successful', $this->students->setPin($input['flow_token'], $input['pin']));
    }

    public function pinLogin(Request $request): JsonResponse
    {
        $input = $request->validate([
            'mobile_number' => self::MOBILE_RULE,
            'role' => ['required', ...self::ROLE_RULE],
            'pin' => ['required', 'digits:4'],
        ]);

        return ApiResponse::success(
            'Login successful',
            $this->students->pinLogin($input['mobile_number'], $input['role'], $input['pin']),
        );
    }

    public function me(Request $request, ActivityLogService $activity): JsonResponse
    {
        $user = $request->attributes->get('app_user');

        $activity->record($user, 'view_profile');

        return ApiResponse::success('Profile', $this->students->profile($user, $request->attributes->get('tenant')));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->students->logout($request->attributes->get('app_user'));

        return ApiResponse::success('Logged out');
    }
}
