<?php

namespace App\Http\Controllers\Behaviour;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Behaviour\BehaviourReportService;
use App\Services\Behaviour\BehaviourSupport;
use App\Services\Behaviour\BehaviourText;
use App\Services\HwAssignment\HomeworkCaller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The contract's two proposals, which the web system's API already has.
 */
class BehaviourReportController extends Controller
{
    public function __construct(
        private BehaviourSupport $support,
        private BehaviourReportService $reports,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $caller = $this->caller($request);
        $filters = $this->filters($request, $caller);
        [$current, $previous, $periods] = $this->periods($request, $caller, $filters['session_code']);

        return ApiResponse::success('Dashboard', $this->periodFields($current, $previous, $periods) + $this->reports->dashboard($caller, $filters, $current, $previous));
    }

    public function reports(Request $request): JsonResponse
    {
        $caller = $this->caller($request);
        $filters = $this->filters($request, $caller);
        $subject = $this->digits($request->query('subject_id'));

        if ($request->query('scope') === 'student') {
            $studentId = BehaviourText::studentId($request->query('student_id'));

            if (! $studentId) {
                throw ValidationException::withMessages(['student_id' => 'student_id is required for scope=student.']);
            }

            $student = $this->support->scopedStudent($caller, $studentId)
                ?? throw new ApiException('This student is not one of your students.', 403);

            [$current, $previous, $periods] = $this->periods($request, $caller, $student->session_code);

            return ApiResponse::success('Report', $this->reports->studentReport($caller, $student, $current, $previous, $subject ? (int) $subject : null) + $this->periodFields($current, $previous, $periods));
        }

        if (! $filters['class_code']) {
            throw ValidationException::withMessages(['class_id' => 'class_id is required for scope=class.']);
        }

        [$current, $previous, $periods] = $this->periods($request, $caller, $filters['session_code']);

        return ApiResponse::success('Report', $this->reports->classReport($caller, $filters + ['subject_id' => $subject], $current, $previous) + $this->periodFields($current, $previous, $periods));
    }

    private function caller(Request $request): HomeworkCaller
    {
        return $request->attributes->get('behaviour_caller');
    }

    private function filters(Request $request, HomeworkCaller $caller): array
    {
        return [
            'session_code' => $this->digits($request->query('session_id')) ?? $this->support->runningSessionCode($caller->db),
            'class_code' => $this->digits($request->query('class_id')),
            'section_code' => $this->digits($request->query('section_id')),
        ];
    }

    /**
     * @return array{0: array, 1: ?array, 2: array} The current period, the one to compare with, and all of them.
     */
    private function periods(Request $request, HomeworkCaller $caller, mixed $sessionCode): array
    {
        $periods = $request->query('period') === 'month'
            ? $this->support->monthPeriods()
            : $this->support->periods($caller->db, $sessionCode);

        $current = $this->support->periodIndex($periods, $request->query('current'));

        if ($current < 0) {
            $current = $this->support->currentIndex($periods);
        }

        $previous = $this->support->periodIndex($periods, $request->query('previous'));

        if ($previous < 0 || $previous === $current) {
            $previous = $current > 0 ? $current - 1 : -1;
        }

        return [$periods[$current], $previous >= 0 ? $periods[$previous] : null, $periods];
    }

    private function periodFields(array $current, ?array $previous, array $periods): array
    {
        return [
            'period' => $this->reports->periodShape($current),
            'previous_period' => $previous ? $this->reports->periodShape($previous) : null,
            'periods' => array_map(fn (array $period) => $this->reports->periodShape($period), $periods),
        ];
    }

    private function digits(mixed $value): ?string
    {
        return is_scalar($value) && ctype_digit((string) $value) ? (string) $value : null;
    }
}
