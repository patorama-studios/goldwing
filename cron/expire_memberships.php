<?php
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = db();

// Members get a grace period after end_date before they actually lock off:
// they stay ACTIVE (full access + a warning banner) until end_date +
// GRACE_MONTHS, then this flips them to LAPSED. Reading the constant keeps the
// cron's flip aligned with the "still active" window in MembershipAccessService.
$graceMonths = (int) \App\Services\MembershipAccessService::GRACE_MONTHS;
$stmt = $pdo->prepare("SELECT id, member_id FROM membership_periods WHERE status = 'ACTIVE' AND end_date < (CURDATE() - INTERVAL $graceMonths MONTH)");
$stmt->execute();
$periods = $stmt->fetchAll();

foreach ($periods as $period) {
    $update = $pdo->prepare('UPDATE membership_periods SET status = "LAPSED" WHERE id = :id');
    $update->execute(['id' => $period['id']]);

    // "LAPSED" matches the legacy members.status convention written by
    // MemberRepository::normalizeStatus (EXPIRED -> LAPSED); the lockdown
    // reader lowercases and treats "lapsed" as locked.
    $updateMember = $pdo->prepare('UPDATE members SET status = "LAPSED" WHERE id = :member_id');
    $updateMember->execute(['member_id' => $period['member_id']]);
}

$stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_expire_run', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()");
$stmt->execute();
