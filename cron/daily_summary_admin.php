<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\EmailService;

$pdo = db();

$admin = $pdo->query("SELECT email, name FROM users WHERE id IN (SELECT user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = 'admin') ORDER BY id ASC LIMIT 1")->fetch();
if (!$admin) {
    exit;
}

$active = $pdo->query("SELECT COUNT(*) as c FROM members WHERE status = 'ACTIVE'")->fetch()['c'] ?? 0;
$pending = $pdo->query("SELECT COUNT(*) as c FROM membership_applications WHERE status = 'PENDING'")->fetch()['c'] ?? 0;
$dueSoon = $pdo->query("SELECT COUNT(*) as c FROM membership_periods WHERE end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetch()['c'] ?? 0;

$body = '<p>Daily summary:</p>'
    . '<ul>'
    . '<li>Active members: ' . e((string) $active) . '</li>'
    . '<li>Pending approvals: ' . e((string) $pending) . '</li>'
    . '<li>Members due soon: ' . e((string) $dueSoon) . '</li>'
    . '</ul>';

EmailService::send($admin['email'], 'Goldwing Admin Daily Summary', $body);

$stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (\"last_daily_summary_run\", NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()');
$stmt->execute();
