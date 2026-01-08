<?php
namespace App\Services;

class TotpService
{
    public static function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }

    public static function getOtpAuthUrl(string $issuer, string $accountLabel, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        $issuerParam = rawurlencode($issuer);
        return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . $issuerParam . '&period=30&digits=6';
    }

    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\\d{6}$/', $code)) {
            return false;
        }
        $timestamp = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::generateCode($secret, $timestamp + ($i * 30)), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function generateCode(string $secret, int $timestamp): string
    {
        $counter = (int) floor($timestamp / 30);
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (ord($hash[$offset]) & 0x7f) << 24;
        $value |= (ord($hash[$offset + 1]) & 0xff) << 16;
        $value |= (ord($hash[$offset + 2]) & 0xff) << 8;
        $value |= (ord($hash[$offset + 3]) & 0xff);
        $modulo = $value % 1000000;
        return str_pad((string) $modulo, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }
        return $output;
    }

    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(preg_replace('/[^A-Z2-7]/', '', $data));
        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}
