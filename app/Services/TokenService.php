<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class TokenService
{
    public const STEP_MOBILE_VERIFIED = 'mobile_verified';
    public const STEP_STUDENT_FOUND = 'student_found';
    public const STEP_READY = 'ready';

    private const ALGORITHM = 'HS256';
    private const TYPE_APP = 'app';
    private const TYPE_FLOW = 'flow';

    /**
     * Issues the app JWT. It has no expiry: it stops working when it is no longer the user's auth_token.
     * The school's db_name and schl_id are encrypted before they go into the token.
     */
    public function issueAppToken(AppUser $user, ?string $dbName): string
    {
        return $this->encode([
            'typ' => self::TYPE_APP,
            'jti' => (string) Str::uuid(),
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => time(),
            'db' =>$dbName ? Crypt::encryptString($dbName) : null,
            'schl' => $user->schl_id ? Crypt::encryptString((string) $user->schl_id) : null,
        ]);
    }

    /**
     * Returns the claims, or null if the token is invalid or is not an app token.
     */
    public function readAppToken(string $token): ?stdClass
    {
        $claims = $this->decode($token);

        return ($claims && $claims->typ === self::TYPE_APP) ? $claims : null;
    }

    public function decryptDbName(stdClass $claims): ?string
    {
        return $claims->db ? Crypt::decryptString($claims->db) : null;
    }

    public function issueFlowToken(string $step, array $data): string
    {
        return $this->encode([
            'typ' => self::TYPE_FLOW,
            'jti' => (string) Str::uuid(),
            'step' => $step,
            'iat' => time(),
            'exp' => time() + config('pathshala.flow_token_ttl'),
            'd' => Crypt::encrypt($data),
        ]);
    }

    /**
     * Returns the flow data, only if the token is at one of the allowed steps.
     * This is what stops the app skipping a step.
     */
    public function readFlowToken(string $token, array $allowedSteps, bool $singleUse = false): array
    {
        $claims = $this->decode($token);

        if (! $claims || $claims->typ !== self::TYPE_FLOW || ! in_array($claims->step, $allowedSteps, true)) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        if ($singleUse && ! Cache::add('flow_used:'.$claims->jti, true, config('pathshala.flow_token_ttl'))) {
            throw new ApiException('Session expired or invalid. Please start again.');
        }

        return Crypt::decrypt($claims->d);
    }

    private function encode(array $claims): string
    {
        return JWT::encode($claims, $this->signingKey(), self::ALGORITHM);
    }

    private function decode(string $token): ?stdClass
    {
        try {
            return JWT::decode($token, new Key($this->signingKey(), self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The salt is shared by all users, so it is only used in the key and never goes in a token.
     */
    private function signingKey(): string
    {
        return config('pathshala.jwt_secret').config('pathshala.token_salt');
    }
}
