<?php

namespace App\Providers;

use App\Services\Otp\LogOtpDriver;
use App\Services\Otp\OtpDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Add a real SMS gateway here, then set OTP_DRIVER in .env.
        $this->app->bind(OtpDriver::class, fn () => match (config('pathshala.otp.driver')) {
            'log' => new LogOtpDriver,
            default => throw new InvalidArgumentException('Unknown OTP_DRIVER'),
        });
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('homework', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('routine', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('profile', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('result', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('notice', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('attendance', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
