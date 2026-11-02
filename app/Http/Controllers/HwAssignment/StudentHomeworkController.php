<?php

namespace App\Http\Controllers\HwAssignment;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\HwAssignment\HomeworkCaller;
use App\Services\HwAssignment\HomeworkReader;
use App\Services\HwAssignment\SubmissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentHomeworkController extends Controller
{
    public function __construct(
        private HomeworkReader $reader,
        private SubmissionService $submissions,
    ) {}

    public function index(Request $request, int $studentId): JsonResponse
    {
        $caller = $this->caller($request, $studentId);
        $items = $this->reader->studentHomework($caller->db, $studentId);

        return ApiResponse::success('Homework', array_map($this->reader->publicStudentItem(...), $items));
    }

    public function summary(Request $request, int $studentId): JsonResponse
    {
        $caller = $this->caller($request, $studentId);

        return ApiResponse::success('Homework summary', $this->reader->studentSummary($this->reader->studentHomework($caller->db, $studentId)));
    }

    public function submit(Request $request, int $studentId, int $homeworkId): JsonResponse
    {
        $caller = $this->caller($request, $studentId);

        $this->submissions->submit(
            $caller,
            $studentId,
            $homeworkId,
            is_string($request->input('text')) ? $request->input('text') : null,
            is_string($request->input('link')) ? trim($request->input('link')) : null,
            array_values(array_filter((array) $request->file('files'))),
        );

        $item = $this->reader->studentHomework($caller->db, $studentId, $homeworkId)[0];

        $request->attributes->set('activity_message', ($this->reader->studentName($caller->db, $studentId) ?? 'A student').' submitted homework: '.$item['title']);

        return ApiResponse::success('Homework submitted', $this->reader->publicStudentItem($item), 201);
    }

    /**
     * The student in the URL must be the caller. A body or path id never decides who they are.
     */
    private function caller(Request $request, int $studentId): HomeworkCaller
    {
        $caller = $request->attributes->get('homework_caller');

        if (! in_array($studentId, $caller->studentIds, true)) {
            throw new ApiException('You do not have access to this', 403);
        }

        return $caller;
    }
}
