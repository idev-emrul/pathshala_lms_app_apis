<?php

namespace App\Http\Controllers\ClassRoutine;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ClassRoutine\RoutineReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentRoutineController extends Controller
{
    public function __construct(private RoutineReader $reader) {}

    /**
     * 3.1 A student's periods for one date.
     */
    public function show(Request $request, int $studentId): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $caller = $request->attributes->get('routine_caller');

        // The student in the URL must be the caller. A path id never decides who they are.
        $placement = $caller->placements[$studentId] ?? throw new ApiException('You do not have access to this', 403);

        return ApiResponse::success('Routine', $this->reader->studentPeriods($caller->db, $placement, $date));
    }
}
