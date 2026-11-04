<?php

namespace App\Http\Controllers\Guardian;

use App\Http\Controllers\Controller;
use App\Services\GuardianChildService;
use App\Services\HwAssignment\HomeworkReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A child's homework, the same as the student sees it. A guardian can only read: handing in is for the student.
 */
class GuardianHomeworkController extends Controller
{
    public function __construct(
        private GuardianChildService $children,
        private HomeworkReader $reader,
    ) {}

    public function index(Request $request, int $childId): JsonResponse
    {
        [$db, $studentId] = $this->children->currentStudent($request->attributes->get('app_user'), $childId);

        return ApiResponse::success('Homework',array_map($this->reader->publicStudentItem(...), $this->reader->studentHomework($db, $studentId)));
    }

    public function summary(Request $request, int $childId): JsonResponse
    {
        [$db, $studentId] = $this->children->currentStudent($request->attributes->get('app_user'), $childId);

        return ApiResponse::success('Homework summary',$this->reader->studentSummary($this->reader->studentHomework($db, $studentId)));
    }
}
