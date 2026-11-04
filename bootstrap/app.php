<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateAppToken;
use App\Http\Middleware\AuthorizeGuardian;
use App\Http\Middleware\AuthorizeHomework;
use App\Http\Middleware\AuthorizeLeave;
use App\Http\Middleware\ConnectTenant;
use App\Http\Middleware\RecordActivity;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'jwt.app' => AuthenticateAppToken::class,
            'tenant' => ConnectTenant::class,
            'homework' => AuthorizeHomework::class,
            'leave' => AuthorizeLeave::class,
            'guardian' => AuthorizeGuardian::class,
            'activity' => RecordActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Wrong OTPs, failed checks and the like are normal, not errors to log.
        $exceptions->dontReport(ApiException::class);

        $exceptions->render(fn (ApiException $e) => ApiResponse::error($e->getMessage(), $e->status, $e->data, code: $e->errorCode));

        $exceptions->render(fn (ValidationException $e) => ApiResponse::error(
            $e->getMessage(),
            $e->status,
            errors: $e->errors(),
        ));

        $exceptions->render(fn (NotFoundHttpException $e) => ApiResponse::error('Not found', 404));

        $exceptions->render(fn (HttpExceptionInterface $e) => ApiResponse::error(
            $e->getStatusCode() === 429 ? 'Too many requests. Please try again later.' : ($e->getMessage() ?: 'Request failed'),
            $e->getStatusCode(),
            headers: $e->getHeaders(),
        ));

        // Never expose SQL, database names or stack traces. The error is still logged.
        $exceptions->render(fn (Throwable $e) => ApiResponse::error('Something went wrong. Please try again later.', 500));
    })->create();
