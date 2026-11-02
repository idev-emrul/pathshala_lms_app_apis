<?php

namespace App\Support;

class Phone
{
    /**
     * Returns the number as 01XXXXXXXXX, or null if it is not a Bangladeshi mobile number.
     */
    public static function normalize(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    public static function mask(string $number): string
    {
        return substr($number, 0, 3).'*****'.substr($number, -3);
    }
}
