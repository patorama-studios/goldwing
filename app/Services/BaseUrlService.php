<?php
namespace App\Services;

class BaseUrlService
{
    private const DISALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1'];
    private static ?string $configuredBase = null;
    private static ?string $emailBase = null;
    private static ?string $validationError = null;

    public static function configuredBaseUrl(): string
    {
        if (self::$configuredBase !== null) {
            return self::$configuredBase;
        }
        $value = trim((string) SettingsService::getGlobal('site.base_url', ''));
        if ($value === '') {
            $value = trim((string) Env::get('APP_BASE_URL', ''));
        }
        self::$configuredBase = self::normalize($value);
        return self::$configuredBase;
    }

    public static function buildUrl(string $path): string
    {
        $base = self::configuredBaseUrl();
        if ($base === '' || $path === '') {
            return $base === '' ? $path : $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    public static function emailLink(string $path): string
    {
        $base = self::emailBaseUrl();
        if ($base === null || $base === '') {
            return '';
        }
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    public static function emailBaseUrl(): ?string
    {
        if (self::$emailBase !== null) {
            return self::$emailBase;
        }
        if (self::validationError() !== null) {
            return null;
        }
        $value = (string) Env::get('APP_BASE_URL', '');
        self::$emailBase = self::normalize($value);
        return self::$emailBase;
    }

    public static function validationError(): ?string
    {
        if (self::$validationError !== null) {
            return self::$validationError;
        }
        $value = (string) Env::get('APP_BASE_URL', '');
        $error = self::validateRawUrl($value, true, 'APP_BASE_URL');
        self::$validationError = $error;
        return $error;
    }

    public static function validateSettingValue(string $value): ?string
    {
        return self::validateRawUrl($value, false, 'Site base URL');
    }

    public static function normalize(string $value): string
    {
        return rtrim(trim($value), '/');
    }

    private static function validateRawUrl(string $value, bool $requireValue, string $label): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === '') {
            return $requireValue ? "$label is not configured. Set APP_BASE_URL to your public domain." : null;
        }
        if (!preg_match('#^https?://#i', $normalized)) {
            return "$label must start with http:// or https://.";
        }
        if (!filter_var($normalized, FILTER_VALIDATE_URL)) {
            return "$label is not a valid URL.";
        }
        $host = parse_url($normalized, PHP_URL_HOST);
        if (!$host) {
            return "$label is not a valid URL.";
        }
        if (self::isDisallowedHost($host)) {
            return "$label cannot use localhost, 127.0.0.1, or example domains.";
        }
        return null;
    }

    private static function isDisallowedHost(string $host): bool
    {
        $normalized = strtolower(trim($host));
        if (in_array($normalized, self::DISALLOWED_HOSTS, true)) {
            return true;
        }
        return preg_match('/(^|\\.)example\\.[a-z]+$/i', $normalized) === 1;
    }
}
