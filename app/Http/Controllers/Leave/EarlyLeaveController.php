<?php

namespace App\Http\Controllers\Leave;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AppGuardianChild;
use App\Models\AppUser;
use App\Services\GuardianChildService;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\HwAssignment\IdempotencyService;
use App\Services\Leave\EarlyLeaveService;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EarlyLeaveController extends Controller
{
    public function __construct(
        private EarlyLeaveService $earlyLeave,
        private GuardianChildService $children,
        private IdempotencyService $idempotency,
    ) {}

    public function index(Request $request, int $studentId): JsonResponse
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'digits:4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'status' => ['nullable', 'in:pending_parent,pending_teacher,pending_final,approved,rejected,expired,cancelled,released'],
        ]);

        return ApiResponse::success('Early leave', $this->earlyLeave->overview($this->student($request, $studentId), $studentId, $input));
    }

    public function show(Request $request, int $studentId, string $requestId): JsonResponse
    {
        return ApiResponse::success('Request', $this->earlyLeave->request($this->student($request, $studentId), $studentId, $this->id($requestId)));
    }

    public function store(Request $request, int $studentId): JsonResponse
    {
        $caller = $this->student($request, $studentId);

        if (! in_array($studentId, $caller->activeStudentIds, true)) {
            throw new ApiException('This student is not active.', 403);
        }

        $input = $request->validate([
            'leave_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:60'],
            'details' => ['nullable', 'string', 'max:1000'],
            'guardian_id' => ['nullable', 'string', 'max:20'],
            'pickup' => ['required', 'string', 'max:150'],
            'attachment' => ['nullable', 'file'],
        ]);

        $result = $this->idempotency->once($request, $caller, "students/$studentId/early-leave", 201, fn () => $this->earlyLeave->create(
            $caller,
            $studentId,
            $input,
            $request->file('attachment'),
        ));

        return $this->respond($request, 'Early leave requested', $result, ['requestId' => $result['data']['id']]);
    }

    public function queue(Request $request): JsonResponse
    {
        $user = $request->attributes->get('app_user');

        if ($user->role !== AppUser::ROLE_GUARDIAN) {
            return ApiResponse::success('Queue', ['requests' => $this->earlyLeave->staffQueue($request->attributes->get('leave_caller'))]);
        }

        $requests = [];

        // One school being down must not hide the requests of the other children.
        foreach (AppGuardianChild::where('app_user_id', $user->id)->orderBy('id')->pluck('id') as $childId) {
            try {
                $caller = $this->guardianCaller($user, (int) $childId);

                foreach ($this->earlyLeave->guardianQueueForChild($caller, $caller->studentIds[0]) as $item) {
                    $requests[] = $item + ['child_id' => (string) $childId];
                }
            } catch (ApiException|QueryException $e) {
                report($e);
            }
        }

        return ApiResponse::success('Queue', ['requests' => $requests]);
    }

    public function decide(Request $request, string $requestId): JsonResponse
    {
        $user = $request->attributes->get('app_user');
        $id = $this->id($requestId);

        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // A guardian's children can be in different schools, and request ids are per school.
            'child_id' => [$user->role === AppUser::ROLE_GUARDIAN ? 'required' : 'nullable', 'integer'],
        ]);

        $caller = $user->role === AppUser::ROLE_GUARDIAN
            ? $this->guardianCaller($user, (int) $input['child_id'])
            : $request->attributes->get('leave_caller');

        $result = $this->idempotency->once($request, $caller, "early-leave/$id/decision", 200, fn () => $this->earlyLeave->decide($caller, $id, $input['decision'], $input['remarks'] ?? null));

        return $this->respond($request, 'Decision saved', $result);
    }

    public function register(Request $request): JsonResponse
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return ApiResponse::success('Register', $this->earlyLeave->register($request->attributes->get('leave_caller'), $input['date'] ?? HomeworkTime::today()));
    }

    public function gate(Request $request): JsonResponse
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return ApiResponse::success('Gate list', $this->earlyLeave->gate($request->attributes->get('leave_caller'), $input['date'] ?? HomeworkTime::today()));
    }

    public function release(Request $request, string $requestId): JsonResponse
    {
        $caller = $request->attributes->get('leave_caller');
        $id = $this->id($requestId);

        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'handed_to' => ['nullable', 'string', 'max:150'],
            'handed_to_relation' => ['nullable', 'string', 'max:40'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->idempotency->once($request, $caller, "early-leave/$id/release", 200, fn () => $this->earlyLeave->release($caller, $id, $input));

        return $this->respond($request, 'Student released', $result);
    }

    public function summary(Request $request): JsonResponse
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'digits:4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return ApiResponse::success('Early leave summary', $this->earlyLeave->summary(
            $request->attributes->get('leave_caller'),
            (string) ($input['year'] ?? substr(HomeworkTime::today(), 0, 4)),
            (int) ($input['month'] ?? substr(HomeworkTime::today(), 5, 2)),
        ));
    }

    private function guardianCaller(AppUser $user, int $childId): HomeworkCaller
    {
        [$db, $studentId, $dbName] = $this->children->currentStudent($user, $childId);

        return new HomeworkCaller($user, $db, $dbName, studentIds: [$studentId], activeStudentIds: [$studentId]);
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

    /**
     * Paths take 3 or the contract's el-3.
     */
    private function id(string $value): int
    {
        return (int) preg_replace('/\D/', '', $value);
    }
}
