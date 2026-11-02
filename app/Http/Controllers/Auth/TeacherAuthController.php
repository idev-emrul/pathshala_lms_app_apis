<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TeacherAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherAuthController extends Controller
{
    public function __construct(private TeacherAuthService $teachers) {}

    public function verify(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'schl_id' => ['required', 'integer'],
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        return ApiResponse::success('Teacher verified', $this->teachers->verify(
            $input['flow_token'],
            (int) $input['schl_id'],
            $input['username'],
            $input['password'],
        ));
    }

    public function guest(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'school_name' => ['required', 'string', 'max:150'],
        ]);

        return ApiResponse::success('Continue as guest', $this->teachers->guest($input['flow_token'], $input['school_name']));
    }
}
