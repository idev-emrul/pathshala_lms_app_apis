<?php

namespace App\Services\HwAssignment;

use App\Exceptions\ApiException;
use App\Services\SchoolDbService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The same Idempotency-Key returns the first answer, so a repeated tap cannot create homework twice.
 */
class IdempotencyService
{
    public function __construct(private SchoolDbService $schools) {}

    /**
     * @return array{data: array, status: int, replayed: bool}
     */
    public function remember(HomeworkCaller $caller, string $key, string $endpoint, int $status, Closure $action): array
    {
        if (strlen($key) > 100) {
            throw new ApiException('Idempotency-Key is too long', 400);
        }

        $db = $this->schools->connectForHomework($caller->dbName);
        $userKey = $caller->idempotencyKey();

        try {
            $id = $db->table('hw_api_idempotency')->insertGetId([
                'user_key' => $userKey,
                'idem_key' => $key,
                'endpoint' => $endpoint,
                'created_at' => HomeworkTime::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $first = $db->table('hw_api_idempotency')->where('user_key', $userKey)->where('idem_key', $key)->first();

            if (! $first || $first->response_code === null) {
                throw new ApiException('The same request is still running', 409);
            }

            return ['data' => json_decode($first->response_body, true)['data'] ?? [], 'status' => (int) $first->response_code, 'replayed' => true];
        }

        try {
            $data = $action();
        } catch (Throwable $e) {
            // A request that fails frees its key, so the app can retry with the same one.
            $db->table('hw_api_idempotency')->where('id', $id)->delete();

            throw $e;
        }

        $db->table('hw_api_idempotency')->where('id', $id)->update([
            'response_code' => $status,
            'response_body' => json_encode(['data' => $data], JSON_UNESCAPED_UNICODE),
        ]);

        return ['data' => $data, 'status' => $status, 'replayed' => false];
    }
}
