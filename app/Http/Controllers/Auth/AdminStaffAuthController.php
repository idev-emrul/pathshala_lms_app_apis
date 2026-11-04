<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\AdminStaffAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStaffAuthController extends Controller
{
    public function __construct(private AdminStaffAuthService $accounts) {}

    public function verify(Request $request, string $role): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'schl_id' => ['required', 'integer'],
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        return ApiResponse::success('Login verified', $this->accounts->verify(
            AppUser::roleFromAppName($role),
            $input['flow_token'],
            (int) $input['schl_id'],
            $input['username'],
            $input['password'],
        ));
    }
}
