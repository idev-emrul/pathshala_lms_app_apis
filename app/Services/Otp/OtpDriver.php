<?php

namespace App\Services\Otp;

interface OtpDriver
{
    public function send(string $mobileNumber, string $otp): void;
}
