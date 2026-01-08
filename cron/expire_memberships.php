<?php
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

$stmt = $pdo->prepare('SELECT id, member_id FROM membership_periods WHERE status = "ACTIVE" AND end_date < CURDATE()');
$stmt->execute();
$periods = $stmt->fetchAll();

foreach ($periods as $period) {
    $update = $pdo->prepare('UPDATE membership_periods SET status = "LAPSED" WHERE id = :id');
    $update->execute(['id' => $period['id']]);

    $updateMember = $pdo->prepare('UPDATE members SET status = "LAPSED" WHERE id = :member_id');
    $updateMember->execute(['member_id' => $period['member_id']]);
}

$stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (\"last_expire_run\", NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()');
$stmt->execute();
