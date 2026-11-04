<?php

$mysql = [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
];

return [

    'default' => env('DB_CONNECTION', 'master'),

    'connections' => [

        'master' => $mysql + [
            'database' => env('DB_DATABASE', 'db_school_api_web'),
        ],

        // Pointed at a school's database by SchoolDbService.
        'tenant' => $mysql + [
            'database' => null,
        ],

        // The same school, used only for writing homework and quizzes. See SchoolDbService.
        'tenant_homework' => $mysql + [
            'database' => null,
        ],

        // The same school, used only for writing profile correction requests. See SchoolDbService.
        'tenant_profile' => $mysql + [
            'database' => null,
        ],

        // The same school, used only for writing the teacher's result comment. See SchoolDbService.
        'tenant_result' => $mysql + [
            'database' => null,
        ],

        // The same school, used only for writing app notices and read marks. See SchoolDbService.
        'tenant_notice' => $mysql + [
            'database' => null,
        ],

        // The same school, used only for writing the class attendance register. See SchoolDbService.
        'tenant_attendance' => $mysql + [
            'database' => null,
        ],

        // The same school, used only for writing behaviour entries and incidents. See SchoolDbService.
        'tenant_behaviour' => $mysql + [
            'database' => null,
        ],

    ],

    // db_school_api_web already has an unrelated `migrations` table, so this
    // project keeps its own.
    'migrations' => [
        'table' => 'app_migrations',
        'update_date_on_publish' => true,
    ],

];
