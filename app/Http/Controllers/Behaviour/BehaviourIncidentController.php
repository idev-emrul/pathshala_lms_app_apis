<?php

namespace App\Http\Controllers\Behaviour;

use App\Http\Controllers\Controller;
use App\Services\Behaviour\BehaviourIncidentService;
use App\Services\Behaviour\BehaviourSupport;
use App\Services\Behaviour\BehaviourText;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\HwAssignment\IdempotencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class BehaviourIncidentController extends Controller
{
    public function __construct(
        private BehaviourSupport $support,
        private BehaviourIncidentService $incidents,
        private IdempotencyService $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        if (filled($status) && ! in_array($status, BehaviourSupport::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'status must be reported, under_review or resolved.']);
        }

        return ApiResponse::success('Incidents', $this->incidents->list($this->caller($request), [
            'status' => $status,
            'student_id' => BehaviourText::studentId($request->query('student_id')) ?: null,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        $result = $this->idempotency->once($request, $caller, 'behaviour/incidents', 201, function () use ($request, $caller) {
            $student = $this->support->managedStudent($caller, $request->input('student_id'));
            $data = $this->incidentData($request);

            return ['id' => 'inc-'.$this->incidents->create($caller, $student, $data)];
        });

        $request->attributes->set('activity_details', ['incidentId' => $result['data']['id']]);
        $request->attributes->set('activity_message', 'Incident logged');

        $response = ApiResponse::success('Incident logged', $result['data'], $result['status']);

        return $result['replayed'] ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    /**
     * Only an admin. The route allows the admin alone, so a teacher gets 403.
     */
    public function update(Request $request, string $incidentId): JsonResponse
    {
        $this->incidents->update($this->caller($request), (int) preg_replace('/\D/', '', $incidentId), $request->only([
            'status', 'follow_up_note', 'action_taken', 'parent_contacted', 'support_required', 'notify_guardian',
        ]));

        $request->attributes->set('activity_message', 'Incident updated');

        return ApiResponse::success('Incident updated');
    }

    private function caller(Request $request): HomeworkCaller
    {
        return $request->attributes->get('behaviour_caller');
    }

    private function incidentData(Request $request): array
    {
        $errors = [];
        $occurredAt = null;
        $raw = trim((string) $request->input('occurred_at', ''));

        if ($raw === '') {
            $errors['occurred_at'] = 'Required, e.g. 2026-09-18T06:30:00Z.';
        } else {
            try {
                if (Carbon::parse($raw, 'UTC')->isFuture()) {
                    $errors['occurred_at'] = 'Cannot be in the future.';
                } else {
                    $occurredAt = HomeworkTime::fromApi($raw);
                }
            } catch (Throwable) {
                $errors['occurred_at'] = 'Use a date and time like 2026-09-18T06:30:00Z.';
            }
        }

        $category = BehaviourText::clean($request->input('category', ''), 120);
        $description = BehaviourText::clean($request->input('description', ''), 2000);

        if ($category === '') {
            $errors['category'] = 'Required.';
        }

        if ($description === '') {
            $errors['description'] = 'Required.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'occurred_at' => $occurredAt,
            'location' => BehaviourText::clean($request->input('location', ''), 120),
            'category' => $category,
            'description' => $description,
            'action_taken' => BehaviourText::clean($request->input('action_taken', ''), 1000),
            'parent_contacted' => BehaviourText::isTrue($request->input('parent_contacted', false)),
            'support_required' => BehaviourText::isTrue($request->input('support_required', false)),
        ];
    }
}
