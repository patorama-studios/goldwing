<?php
namespace App\Services;

class PasswordPolicyService
{
    private const COMMON = [
        'password',
        'password123',
        '123456789012',
        'qwertyuiop',
        'letmein123',
        'welcome123',
        'goldwing123',
    ];

    public static function validate(string $password): array
    {
        $minLength = (int) SettingsService::getGlobal('security.password_min_length', 12);
        $minLength = max(12, $minLength);
        $errors = [];
        if (strlen($password) < $minLength) {
            $errors[] = 'Password must be at least ' . $minLength . ' characters.';
        }
        $lower = strtolower($password);
        foreach (self::COMMON as $common) {
            if ($lower === $common) {
                $errors[] = 'Password is too common.';
                break;
            }
        }
        return $errors;
    }
}
