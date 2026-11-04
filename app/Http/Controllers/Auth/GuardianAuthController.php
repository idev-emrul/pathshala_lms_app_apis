<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GuardianAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianAuthController extends Controller
{
    public function __construct(private GuardianAuthService $guardians) {}

    public function check(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'schl_id' => ['required', 'integer'],
            'class_code' => ['required', 'integer'],
            'student_id' => ['required', 'string', 'regex:/^\d{1,12}$/'],
        ]);

        return ApiResponse::success('Child found', $this->guardians->check(
            $input['flow_token'],
            (int) $input['schl_id'],
            (int) $input['class_code'],
            $input['student_id'],
        ));
    }

    public function sendChildOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['flow_token' => ['required', 'string']]);

        $sent = $this->guardians->sendOtp($input['flow_token']);

        return ApiResponse::success('OTP sent to '.$sent['masked_number'], $sent['otp']);
    }

    public function verifyChildOtp(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'otp' => ['required', 'digits:6'],
        ]);

        return ApiResponse::success('Child verified', $this->guardians->verifyOtp($input['flow_token'], $input['otp']));
    }
}
