<?php

namespace App\Http\Controllers\StudentProfile;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\StudentProfile\ChangeRequestService;
use App\Services\StudentProfile\ProfileReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    public function __construct(
        private ProfileReader $reader,
        private ChangeRequestService $changeRequests,
    ) {}

    /**
     * 1.1 One student's full profile: the student themself, or a teacher of the student's class.
     */
    public function show(Request $request, int $studentId): JsonResponse
    {
        $caller = $request->attributes->get('profile_caller');

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

        return ApiResponse::success('Profile', $this->reader->profile($caller->db, $row, $caller->isTeacher()));
    }

    /**
     * 1.2 Ask the school to correct a profile field. A queued write: the same Idempotency-Key twice
     * is one request, and a repeat answers 200 instead of 201.
     */
    public function changeRequest(Request $request, int $studentId): JsonResponse
    {
        $caller = $request->attributes->get('profile_caller');

        // The student in the URL must be the caller. A path id never decides who they are.
        if (! in_array($studentId, $caller->studentIds, true)) {
            throw new ApiException('You do not have access to this', 403);
        }

        $input = $request->validate([
            'field' => ['required', 'string', 'max:100'],
            'requested_value' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = $this->reader->findRow($caller->db, $studentId)
            ?? throw new ApiException('Student not found', 404);

        $created = $this->changeRequests->create(
            $caller,
            $row,
            $input + ['note' => null],
            $request->header('Idempotency-Key') ?: null,
            $request->ip(),
        );

        if (! $created) {
            return ApiResponse::success('Correction request already sent')->header('Idempotent-Replayed', 'true');
        }

        $request->attributes->set('activity_message', 'A student asked to correct a profile field');

        return ApiResponse::success('Correction request sent', null, 201);
    }
}
