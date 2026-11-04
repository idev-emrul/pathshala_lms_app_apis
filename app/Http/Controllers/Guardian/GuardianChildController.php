<?php

namespace App\Http\Controllers\Guardian;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Services\GuardianAuthService;
use App\Services\GuardianChildService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianChildController extends Controller
{
    public function __construct(
        private GuardianAuthService $guardians,
        private GuardianChildService $children,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::success('Dashboard', $this->children->dashboard($this->user($request)));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success('Children', $this->children->children($this->user($request)));
    }

    public function show(Request $request, int $childId): JsonResponse
    {
        return ApiResponse::success('Child', $this->children->child($this->user($request), $childId));
    }

    public function check(Request $request): JsonResponse
    {
        $input = $request->validate([
            'schl_id' => ['required', 'integer'],
            'class_code' => ['required', 'integer'],
            'student_id' => ['required', 'string', 'regex:/^\d{1,12}$/'],
        ]);

        return ApiResponse::success('Child found', $this->guardians->checkNewChild(
            $this->user($request),
            (int) $input['schl_id'],
            (int) $input['class_code'],
            $input['student_id'],
        ));
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['flow_token' => ['required', 'string']]);

        $sent = $this->guardians->sendNewChildOtp($this->user($request), $input['flow_token']);

        return ApiResponse::success('OTP sent to '.$sent['masked_number'], $sent['otp']);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_token' => ['required', 'string'],
            'otp' => ['required', 'digits:6'],
        ]);

        $child = $this->guardians->addChild($this->user($request), $input['flow_token'], $input['otp']);

        $request->attributes->set('activity_details', ['childId' => (string) $child->id]);
        $request->attributes->set('activity_message', 'Guardian added a child');

        return ApiResponse::success('Child added', $this->children->info($child), 201);
    }

    public function destroy(Request $request, int $childId): JsonResponse
    {
        $this->children->remove($this->user($request), $childId);

        $request->attributes->set('activity_message', 'Guardian removed a child');

        return ApiResponse::success('Child removed');
    }

    private function user(Request $request): AppUser
    {
        return $request->attributes->get('app_user');
    }
}
