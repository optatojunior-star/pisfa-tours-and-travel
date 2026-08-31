<?php

namespace App\Support;

final class PublicMediaUrl
{
    public static function isSafe(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            return false;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && mb_strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }
}
