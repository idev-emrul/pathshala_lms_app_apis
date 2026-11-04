<?php

namespace App\Services;

use App\Models\AppUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * App users' activity, kept in one file per month instead of a database table.
 * Never pass PINs, OTPs, passwords, tokens, db_name or a student's work in $details or $message.
 */
class ActivityLogService
{
    public function record(AppUser $user, string $action, array $details = [], ?string $message = null): void
    {
        $path = $this->path(now());

        File::ensureDirectoryExists(dirname($path));

        file_put_contents($path, json_encode([
            'time' => now()->toDateTimeString(),
            'app_user_id' => $user->id,
            'role' => $user->role,
            'action' => $action,
            'message' => $message,
            'details' => $details,
            'ip_address' => request()->ip(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Entries for a month, oldest first. A month with no file returns an empty list.
     */
    public function read(int $year, int $month, ?int $appUserId = null): array
    {
        $path = $this->path(Carbon::create($year, $month, 1));

        if (! is_file($path)) {
            return [];
        }

        $entries = [];
        $file = fopen($path, 'r');

        while (($line = fgets($file)) !== false) {
            $entry = json_decode($line, true);

            if (is_array($entry) && ($appUserId === null || $entry['app_user_id'] === $appUserId)) {
                $entries[] = $entry;
            }
        }

        fclose($file);

        return $entries;
    }

    private function path(Carbon $date): string
    {
        return config('pathshala.activity_log_path').DIRECTORY_SEPARATOR.'activity_'.$date->format('Y_m').'.log';
    }
}
