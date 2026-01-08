<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\BaseUrlService;
use App\Services\EmailService;
use App\Services\MembershipService;
use App\Services\SmsService;
use App\Services\StripeService;

$pdo = db();
$intervals = [60, 30];

foreach ($intervals as $days) {
    $stmt = $pdo->prepare('SELECT mp.*, m.email, m.phone, m.first_name, m.member_type FROM membership_periods mp JOIN members m ON m.id = mp.member_id WHERE mp.status = "ACTIVE" AND mp.end_date = DATE_ADD(CURDATE(), INTERVAL :days DAY)');
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
        $nextStart = date('Y-m-d', strtotime($period['end_date'] . ' +1 day'));
        $existing = $pdo->prepare('SELECT id FROM membership_periods WHERE member_id = :member_id AND start_date = :start_date AND status = \"PENDING_PAYMENT\"');
        $existing->execute(['member_id' => $period['member_id'], 'start_date' => $nextStart]);
        $nextPeriod = $existing->fetch();
        $nextPeriodId = $nextPeriod['id'] ?? MembershipService::createMembershipPeriod((int) $period['member_id'], '1Y', $nextStart);

        $priceKey = $period['member_type'] . '_1Y';
        $priceId = config('stripe.membership_prices.' . $priceKey, '');
        $session = null;
        if ($priceId) {
            $session = StripeService::createCheckoutSession($priceId, $period['email'], [
                'period_id' => $nextPeriodId,
                'member_id' => $period['member_id'],
            ]);
        }
        $paymentLink = $session['url'] ?? BaseUrlService::emailLink('/member/index.php?page=billing');

        $subject = 'Membership renewal reminder';
        $body = '<p>Hi ' . e($period['first_name']) . ',</p>'
            . '<p>Your membership expires on ' . e($period['end_date']) . '. Please renew to stay active.</p>'
            . '<p><a href=\"' . e($paymentLink) . '\">Renew now</a></p>';
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

$stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (\"last_renewal_reminder_run\", NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()');
$stmt->execute();
