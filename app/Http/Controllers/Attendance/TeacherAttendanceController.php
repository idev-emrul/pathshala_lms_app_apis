<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceReader;
use App\Services\Attendance\RegisterService;
use App\Services\HwAssignment\HomeworkTime;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherAttendanceController extends Controller
{
    public function __construct(
        private AttendanceReader $reader,
        private RegisterService $registers,
    ) {}

    /**
     * 10.5 The register of the teacher's own class for a date (?date=, optional ?section_id= when the
     * teacher is class teacher of more than one section).
     */
    public function show(Request $request): JsonResponse
    {
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'section_id' => ['nullable', 'string'],
        ]);

        $caller = $request->attributes->get('attendance_caller');
        $section = $this->registers->section($caller, $input['section_id'] ?? null);

        return ApiResponse::success('Register', $this->reader->register($caller->db, $section, $input['date']));
    }

    /**
     * 10.6 Submit the marked register. A queued write: the date is the one in the body, not the day it
     * arrives, and the same Idempotency-Key is written once.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'section_id' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.HomeworkTime::today()],
            'marks' => ['required', 'array', 'min:1'],
        ]);

        $caller = $request->attributes->get('attendance_caller');
        $section = $this->registers->section($caller, $input['section_id']);

        $written = $this->registers->submit($caller, $section, $input['date'], $input['marks'], $request->header('Idempotency-Key') ?: null, $request->ip());

        if (! $written) {
            return ApiResponse::success('Register already submitted')->header('Idempotent-Replayed', 'true');
        }

        $request->attributes->set('activity_message', 'A teacher submitted the attendance register');
        $request->attributes->set('activity_details', ['section_id' => $section->section_id, 'date' => $input['date']]);

        return ApiResponse::success('Register submitted');
    }
}
