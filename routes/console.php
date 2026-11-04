<?php

use App\Services\ActivityLogService;
use App\Services\OtpService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('otp:prune', function (OtpService $otp) {
    $this->info('Removed '.$otp->prune().' login attempt record(s).');
})->purpose('Remove app_login_attempt records whose hour has passed');

Artisan::command('activity:read {year} {month} {--user= : Only this app_user id}', function (ActivityLogService $activity) {
    $user = $this->option('user');

    foreach ($activity->read((int) $this->argument('year'), (int) $this->argument('month'), $user ? (int) $user : null) as $entry) {
        $this->line(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
})->purpose('Read a month of the activity log');

Schedule::command('otp:prune')->everyFiveMinutes();
