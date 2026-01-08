<?php
namespace App\Services;

class AuditService
{
    public static function log(?int $userId, string $action, string $details = ''): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (:user_id, :action, :details, :ip, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }
}
