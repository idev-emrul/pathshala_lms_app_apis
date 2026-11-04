<?php

return [

    'jwt_secret' => env('JWT_SECRET'),

    'token_salt' => env('TOKEN_SALT'),

    'flow_token_ttl' => (int) env('FLOW_TOKEN_TTL', 3600),

    'otp' => [
        'driver' => env('OTP_DRIVER', 'log'),
        'fixed' => env('OTP_FIXED'),
        'ttl' => 1800,
        'max_sends' => 2,
        'max_wrong' => 2,
        'lock_seconds' => 3600,
    ],

    // Wrong passwords, PINs and guardian child checks, per person or per mobile number.
    'login_limit' => [
        'attempts' => 5,
        'seconds' => 900,
    ],

    'guardian' => [
        'max_children' => 10,
    ],

    'activity_log_path' => env('ACTIVITY_LOG_PATH', storage_path('logs/activity')),

    'homework' => [
        'timezone' => 'Asia/Dhaka',
        'max_file_mb' => 20,
        'files_disk' => env('HW_FILES_DISK', 'public'),
        'files_url' => env('HW_FILES_URL'),
    ],

];
