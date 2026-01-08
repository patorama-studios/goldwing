<?php
namespace App\Services;

class EmailPreferencesTokenService
{
    private const VALID_PURPOSES = ['preferences', 'unsubscribe'];
    private const DEFAULT_EXPIRY_SECONDS = 7 * 24 * 60 * 60; // 7 days

    public static function createToken(int $memberId, string $purpose, int $expiresIn = self::DEFAULT_EXPIRY_SECONDS): ?string
    {
        if ($memberId <= 0 || !in_array($purpose, self::VALID_PURPOSES, true)) {
            return null;
        }
        $payload = [
            'member_id' => $memberId,
            'purpose' => $purpose,
            'expires_at' => time() + max(60, $expiresIn),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }
        return CryptoService::encrypt($json);
    }

    public static function validateToken(string $token, ?string $expectedPurpose = null): ?array
    {
        if ($token === '') {
            return null;
        }
        $decoded = CryptoService::decrypt($token);
        if ($decoded === '') {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }
        if (!isset($data['member_id']) || !isset($data['purpose']) || !isset($data['expires_at'])) {
            return null;
        }
        if (!in_array($data['purpose'], self::VALID_PURPOSES, true)) {
            return null;
        }
        if ($expectedPurpose !== null && $data['purpose'] !== $expectedPurpose) {
            return null;
        }
        if ((int) ($data['expires_at'] ?? 0) < time()) {
            return null;
        }
        $data['member_id'] = (int) $data['member_id'];
        return $data;
    }
}
