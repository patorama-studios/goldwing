<?php
require_once __DIR__ . '/../../app/Services/MembershipService.php';
require_once __DIR__ . '/../../app/Services/NotificationService.php';

use App\Services\MembershipService;
use App\Services\NotificationService;

$failures = 0;

function expect(bool $condition, string $message, int &$failures): void
{
    if ($condition) {
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: {$message}\n");
}

$expiry = MembershipService::calculateExpiry('2024-06-15', 1);
expect($expiry === '2024-07-31', "Expected 2024-07-31 for June start, got {$expiry}", $failures);

$expiry = MembershipService::calculateExpiry('2024-09-01', 1);
expect($expiry === '2025-07-31', "Expected 2025-07-31 for Sept start, got {$expiry}", $failures);

$expiry = MembershipService::calculateExpiry('2024-09-01', 3);
expect($expiry === '2027-07-31', "Expected 2027-07-31 for 3Y Sept start, got {$expiry}", $failures);

$definitions = NotificationService::definitions();
$requiredNotifications = [
    'membership_order_created',
    'membership_payment_received',
    'membership_order_approved',
    'membership_order_rejected',
    'membership_payment_failed',
    'membership_admin_pending_approval',
];
foreach ($requiredNotifications as $key) {
    expect(array_key_exists($key, $definitions), "Missing notification definition: {$key}", $failures);
}

if ($failures === 0) {
    echo "OK: critical path checks passed.\n";
    exit(0);
}

fwrite(STDERR, "FAIL: {$failures} checks failed.\n");
exit(1);
