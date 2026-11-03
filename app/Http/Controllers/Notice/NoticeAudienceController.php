<?php

namespace App\Http\Controllers\Notice;

use App\Http\Controllers\Controller;
use App\Services\Notice\NoticeReader;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoticeAudienceController extends Controller
{
    public function __construct(private NoticeReader $reader) {}

    /**
     * 5.3 Classes, sections, groups and custom categories the teacher may target.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success('Audience options', $this->reader->audienceOptions($request->attributes->get('notice_caller')));
    }
}
