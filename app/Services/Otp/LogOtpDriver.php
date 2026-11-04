<?php

namespace App\Services\Otp;

use App\Support\Phone;
use Illuminate\Support\Facades\Log;

/**
 * Used until an SMS gateway is added. The OTP itself is only logged in debug mode.
 */
class LogOtpDriver implements OtpDriver
{
    public function send(string $mobileNumber, string $otp): void
    {
        Log::info('OTP sent to '.Phone::mask($mobileNumber).(config('app.debug') ? ": {$otp}" : ''));
    }
}
