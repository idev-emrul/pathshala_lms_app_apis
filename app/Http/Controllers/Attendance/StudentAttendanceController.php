<?php

namespace App\Http\Controllers\Attendance;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceCaller;
use App\Services\Attendance\AttendanceReader;
use App\Services\HwAssignment\HomeworkTime;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAttendanceController extends Controller
{
    public function __construct(private AttendanceReader $reader) {}

    /**
     * 10.1 The attendance page for a duration (?from=&to=), as the web profile's attendance search.
     * Without dates: the session start to today. The school has no terms.
     */
    public function report(Request $request, int $studentId): JsonResponse
    {
        [$current, $enrolments] = $this->student($request, $studentId);

        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $input['from'] ?? $this->reader->sessionStart($current);
        $to = $input['to'] ?? HomeworkTime::today();

        if ($to < $from) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'The to date must not be before from.']);
        }

        return ApiResponse::success('Attendance report', $this->reader->report($request->attributes->get('attendance_caller')->db, $current, $enrolments, $from, $to));
    }

    /**
     * 10.2 Every day of one month (?month=YYYY-MM), up to today.
     */
    public function month(Request $request, int $studentId): JsonResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        [, $enrolments] = $this->student($request, $studentId);

        return ApiResponse::success('Attendance month', $this->reader->month(
            $request->attributes->get('attendance_caller')->db,
            $enrolments,
            (int) substr($month, 0, 4),
            (int) substr($month, 5, 2),
        ));
    }

    /**
     * 10.3 The current session: percentage, counts and a monthly chart.
     */
    public function summary(Request $request, int $studentId): JsonResponse
    {
        [$current, $enrolments] = $this->student($request, $studentId);

        return ApiResponse::success('Attendance summary', $this->reader->summary($request->attributes->get('attendance_caller')->db, $current, $enrolments));
    }

    /**
     * The student in the URL must be the caller. A path id never decides who they are.
     */
    private function student(Request $request, int $studentId): array
    {
        /** @var AttendanceCaller $caller */
        $caller = $request->attributes->get('attendance_caller');

        if (! in_array($studentId, $caller->studentIds, true)) {
            throw new ApiException('You do not have access to this', 403);
        }

        $row = $this->reader->findRow($caller->db, $studentId)
            ?? throw new ApiException('Student not found', 404);

        return $this->reader->student($caller->db, $row);
    }
}
