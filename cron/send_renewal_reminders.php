<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\BaseUrlService;
use App\Services\EmailService;
use App\Services\SmsService;

$pdo = db();
$intervals = [60, 30];

foreach ($intervals as $days) {
    $stmt = $pdo->prepare('SELECT mp.*, m.email, m.phone, m.first_name, m.member_type FROM membership_periods mp JOIN members m ON m.id = mp.member_id WHERE mp.status = "ACTIVE" AND mp.end_date = DATE_ADD(CURDATE(), INTERVAL :days DAY) AND NOT EXISTS (SELECT 1 FROM membership_periods mp2 WHERE mp2.member_id = mp.member_id AND mp2.status = "ACTIVE" AND mp2.end_date > mp.end_date)');
    $stmt->execute(['days' => $days]);
    $periods = $stmt->fetchAll();

    foreach ($periods as $period) {
        if ($period['member_type'] === 'LIFE') {
            continue;
        }
        $check = $pdo->prepare('SELECT id FROM renewal_reminders WHERE period_id = :period_id AND reminder_type = :type');
        $check->execute(['period_id' => $period['id'], 'type' => (string) $days]);
        if ($check->fetch()) {
            continue;
        }
        // Just remind and link to the member's billing page. The renewal
        // period (and Stripe checkout) is created when the member actually
        // pays there — pre-creating a PENDING_PAYMENT period here made every
        // current member look like they already owed for next year.
        $paymentLink = BaseUrlService::emailLink('/member/index.php?page=billing');

        $subject = 'Membership renewal reminder';
        $body = '<p>Hi ' . e($period['first_name']) . ',</p>'
            . '<p>Your membership expires on ' . e($period['end_date']) . '. Please renew to stay active.</p>'
            . '<p><a href="' . e($paymentLink) . '">Renew now</a></p>';
        EmailService::send($period['email'], $subject, $body);
        if ($period['phone']) {
            SmsService::send($period['phone'], 'Membership expires on ' . $period['end_date'] . '. Renew: ' . $paymentLink);
        }
        $insert = $pdo->prepare('INSERT INTO renewal_reminders (member_id, period_id, reminder_type, sent_at) VALUES (:member_id, :period_id, :type, NOW())');
        $insert->execute([
            'member_id' => $period['member_id'],
            'period_id' => $period['id'],
            'type' => (string) $days,
        ]);
    }
}

$stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_renewal_reminder_run', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()");
$stmt->execute();
