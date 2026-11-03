<?php

namespace App\Http\Controllers\ResultReport;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ResultReport\CommentService;
use App\Services\ResultReport\ResultCaller;
use App\Services\ResultReport\ResultReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherResultController extends Controller
{
    public function __construct(
        private ResultReader $reader,
        private CommentService $comments,
    ) {}

    /**
     * 2.2 Exams of the class teacher's class, oldest first. A teacher does see pending and processing exams.
     */
    public function exams(Request $request): JsonResponse
    {
        $caller = $request->attributes->get('result_caller');

        return ApiResponse::success('Exams', array_map(
            $this->reader->examSummary(...),
            $this->reader->teacherExams($caller->db, $caller->teacherCode),
        ));
    }

    /**
     * 2.3 The class result sheet for one exam.
     */
    public function exam(Request $request, int $examId): JsonResponse
    {
        $caller = $request->attributes->get('result_caller');
        $exam = $this->teacherExam($caller, $examId);

        return ApiResponse::success('Result sheet', $this->reader->examSheet($caller->db, $caller->teacherCode, $exam));
    }

    /**
     * 2.4 Save the teacher's comment on a student's result. {studentId} is the student's
     * current_status_info_id, as in the result sheet's rows.
     */
    public function comment(Request $request, int $examId, int $studentId): JsonResponse
    {
        $caller = $request->attributes->get('result_caller');
        $comment = $request->validate(['comment' => ['present', 'nullable', 'string']])['comment'] ?? '';
        $exam = $this->teacherExam($caller, $examId);

        if (! $this->reader->teacherStudentRow($caller->db, $caller->teacherCode, $exam, $studentId)) {
            throw new ApiException('This student is not in your class', 403);
        }

        // The result row must exist (merit list submitted) before a comment can be added.
        if (! $this->reader->hasResult($caller->db, $studentId, $examId)) {
            throw new ApiException('Comments are not enabled for this exam yet', 403);
        }

        $this->comments->save($caller, $studentId, $examId, $comment);

        $request->attributes->set('activity_message', 'A teacher saved a result comment');

        return ApiResponse::success('Comment saved');
    }

    /**
     * The exam if it belongs to the teacher's class: 404 when there is no such exam, 403 when it is
     * another class's exam.
     */
    private function teacherExam(ResultCaller $caller, int $examId): object
    {
        $exam = $this->reader->teacherExams($caller->db, $caller->teacherCode, $examId)[0] ?? null;

        if ($exam) {
            return $exam;
        }

        throw $this->reader->examExists($caller->db, $examId)
            ? new ApiException('This exam is not of your class', 403)
            : new ApiException('Exam not found', 404);
    }
}
