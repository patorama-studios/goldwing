<?php
namespace App\Services;

use SessionHandlerInterface;

class DbSessionHandler implements SessionHandlerInterface
{
    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT data FROM sessions WHERE id = :id AND expires_at > NOW() LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row['data'] ?? '';
    }

    public function write($id, $data): bool
    {
        $pdo = Database::connection();
        $ttl = (int) ini_get('session.gc_maxlifetime');
        $userId = $_SESSION['user']['id'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO sessions (id, user_id, data, ip_address, user_agent, last_activity_at, created_at, expires_at) VALUES (:id, :user_id, :data, :ip, :user_agent, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND)) ON DUPLICATE KEY UPDATE user_id = :update_user_id, data = :update_data, ip_address = :update_ip, user_agent = :update_user_agent, last_activity_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL :update_ttl SECOND)');
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':data', $data);
        $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null);
        $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
        $stmt->bindValue(':ttl', $ttl, \PDO::PARAM_INT);
        $stmt->bindValue(':update_user_id', $userId);
        $stmt->bindValue(':update_data', $data);
        $stmt->bindValue(':update_ip', $_SERVER['REMOTE_ADDR'] ?? null);
        $stmt->bindValue(':update_user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
        $stmt->bindValue(':update_ttl', $ttl, \PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function destroy($id): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function gc($max_lifetime): int|false
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
