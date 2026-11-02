<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AppLoginAttempt;
use App\Services\Otp\OtpDriver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public const PURPOSE_LOGIN = 'mobile_login';
    public const PURPOSE_CONTACT = 'student_contact';

    public function __construct(private OtpDriver $driver) {}

    /**
     * @return array{expires_in_seconds: int, resend_left: int}
     */
    public function send(string $mobileNumber, string $purpose): array
    {
        $otp = $this->generate();

        // Refusals are thrown after the transaction, so anything it saved is kept.
        try {
            $result = DB::connection('master')->transaction(fn () => $this->store($mobileNumber, $purpose, $otp));
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('Please wait before requesting a new OTP', 429);
        }

        if ($result['outcome'] === 'limit') {
            throw new ApiException(
                'OTP limit reached. Please check your number and try again later.',
                429,
                ['retry_after_seconds' => $result['retry']],
            );
        }

        if ($result['outcome'] === 'wait') {
            throw new ApiException('Please wait before requesting a new OTP', 429, ['retry_after_seconds' => $result['retry']]);
        }

        $this->driver->send($mobileNumber, $otp);

        return [
            'expires_in_seconds' => config('pathshala.otp.ttl'),
            'resend_left' => config('pathshala.otp.max_sends') - $result['send_count'],
        ];
    }

    /**
     * Returns normally only when the OTP is correct. The record is then deleted.
     */
    public function verify(string $mobileNumber, string $purpose, string $otp): void
    {
        $result = DB::connection('master')->transaction(fn () => $this->check($mobileNumber, $purpose, $otp));

        match ($result['outcome']) {
            'ok' => null,
            'expired' => throw new ApiException('OTP expired. Please request a new OTP.'),
            'wrong' => throw new ApiException('Incorrect OTP', 422, ['attempts_left' => $result['attempts_left']]),
            'locked' => throw new ApiException(
                'OTP blocked. Please try again after 1 hour.',
                422,
                ['retry_after_seconds' => $result['retry']],
            ),
        };
    }

    /**
     * Removes records whose hour has passed. Run by the scheduler.
     */
    public function prune(): int
    {
        $cutoff = now()->subSeconds(config('pathshala.otp.lock_seconds'));

        return AppLoginAttempt::query()
            ->whereRaw('COALESCE(locked_at, expires_at) <= ?', [$cutoff])
            ->delete();
    }

    private function store(string $mobileNumber, string $purpose, string $otp): array
    {
        $attempt = $this->current($mobileNumber, $purpose);

        if ($attempt && ($attempt->locked_at || $attempt->send_count >= config('pathshala.otp.max_sends'))) {
            return ['outcome' => 'limit', 'retry' => $this->secondsUntilRemoved($attempt)];
        }

        if ($attempt && now()->lt($attempt->expires_at)) {
            return ['outcome' => 'wait', 'retry' => (int) ceil(now()->diffInSeconds($attempt->expires_at))];
        }

        $attempt ??= new AppLoginAttempt(['mobile_number' => $mobileNumber, 'purpose' => $purpose]);
        $attempt->fill([
            'otp' => Hash::make($otp),
            'wrong_attempts' => 0,
            'send_count' => $attempt->send_count + 1,
            'expires_at' => now()->addSeconds(config('pathshala.otp.ttl')),
        ])->save();

        return ['outcome' => 'sent', 'send_count' => $attempt->send_count];
    }

    private function check(string $mobileNumber, string $purpose, string $otp): array
    {
        $attempt = $this->current($mobileNumber, $purpose);

        if (! $attempt) {
            return ['outcome' => 'expired'];
        }

        if ($attempt->locked_at) {
            return ['outcome' => 'locked', 'retry' => $this->secondsUntilRemoved($attempt)];
        }

        if (now()->gte($attempt->expires_at)) {
            return ['outcome' => 'expired'];
        }

        if (Hash::check($otp, $attempt->otp)) {
            $attempt->delete();

            return ['outcome' => 'ok'];
        }

        $attempt->wrong_attempts++;
        $attemptsLeft = config('pathshala.otp.max_wrong') - $attempt->wrong_attempts;

        if ($attemptsLeft > 0) {
            $attempt->save();

            return ['outcome' => 'wrong', 'attempts_left' => $attemptsLeft];
        }

        $attempt->fill(['otp' => null, 'locked_at' => now()])->save();

        return ['outcome' => 'locked', 'retry' => $this->secondsUntilRemoved($attempt)];
    }

    /**
     * Loads the record under a row lock, applying the time-based rules first:
     * the lock starts when the last OTP expires, and the record goes after 1 hour.
     */
    private function current(string $mobileNumber, string $purpose): ?AppLoginAttempt
    {
        $attempt = AppLoginAttempt::query()
            ->where('mobile_number', $mobileNumber)
            ->where('purpose', $purpose)
            ->lockForUpdate()
            ->first();

        if (! $attempt) {
            return null;
        }

        if (now()->gte($this->removalTime($attempt))) {
            $attempt->delete();

            return null;
        }

        $lastOtpExpired = $attempt->send_count >= config('pathshala.otp.max_sends') && now()->gte($attempt->expires_at);

        if (! $attempt->locked_at && $lastOtpExpired) {
            $attempt->fill(['otp' => null, 'locked_at' => $attempt->expires_at])->save();
        }

        return $attempt;
    }

    private function removalTime(AppLoginAttempt $attempt): Carbon
    {
        return ($attempt->locked_at ?? $attempt->expires_at)->copy()->addSeconds(config('pathshala.otp.lock_seconds'));
    }

    private function secondsUntilRemoved(AppLoginAttempt $attempt): int
    {
        return (int) ceil(now()->diffInSeconds($this->removalTime($attempt)));
    }

    private function generate(): string
    {
        return (string) (config('pathshala.otp.fixed') ?: random_int(100000, 999999));
    }
}
