<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guardian routes. Runs after jwt.app.
 */
class AuthorizeGuardian
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('app_user')->role !== AppUser::ROLE_GUARDIAN) {
            throw new ApiException('You do not have access to this', 403);
        }

        return $next($request);
    }
}
