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

    // Wrong student passwords and PINs, per student or per mobile number.
    'login_limit' => [
        'attempts' => 5,
        'seconds' => 900,
    ],

    'activity_log_path' => env('ACTIVITY_LOG_PATH', storage_path('logs/activity')),

    'homework' => [
        'timezone' => 'Asia/Dhaka',
        'max_file_mb' => 20,
        'files_disk' => env('HW_FILES_DISK', 'public'),
        'files_url' => env('HW_FILES_URL'),
    ],

    // Guardian SMS, through the school's own gateway, found the way the web system's Sms_lib finds it.
    // Off unless SMS_ENABLED=true, so local and test runs never send a real SMS.
    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
        'web_token_url' => env('SMS_WEB_TOKEN_URL', 'http://pathshala.cloud/pathshala_2011_app/information/get_web_token_v2'),
        'isms_url' => env('SMS_ISMS_URL', 'https://isms.itlab.solutions/api'),
        'timeout' => 15,
    ],

];
