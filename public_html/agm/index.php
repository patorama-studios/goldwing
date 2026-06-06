<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AgmEventService;
use App\Services\AgmRegistrationService;

$featureEnabled = AgmEventService::isFeatureEnabled();
$event = $featureEnabled ? AgmEventService::getCurrentEvent() : null;

$pageTitle = $event ? $event['title'] : 'AGM Registration';
require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
    <div class="container" style="max-width:960px;margin:0 auto;padding:2rem 1rem;">
        <?php if (!$featureEnabled): ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:1rem;padding:2rem;text-align:center;">
                <h1 style="margin-top:0;">Annual General Meeting</h1>
                <p style="color:#7c2d12;">Details for our next AGM are being finalised by the committee. We'll publish the event, the registration form, and payment details here soon — please check back shortly.</p>
            </div>
        <?php elseif (!$event): ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:1rem;padding:2rem;text-align:center;">
                <h1 style="margin-top:0;">No AGM event is currently published</h1>
                <p style="color:#7c2d12;">Check back soon — the next Annual General Meeting details will be posted here once registration opens.</p>
            </div>
        <?php else: ?>
            <?php
            $isOpen = AgmRegistrationService::isRegistrationOpen($event);
            $pricingTier = AgmRegistrationService::computePricingTier($event);
            ?>

            <?php if (!empty($event['cover_image_path'])): ?>
                <img src="<?= e($event['cover_image_path']) ?>" alt="" style="width:100%;height:auto;border-radius:1rem;margin-bottom:1.5rem;">
            <?php endif; ?>

            <h1 style="font-size:2.25rem;font-weight:700;margin:0 0 0.5rem 0;"><?= e($event['title']) ?></h1>
            <?php if (!empty($event['subtitle'])): ?>
                <p style="font-size:1.25rem;color:#475569;margin:0 0 1.5rem 0;"><?= e($event['subtitle']) ?></p>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:2rem;">
                <div style="background:#f8fafc;border-radius:0.75rem;padding:1rem;">
                    <div style="font-size:0.75rem;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">Dates</div>
                    <div style="font-weight:600;color:#0f172a;margin-top:0.25rem;"><?php
                        $start = !empty($event['start_date']) ? date('j M', strtotime((string) $event['start_date'])) : '';
                        $end = !empty($event['end_date']) ? date('j M Y', strtotime((string) $event['end_date'])) : '';
                        echo e(trim($start . ' – ' . $end, ' – '));
                    ?></div>
                </div>
                <div style="background:#f8fafc;border-radius:0.75rem;padding:1rem;">
                    <div style="font-size:0.75rem;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">Venue</div>
                    <div style="font-weight:600;color:#0f172a;margin-top:0.25rem;"><?= e($event['venue_name'] ?? '') ?></div>
                    <div style="font-size:0.875rem;color:#475569;"><?= e($event['venue_address'] ?? '') ?></div>
                </div>
                <div style="background:#f8fafc;border-radius:0.75rem;padding:1rem;">
                    <div style="font-size:0.75rem;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">Registration closes</div>
                    <div style="font-weight:600;color:#0f172a;margin-top:0.25rem;"><?= !empty($event['registration_closes_at']) ? e(date('j M Y', strtotime((string) $event['registration_closes_at']))) : 'no deadline' ?></div>
                    <?php if ($pricingTier === 'early' && !empty($event['late_fee_starts_at'])): ?>
                        <div style="font-size:0.75rem;color:#16a34a;margin-top:0.25rem;">Early bird pricing until <?= e(date('j M', strtotime((string) $event['late_fee_starts_at']))) ?></div>
                    <?php elseif ($pricingTier === 'late'): ?>
                        <div style="font-size:0.75rem;color:#ea580c;margin-top:0.25rem;">Late pricing in effect</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOpen): ?>
                <a href="/agm/register.php" style="display:inline-block;background:#1e293b;color:#fff;padding:0.75rem 1.5rem;border-radius:0.5rem;font-weight:600;text-decoration:none;margin-bottom:2rem;">Register now</a>
            <?php else: ?>
                <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:0.5rem;padding:1rem;margin-bottom:2rem;">
                    <strong>Registration is not currently open.</strong>
                    <?php if (!empty($event['registration_opens_at']) && strtotime((string) $event['registration_opens_at']) > time()): ?>
                        Opens <?= e(date('j M Y, g:ia', strtotime((string) $event['registration_opens_at']))) ?>.
                    <?php elseif (!empty($event['registration_closes_at']) && strtotime((string) $event['registration_closes_at']) < time()): ?>
                        Registration closed on <?= e(date('j M Y', strtotime((string) $event['registration_closes_at']))) ?>.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($event['description_html'])): ?>
                <div style="line-height:1.6;color:#1e293b;">
                    <?= $event['description_html'] /* trusted: admin-authored via WYSIWYG */ ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($event['contact_name']) || !empty($event['contact_phone']) || !empty($event['contact_email'])): ?>
                <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid #e2e8f0;">
                    <h3 style="margin:0 0 0.5rem 0;">Questions?</h3>
                    <p style="margin:0;color:#475569;">
                        Contact <?= e($event['contact_name'] ?? 'the AGM coordinator') ?>
                        <?php if (!empty($event['contact_phone'])): ?> on <?= e($event['contact_phone']) ?><?php endif; ?>
                        <?php if (!empty($event['contact_email'])): ?> or email <a href="mailto:<?= e($event['contact_email']) ?>"><?= e($event['contact_email']) ?></a><?php endif; ?>.
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
