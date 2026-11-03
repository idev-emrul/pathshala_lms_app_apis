<?php

namespace App\Http\Controllers\ResultReport;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ResultReport\ResultReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentResultController extends Controller
{
    public function __construct(private ResultReader $reader) {}

    /**
     * 2.1 All published exams of one student, with subjects: the student themself, or a teacher of
     * the student's class.
     */
    public function index(Request $request, int $studentId): JsonResponse
    {
        $caller = $request->attributes->get('result_caller');

        if ($caller->isTeacher()) {
            $row = $this->reader->findRow($caller->db, $studentId)
                ?? throw new ApiException('Student not found', 404);

            // The contract: a teacher asking for a student not assigned to them is refused (403).
            if (! $this->reader->teacherTeaches($caller->db, $caller->teacherCode, $row)) {
                throw new ApiException('You do not have access to this', 403);
            }
        } else {
            // The student in the URL must be the caller. A path id never decides who they are.
            if (! in_array($studentId, $caller->studentIds, true)) {
                throw new ApiException('You do not have access to this', 403);
            }

            $row = $this->reader->findRow($caller->db, $studentId)
                ?? throw new ApiException('Student not found', 404);
        }

        return ApiResponse::success('Results', $this->reader->studentResults($caller->db, $row));
    }
}
