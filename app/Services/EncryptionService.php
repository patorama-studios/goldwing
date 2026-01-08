<?php
namespace App\Services;

class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        if ($key === null) {
            throw new \RuntimeException('APP_KEY is not configured.');
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): ?string
    {
        $key = self::getKey();
        if ($key === null) {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) <= self::IV_LENGTH + self::TAG_LENGTH) {
            return null;
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            return null;
        }

        return $plaintext;
    }

    public static function isReady(): bool
    {
        return self::getKey() !== null;
    }

    private static function getKey(): ?string
    {
        $raw = (string) config('app_key', '');
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            $raw = hex2bin($raw);
        }

        if (!is_string($raw) || strlen($raw) < 32) {
            return null;
        }

        return substr($raw, 0, 32);
    }
}
