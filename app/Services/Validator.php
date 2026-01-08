<?php
namespace App\Services;

class Validator
{
    public static function required(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    public static function email(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    public static function length(?string $value, int $min, int $max): bool
    {
        if ($value === null) {
            return false;
        }
        $len = strlen(trim($value));
        return $len >= $min && $len <= $max;
    }
}
