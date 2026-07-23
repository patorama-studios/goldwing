<?php
/**
 * Cron: recover stranded new-member applications.
 *
 * The /apply.php card flow charges the join fee BEFORE it writes the applicant
 * to the DB (the write is a browser AJAX POST after payment). If that POST never
 * completes — an issuer/3DS redirect that reloads and wipes the form, a closed
 * tab, a dropped connection — the money is captured but nothing is recorded, and
 * the invoice.paid webhook deliberately no-ops for membership_application.
 *
 * This scans Stripe for paid membership_application invoices with no matching
 * DB member/order and rebuilds the PENDING member + application from the invoice
 * metadata, then notifies the committee. It only touches invoices paid more than
 * a short grace window ago, so it never races the normal browser POST, and it is
 * idempotent + deduped, so re-runs and double payments are safe.
 *
 * Schedule (cPanel → Cron Jobs), e.g. every 15 minutes:
 *   *\/15 * * * * /usr/bin/php /home2/goldwing/.../cron/recover_stranded_applications.php >> /home2/goldwing/.../app/storage/logs/cron_recover_applications.log 2>&1
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\MembershipApplicationRecoveryService;

$startedAt = date('c');
$recovered = 0;
$skipped = 0;
$failed = 0;

try {
    $orphans = MembershipApplicationRecoveryService::findOrphans();
} catch (\Throwable $e) {
    fwrite(STDERR, "[$startedAt] recover_stranded_applications: scan failed — " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($orphans as $orphan) {
    $res = MembershipApplicationRecoveryService::recoverInvoice($orphan['invoice']);
    if (!empty($res['ok'])) {
        $recovered++;
        echo "[$startedAt] recovered {$orphan['invoice_id']} → member #{$res['member_id']} ({$orphan['email']})\n";
    } elseif (!empty($res['duplicate'])) {
        $skipped++;
        echo "[$startedAt] skipped {$orphan['invoice_id']} — {$res['msg']}\n";
    } else {
        $failed++;
        fwrite(STDERR, "[$startedAt] FAILED {$orphan['invoice_id']} — {$res['msg']}\n");
    }
}

echo "[$startedAt] recover_stranded_applications: {$recovered} recovered, {$skipped} skipped, {$failed} failed, " . count($orphans) . " scanned.\n";
