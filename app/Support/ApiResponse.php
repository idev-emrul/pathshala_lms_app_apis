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

    /**
     * The error object is what the app reads. Extra values like next also go inside it.
     */
    public static function error(
        string $message,
        int $status,
        ?array $data = null,
        ?array $errors = null,
        array $headers = [],
        ?string $code = null,
    ): JsonResponse {
        $error = ['code' => $code ?? self::code($status), 'message' => $message];

        if ($errors !== null) {
            $error['details'] = array_map(fn (array $messages) => $messages[0], $errors);
        }

        $body = ['status' => false, 'message' => $message, 'error' => $error + ($data ?? [])];

        if ($data !== null) {
            $body['data'] = $data;
        }

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }

    /**
     * Other 4xx answers are refusals the app shows to the user, so they count as validation_failed.
     */
    private static function code(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'validation_failed',
        };
    }
}
