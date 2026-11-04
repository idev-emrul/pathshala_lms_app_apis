<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAppToken
{
    public function __construct(private TokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $claims = $this->tokens->readAppToken($token);
        $user = $claims ? AppUser::find($claims->sub) : null;

        // Logout or a PIN reset replaces auth_token, which is how a token with no expiry is revoked.
        if (! $user?->auth_token || ! hash_equals($user->auth_token, $token)) {
            throw new ApiException('Unauthenticated', 401);
        }

        $request->attributes->set('app_user', $user);
        $request->attributes->set('app_claims', $claims);

        return $next($request);
    }
}
