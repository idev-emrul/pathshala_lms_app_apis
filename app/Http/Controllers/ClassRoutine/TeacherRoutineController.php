<?php

namespace App\Http\Controllers\ClassRoutine;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ClassRoutine\RoutineReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherRoutineController extends Controller
{
    public function __construct(private RoutineReader $reader) {}

    /**
     * 3.2 A teacher's own periods for one date. teacherId is the signed-in user's id (app_user.id),
     * the user.id the app gets from Set PIN and /auth/me.
     */
    public function show(Request $request, int $teacherId): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $caller = $request->attributes->get('routine_caller');

        if ($teacherId !== (int) $caller->user->id) {
            throw new ApiException('You do not have access to this', 403);
        }

        return ApiResponse::success('Routine', $this->reader->teacherPeriods($caller->db, $caller->teacherCode, $date));
    }
}
