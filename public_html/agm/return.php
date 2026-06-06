<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AgmEventService;
use App\Services\AgmRegistrationService;

$status = $_GET['status'] ?? 'success';
$sessionId = $_GET['session_id'] ?? '';
$regNumber = $_GET['r'] ?? '';

$event = AgmEventService::getCurrentEvent();
$registration = null;
if ($sessionId !== '') {
    $registration = AgmRegistrationService::getRegistrationByStripeSession($sessionId);
} elseif ($regNumber !== '') {
    // Best-effort lookup by registration number for bank-transfer flow.
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM agm_registrations WHERE registration_number = :n LIMIT 1');
    $stmt->execute(['n' => $regNumber]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        $registration = AgmRegistrationService::getRegistrationById($id);
    }
}

$pageTitle = match ($status) {
    'cancel' => 'Payment cancelled',
    'bank_transfer' => 'Registration received — bank transfer pending',
    default => 'Registration confirmed',
};

require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
    <div class="container" style="max-width:720px;margin:0 auto;padding:2rem 1rem;">
        <?php if ($status === 'cancel'): ?>
            <h1 style="font-size:1.75rem;">Payment cancelled</h1>
            <p>Your registration was saved but payment was not completed. <a href="/agm/register.php">Try again</a>, or contact the AGM coordinator if you need help.</p>
        <?php elseif ($status === 'bank_transfer'): ?>
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:0.75rem;padding:1.5rem;margin-bottom:1.5rem;">
                <h1 style="margin:0 0 0.5rem 0;font-size:1.5rem;">Registration received — payment pending</h1>
                <p style="margin:0;">Your registration <?php if ($registration): ?><strong><?= e($registration['registration_number']) ?></strong><?php endif; ?> has been saved. Please complete payment by bank transfer using the details below, then we'll confirm your spot.</p>
            </div>
            <?php if ($event && !empty($event['bank_transfer_instructions'])): ?>
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.5rem;">
                    <h3 style="margin-top:0;">Bank transfer details</h3>
                    <div style="white-space:pre-wrap;line-height:1.5;color:#1e293b;"><?= e($event['bank_transfer_instructions']) ?></div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.75rem;padding:1.5rem;margin-bottom:1.5rem;">
                <h1 style="margin:0 0 0.5rem 0;font-size:1.5rem;">Registration confirmed!</h1>
                <?php if ($registration): ?>
                    <p style="margin:0;">Thanks <strong><?= e($registration['attendee1_name']) ?></strong>. Your registration <strong><?= e($registration['registration_number']) ?></strong> has been confirmed and a receipt will be emailed to <strong><?= e($registration['email']) ?></strong>.</p>
                <?php else: ?>
                    <p style="margin:0;">Thanks — your payment was received. A receipt will be emailed shortly.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($event): ?>
            <div style="margin-top:1.5rem;">
                <h3>Event details</h3>
                <p style="margin:0;"><strong><?= e($event['title']) ?></strong></p>
                <p style="margin:0.25rem 0 0;color:#475569;"><?= e($event['venue_name'] ?? '') ?> · <?= e($event['venue_address'] ?? '') ?></p>
                <?php if (!empty($event['start_date'])): ?><p style="margin:0.25rem 0 0;color:#475569;"><?= e(date('j M Y', strtotime((string) $event['start_date']))) ?> – <?= e(date('j M Y', strtotime((string) ($event['end_date'] ?? $event['start_date'])))) ?></p><?php endif; ?>
                <p style="margin-top:1rem;"><a href="/agm/" style="color:#1e293b;">← Back to event details</a></p>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
