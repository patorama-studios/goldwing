<?php
namespace App\Services;

class SmsService
{
    public static function send(string $to, string $message): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO sms_log (recipient, message, created_at) VALUES (:recipient, :message, NOW())');
        $stmt->execute([
            'recipient' => $to,
            'message' => $message,
        ]);
        return true;
    }
}
