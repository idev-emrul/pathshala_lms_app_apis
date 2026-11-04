<?php

namespace App\Http\Controllers\Leave;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\HwAssignment\IdempotencyService;
use App\Services\Leave\LeaveService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(
        private LeaveService $leave,
        private IdempotencyService $idempotency,
    ) {}

    public function index(Request $request, int $studentId): JsonResponse
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'digits:4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:pending,approved,rejected,withdrawn'],
            'cursor' => ['nullable', 'string', 'max:100'],
        ]);

        return ApiResponse::success('Leave', $this->leave->overview($this->student($request, $studentId), $studentId, $input));
    }

    public function show(Request $request, int $studentId, string $applicationId): JsonResponse
    {
        return ApiResponse::success('Application', $this->leave->application($this->student($request, $studentId), $studentId, $this->id($applicationId)));
    }

    public function store(Request $request, int $studentId): JsonResponse
    {
        $caller = $this->activeStudent($request, $studentId);

        $input = $request->validate([
            'type_id' => ['required', 'string', 'max:60'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file'],
        ]);

        $result = $this->idempotency->once($request, $caller, "students/$studentId/leave", 201, fn () => $this->leave->apply(
            $caller,
            $studentId,
            $input,
            $request->file('attachment'),
        ));

        return $this->respond($request, 'Leave application submitted', $result, ['applicationId' => $result['data']['id']]);
    }

    public function withdraw(Request $request, int $studentId, string $applicationId): JsonResponse
    {
        $caller = $this->activeStudent($request, $studentId);
        $id = $this->id($applicationId);

        $input = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);

        $result = $this->idempotency->once($request, $caller, "students/$studentId/leave/$id/withdraw", 200, fn () => $this->leave->withdraw($caller, $studentId, $id, $input['remarks'] ?? null));

        return $this->respond($request, 'Application withdrawn', $result);
    }

    public function review(Request $request): JsonResponse
    {
        $input = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);

        return ApiResponse::success('Review queue', $this->leave->reviewQueue($request->attributes->get('leave_caller'), $input['status'] ?? 'pending'));
    }

    public function decide(Request $request, string $applicationId): JsonResponse
    {
        $caller = $request->attributes->get('leave_caller');
        $id = $this->id($applicationId);

        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
        ]);

        $result = $this->idempotency->once($request, $caller, "leave/review/$id/decision", 200, fn () => $this->leave->decide($caller, $id, $input['decision'], $input['remarks'] ?? null));

        return $this->respond($request, 'Decision saved', $result);
    }

    public function summary(Request $request): JsonResponse
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'digits:4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return ApiResponse::success('Leave summary', $this->leave->summary(
            $request->attributes->get('leave_caller'),
            (string) ($input['year'] ?? substr(HomeworkTime::today(), 0, 4)),
            isset($input['month']) ? (int) $input['month'] : null,
        ));
    }

    private function respond(Request $request, string $message, array $result, array $details = []): JsonResponse
    {
        $request->attributes->set('activity_details', $details);
        $request->attributes->set('activity_message', $message);

        $response = ApiResponse::success($message, $result['data'], $result['status']);

        return $result['replayed'] ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    private function student(Request $request, int $studentId): HomeworkCaller
    {
        $caller = $request->attributes->get('leave_caller');

        if (! in_array($studentId, $caller->studentIds, true)) {
            throw new ApiException('You do not have access to this', 403);
        }

        return $caller;
    }

    private function activeStudent(Request $request, int $studentId): HomeworkCaller
    {
        $caller = $this->student($request, $studentId);

        if (! in_array($studentId, $caller->activeStudentIds, true)) {
            throw new ApiException('This student is not active.', 403);
        }

        return $caller;
    }

    /**
     * Paths take 3 or the contract's lv-3.
     */
    private function id(string $value): int
    {
        return (int) preg_replace('/\D/', '', $value);
    }
}
