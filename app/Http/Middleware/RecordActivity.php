<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Adds a successful change to the monthly activity file. Only ids are written, never the
 * request body. A repeated create that only returns the first answer changes nothing.
 */
class RecordActivity
{
    public function __construct(private ActivityLogService $activity) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $response = $next($request);
        $user = $request->attributes->get('app_user');

        if ($user && $response->getStatusCode() < 400 && $response->headers->get('Idempotent-Replayed') !== 'true') {
            try {
                $details = ($request->route()?->parameters() ?? []) + $request->attributes->get('activity_details', []);

                $this->activity->record($user, $action, $details, $request->attributes->get('activity_message'));
            } catch (Throwable $e) {
                // A log that cannot be written must not fail the request.
                report($e);
            }
        }

        return $response;
    }
}
