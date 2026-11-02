<?php

namespace App\Http\Controllers;

use App\Services\SchoolDbService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SchoolController extends Controller
{
    public function __construct(private SchoolDbService $schools) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success('Schools', $this->schools->schools());
    }

    public function classes(int $schlId): JsonResponse
    {
        return ApiResponse::success('Classes', $this->schools->classes($this->schools->findSchool($schlId)));
    }
}
