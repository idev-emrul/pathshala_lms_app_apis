<?php

namespace App\Services\HwAssignment;

use Illuminate\Support\Carbon;

/**
 * The database stores school time. The API speaks UTC.
 */
class HomeworkTime
{
    public static function now(): string
    {
        return Carbon::now(self::zone())->toDateTimeString();
    }

    public static function today(): string
    {
        return Carbon::now(self::zone())->toDateString();
    }

    public static function toApi(?string $schoolTime): ?string
    {
        return $schoolTime ? Carbon::parse($schoolTime, self::zone())->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    public static function fromApi(string $utc): string
    {
        return Carbon::parse($utc, 'UTC')->setTimezone(self::zone())->toDateTimeString();
    }

    public static function fromDateAndMinutes(string $date, int $minutes): string
    {
        return Carbon::parse($date, self::zone())->startOfDay()->addMinutes($minutes)->toDateTimeString();
    }

    public static function isPast(string $schoolTime): bool
    {
        return Carbon::parse($schoolTime, self::zone())->isPast();
    }

    private static function zone(): string
    {
        return config('pathshala.homework.timezone');
    }
}
