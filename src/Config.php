<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, (string) $default);
    }
}
