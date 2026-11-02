<?php

namespace App\Http\Controllers;

use App\Services\StudentAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAuthController extends Controller
{
    public function __construct(private StudentAuthService $students) {}

    public function check(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'schl_id' => ['required', 'integer'],
            'class_code' => ['required', 'integer'],
            'student_id' => ['required', 'string', 'regex:/^\d{1,12}$/'],
        ]);

        return ApiResponse::success('Student found', $this->students->check(
            $input['flow_token'],
            (int) $input['schl_id'],
            (int) $input['class_code'],
            $input['student_id'],
        ));
    }

    public function verifyPassword(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'password' => ['required', 'string', 'max:50'],
        ]);

        return ApiResponse::success('Student verified', $this->students->verifyPassword($input['flow_token'], $input['password']));
    }

    public function sendContactOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['flow_token' => ['required', 'string']]);

        $sent = $this->students->sendContactOtp($input['flow_token']);

        return ApiResponse::success('OTP sent to '.$sent['masked_number'], $sent['otp']);
    }

    public function verifyContactOtp(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'otp' => ['required', 'digits:6'],
        ]);

        return ApiResponse::success('Student verified', $this->students->verifyContactOtp($input['flow_token'], $input['otp']));
    }

    public function guest(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'schl_id' => ['nullable', 'integer', 'required_without:school_name'],
            'class_code' => ['nullable', 'integer', 'required_with:schl_id'],
            'school_name' => ['nullable', 'string', 'max:150', 'required_without:schl_id'],
            'class_name' => ['nullable', 'string', 'max:100', 'required_without:schl_id'],
            'student_id' => ['required', 'string', 'max:20'],
        ]);

        return ApiResponse::success('Continue as guest', $this->students->guest($input['flow_token'], $input));
    }
}
