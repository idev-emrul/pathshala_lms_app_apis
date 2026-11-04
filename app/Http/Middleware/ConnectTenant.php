<?php

namespace App\Http\Middleware;

use App\Services\SchoolDbService;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connects the user's school database. Runs after jwt.app.
 * "Others" users have no school database, so no tenant is set for them.
 */
class ConnectTenant
{
    public function __construct(
        private TokenService $tokens,
        private SchoolDbService $schools,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $dbName = $this->tokens->decryptDbName($request->attributes->get('app_claims'));

        if ($dbName) {
            $request->attributes->set('tenant', $this->schools->connect($dbName));
        }

        return $next($request);
    }
}
