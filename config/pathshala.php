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

    // The users.user_type values that can log in under each role. Any other type is refused.
    'login_user_types' => [
        'admin' => ['Admin'],
        'staff' => ['Accountant'],
    ],

    // Sent at login. Only what this API can do, because the app shows a screen for each one.
    // Staff have no routes yet, and guests get none of these.
    'permissions' => [
        'student' => ['homework.view', 'quiz.view', 'leave.view', 'early_leave.view'],
        'guardian' => ['homework.view', 'quiz.view', 'leave.view', 'early_leave.view', 'early_leave.approve'],
        'teacher' => ['homework.manage', 'quiz.manage', 'leave.review', 'early_leave.approve'],
        'admin' => ['leave.review', 'early_leave.approve'],
        'staff' => [],
    ],

    // The same for every school for now, as nothing stores this per school.
    'feature_flags' => ['homework', 'teacher_homework', 'quiz', 'leave', 'early_leave'],

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
