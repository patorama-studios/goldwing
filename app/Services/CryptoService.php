<?php
namespace App\Services;

class CryptoService
{
    private const CIPHER = 'AES-256-CBC';

    private static function key(): string
    {
        $key = config('app_key', '');
        if ($key === '') {
            $key = Env::get('APP_KEY', '');
        }
        return $key;
    }

    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $key = self::key();
        if ($key === '') {
            return null;
        }
        $keyBytes = hash('sha256', $key, true);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $ciphertext = openssl_encrypt($value, self::CIPHER, $keyBytes, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }
        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }
        $key = self::key();
        if ($key === '') {
            return '';
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return '';
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength);
        $keyBytes = hash('sha256', $key, true);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $keyBytes, OPENSSL_RAW_DATA, $iv);
        return $plaintext === false ? '' : $plaintext;
    }
}
