<?php
namespace App\Services;

use DateTimeImmutable;

class MembershipMigrationService
{
    public static function createToken(int $memberId, ?int $actorUserId, int $expiryDays): array
    {
        $pdo = Database::connection();
        $safeDays = max(1, $expiryDays);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $safeDays . ' days')->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE membership_migration_tokens SET disabled_at = NOW(), updated_at = NOW() WHERE member_id = :member_id AND used_at IS NULL AND disabled_at IS NULL AND expires_at > NOW()');
            $stmt->execute(['member_id' => $memberId]);

            $stmt = $pdo->prepare('INSERT INTO membership_migration_tokens (member_id, token_hash, expires_at, created_by, sent_at, send_count, last_sent_at, created_at) VALUES (:member_id, :token_hash, :expires_at, :created_by, NOW(), 1, NOW(), NOW())');
            $stmt->execute([
                'member_id' => $memberId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_by' => $actorUserId,
            ]);
            $tokenId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'id' => $tokenId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public static function getByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM membership_migration_tokens WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getLatestForMember(int $memberId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM membership_migration_tokens WHERE member_id = :member_id ORDER BY created_at DESC LIMIT 1');
        $stmt->execute(['member_id' => $memberId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markUsed(int $tokenId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE membership_migration_tokens SET used_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $tokenId]);
    }

    public static function disableActiveTokens(int $memberId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE membership_migration_tokens SET disabled_at = NOW(), updated_at = NOW() WHERE member_id = :member_id AND used_at IS NULL AND disabled_at IS NULL AND expires_at > NOW()');
        $stmt->execute(['member_id' => $memberId]);
    }

    public static function setMemberDisabled(int $memberId, bool $disabled, ?int $actorUserId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE members SET manual_migration_disabled = :disabled, manual_migration_disabled_at = :disabled_at, manual_migration_disabled_by = :disabled_by WHERE id = :id');
        $stmt->execute([
            'disabled' => $disabled ? 1 : 0,
            'disabled_at' => $disabled ? date('Y-m-d H:i:s') : null,
            'disabled_by' => $disabled ? $actorUserId : null,
            'id' => $memberId,
        ]);

        if ($disabled) {
            self::disableActiveTokens($memberId);
        }
    }

    public static function isDisabledForMember(array $member): bool
    {
        return !empty($member['manual_migration_disabled']);
    }
}
