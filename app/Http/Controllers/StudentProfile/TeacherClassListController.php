<?php

namespace App\Http\Controllers\StudentProfile;

use App\Http\Controllers\Controller;
use App\Services\StudentProfile\ProfileReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassListController extends Controller
{
    public function __construct(private ProfileReader $reader) {}

    /**
     * 1.3 The class teacher's class list. A teacher who is not a class teacher gets an empty list.
     */
    public function index(Request $request): JsonResponse
    {
        $caller = $request->attributes->get('profile_caller');

        return ApiResponse::success('Class list', $this->reader->classList($caller->db, $caller->teacherCode));
    }
}
