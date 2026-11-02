<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use stdClass;

class ApiResponse
{
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data ?? new stdClass,
        ], $status);
    }

    public static function error(
        string $message,
        int $status,
        ?array $data = null,
        ?array $errors = null,
        array $headers = [],
    ): JsonResponse {
        $body = ['status' => false, 'message' => $message];

        if ($data !== null) {
            $body['data'] = $data;
        }

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
