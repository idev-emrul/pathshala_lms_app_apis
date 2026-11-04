<?php

namespace App\Services\Behaviour;

/**
 * How the web system's API reads text and yes/no values from a request.
 */
class BehaviourText
{
    public static function clean(mixed $value, int $length): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $length);
    }

    /**
     * Takes 12 or student-12. Returns 0 for anything else.
     */
    public static function studentId(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^(student-)?(\d+)$/i', trim((string) $value), $match) ? (int) $match[2] : 0;
    }

    public static function isTrue(mixed $value): bool
    {
        return $value === true || in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
