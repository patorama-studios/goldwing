<?php
namespace App\Services;

class ActivityLogger
{
    public static function log(string $actorType, ?int $actorId, ?int $memberId, string $action, array $metadata = []): void
    {
        $pdo = Database::connection();
        $metadataJson = self::metadataToJson($metadata);
        $targetType = $metadata['target_type'] ?? null;
        $targetId = $metadata['target_id'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO activity_log (actor_type, actor_id, member_id, user_id, action, target_type, target_id, metadata, ip_address, user_agent, created_at) VALUES (:actor_type, :actor_id, :member_id, :user_id, :action, :target_type, :target_id, :metadata, :ip, :user_agent, NOW())');
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'member_id' => $memberId,
            'user_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadataJson,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private static function metadataToJson(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }
}
