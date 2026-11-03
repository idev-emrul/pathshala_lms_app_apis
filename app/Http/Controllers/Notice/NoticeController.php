<?php

namespace App\Http\Controllers\Notice;

use App\Http\Controllers\Controller;
use App\Services\Notice\NoticeReader;
use App\Services\Notice\NoticeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function __construct(
        private NoticeReader $reader,
        private NoticeService $notices,
    ) {}

    /**
     * 5.1 Notices for the signed-in user. The server works out the audience; the app never filters.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success('Notices', $this->reader->feed($request->attributes->get('notice_caller')));
    }

    /**
     * 5.2 Mark a notice as read. A queued write: a repeat is harmless.
     */
    public function read(Request $request, string $noticeId): JsonResponse
    {
        $this->notices->markRead($request->attributes->get('notice_caller'), $noticeId);

        return ApiResponse::success('Marked as read');
    }

    /**
     * 5.4 Publish a notice (multipart). The same Idempotency-Key never publishes twice.
     */
    public function store(Request $request): JsonResponse
    {
        [$id, $created] = $this->notices->publish($request->attributes->get('notice_caller'), $request);

        if (! $created) {
            return ApiResponse::success('Notice already published', ['id' => (string) $id])->header('Idempotent-Replayed', 'true');
        }

        $request->attributes->set('activity_message', 'A teacher published a notice');
        $request->attributes->set('activity_details', ['noticeId' => (string) $id]);

        return ApiResponse::success('Notice published', ['id' => (string) $id], 201);
    }
}
