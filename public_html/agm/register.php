<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AgmEventService;
use App\Services\AgmRegistrationService;
use App\Services\BaseUrlService;
use App\Services\Csrf;
use App\Services\MemberRepository;
use App\Services\StripeService;
use App\Services\StripeSettingsService;

if (!AgmEventService::isFeatureEnabled()) {
    header('Location: /agm/');
    exit;
}

$event = AgmEventService::getCurrentEvent();
if (!$event) {
    header('Location: /agm/');
    exit;
}

$isOpen = AgmRegistrationService::isRegistrationOpen($event);
$pricingTier = AgmRegistrationService::computePricingTier($event);
$products = AgmEventService::getProducts((int) $event['id'], true);
$customFields = AgmEventService::getFormFields((int) $event['id'], true);

$user = current_user();
$member = null;
if ($user && !empty($user['member_id'])) {
    $member = MemberRepository::findById((int) $user['member_id']);
}
$isMember = $member && ($member['status'] ?? '') === 'ACTIVE';

$errors = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['_token'] ?? '')) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }
    if (!$isOpen) {
        $errors[] = 'Registration is not currently open.';
    }

    $formData = $_POST;

    // Validate the always-required baseline fields.
    $required = [
        'attendee1_name' => 'Name of attendee 1',
        'email' => 'Email address',
        'contact_phone_1' => 'Contact phone',
    ];
    foreach ($required as $key => $label) {
        if (trim((string) ($formData[$key] ?? '')) === '') {
            $errors[] = $label . ' is required.';
        }
    }

    // Build the line items from posted product quantities.
    $lineItems = [];
    foreach ($products as $product) {
        $qtyKey = 'product_' . (int) $product['id'] . '_qty';
        $choiceKey = 'product_' . (int) $product['id'] . '_choice';
        $qty = (int) ($_POST[$qtyKey] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if ((int) $product['member_only'] === 1 && !$isMember) {
            $errors[] = '"' . $product['name'] . '" is only available to current AGA members.';
            continue;
        }
        if ((int) $product['non_member_only'] === 1 && $isMember) {
            $errors[] = '"' . $product['name'] . '" is for non-members only.';
            continue;
        }
        if ((int) $product['per_registration_limit'] > 0 && $qty > (int) $product['per_registration_limit']) {
            $errors[] = '"' . $product['name'] . '" is limited to ' . (int) $product['per_registration_limit'] . ' per registration.';
            continue;
        }
        $unitPrice = (float) ($pricingTier === 'late' && $product['late_price'] !== null ? $product['late_price'] : $product['early_price']);
        $choiceLabel = null;
        if ((int) $product['requires_choice'] === 1) {
            $choiceLabel = trim((string) ($_POST[$choiceKey] ?? ''));
            if ($choiceLabel === '') {
                $errors[] = 'Please pick an option for "' . $product['name'] . '".';
                continue;
            }
        }
        $lineItems[] = [
            'agm_product_id' => (int) $product['id'],
            'category' => $product['category'],
            'name' => $product['name'],
            'choice_label' => $choiceLabel,
            'unit_price' => $unitPrice,
            'quantity' => $qty,
        ];
    }

    if (!$lineItems && !$errors) {
        $errors[] = 'Please pick at least one item to register.';
    }

    $motorcycles = [];
    for ($i = 1; $i <= 2; $i++) {
        $owner = trim((string) ($_POST['moto_' . $i . '_owner'] ?? ''));
        $make = trim((string) ($_POST['moto_' . $i . '_make'] ?? ''));
        $model = trim((string) ($_POST['moto_' . $i . '_model'] ?? ''));
        $rego = trim((string) ($_POST['moto_' . $i . '_rego'] ?? ''));
        if ($owner === '' && $make === '' && $model === '' && $rego === '') {
            continue;
        }
        $motorcycles[] = [
            'owner_name' => $owner,
            'make' => $make,
            'model' => $model,
            'year_built' => $_POST['moto_' . $i . '_year'] ?? null,
            'registration_plate' => $rego,
            'is_trike' => !empty($_POST['moto_' . $i . '_trike']),
            'is_sidecar' => !empty($_POST['moto_' . $i . '_sidecar']),
            'has_trailer' => !empty($_POST['moto_' . $i . '_trailer']),
        ];
    }

    $customValues = [];
    foreach ($customFields as $field) {
        $key = 'field_' . $field['field_key'];
        $val = $_POST[$key] ?? null;
        if (is_array($val)) {
            $val = implode(', ', $val);
        }
        if ((int) $field['is_required'] === 1 && (trim((string) $val) === '')) {
            $errors[] = $field['label'] . ' is required.';
        }
        $customValues[$field['field_key']] = $val;
    }

    if (!$errors) {
        $paymentMethod = $_POST['payment_method'] ?? 'stripe';
        if ($paymentMethod === 'bank_transfer' && empty($event['allow_bank_transfer'])) {
            $paymentMethod = 'stripe';
        }
        if ($paymentMethod === 'stripe' && empty($event['allow_stripe'])) {
            $paymentMethod = 'bank_transfer';
        }

        $payload = [
            'member_id' => $member['id'] ?? null,
            'user_id' => $user['id'] ?? null,
            'attendee1_name' => trim((string) $formData['attendee1_name']),
            'attendee1_member_number' => $formData['attendee1_member_number'] ?? null,
            'attendee1_is_over_65' => !empty($formData['attendee1_is_over_65']),
            'attendee2_name' => trim((string) ($formData['attendee2_name'] ?? '')) ?: null,
            'attendee2_member_number' => $formData['attendee2_member_number'] ?? null,
            'attendee2_is_over_65' => !empty($formData['attendee2_is_over_65']),
            'children_text' => $formData['children_text'] ?? null,
            'contact_phone_1' => $formData['contact_phone_1'] ?? null,
            'contact_phone_2' => $formData['contact_phone_2'] ?? null,
            'address' => $formData['address'] ?? null,
            'postcode' => $formData['postcode'] ?? null,
            'email' => trim((string) $formData['email']),
            'chapter' => $formData['chapter'] ?? null,
            'emergency_1_name' => $formData['emergency_1_name'] ?? null,
            'emergency_1_phone' => $formData['emergency_1_phone'] ?? null,
            'emergency_2_name' => $formData['emergency_2_name'] ?? null,
            'emergency_2_phone' => $formData['emergency_2_phone'] ?? null,
            'dietary_requirements' => $formData['dietary_requirements'] ?? null,
            'custom_fields' => $customValues,
            'payment_method' => $paymentMethod,
        ];
        $context = [
            'submitted_by_user_id' => $user['id'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];

        try {
            $registration = AgmRegistrationService::createRegistration($event, $payload, $lineItems, $motorcycles, $context);

            if ($paymentMethod === 'stripe') {
                $sessionLineItems = [];
                foreach ($registration['items'] as $it) {
                    $label = $it['name_snapshot'];
                    if (!empty($it['choice_label_snapshot'])) {
                        $label .= ' — ' . $it['choice_label_snapshot'];
                    }
                    $sessionLineItems[] = [
                        'currency' => 'aud',
                        'name' => $label,
                        'unit_amount' => (int) round((float) $it['unit_price'] * 100),
                        'quantity' => (int) $it['quantity'],
                    ];
                }
                $successUrl = BaseUrlService::buildUrl('/agm/return.php?session_id={CHECKOUT_SESSION_ID}&status=success');
                $cancelUrl = BaseUrlService::buildUrl('/agm/return.php?status=cancel');
                $session = StripeService::createCheckoutSessionWithLineItems(
                    $sessionLineItems,
                    $registration['email'],
                    $successUrl,
                    $cancelUrl,
                    [
                        'agm_registration_id' => (string) $registration['id'],
                        'agm_event_id' => (string) $event['id'],
                        'registration_number' => $registration['registration_number'],
                    ],
                    StripeSettingsService::ACCOUNT_AGM
                );

                if (!$session || empty($session['url'])) {
                    $errors[] = 'Could not create the Stripe checkout session. Please try the bank-transfer option, or contact the AGM coordinator.';
                } else {
                    AgmRegistrationService::attachStripeSession((int) $registration['id'], (string) $session['id']);
                    header('Location: ' . $session['url']);
                    exit;
                }
            } else {
                // Bank transfer — registration is saved with awaiting_bank_transfer status.
                header('Location: /agm/return.php?status=bank_transfer&r=' . urlencode($registration['registration_number']));
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not save your registration: ' . $e->getMessage();
        }
    }
}

// Pre-fill from member when GET (or POST with errors).
$prefill = function (string $key) use ($formData, $member, $user): string {
    if (array_key_exists($key, $formData)) {
        return (string) $formData[$key];
    }
    $memberMap = [
        'attendee1_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
        'attendee1_member_number' => $member['member_number'] ?? (isset($member['member_number_base']) ? (string) $member['member_number_base'] . ($member['member_number_suffix'] ?? '') : ''),
        'email' => $user['email'] ?? $member['email'] ?? '',
        'contact_phone_1' => $member['phone'] ?? '',
        'address' => trim(($member['address_line1'] ?? '') . ' ' . ($member['address_line2'] ?? '') . ', ' . ($member['city'] ?? '') . ' ' . ($member['state'] ?? ''), ' ,'),
        'postcode' => $member['postal_code'] ?? '',
    ];
    return (string) ($memberMap[$key] ?? '');
};

$pageTitle = 'Register for ' . $event['title'];
require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
    <div class="container" style="max-width:880px;margin:0 auto;padding:2rem 1rem;">
        <a href="/agm/" style="color:#64748b;text-decoration:none;font-size:0.875rem;">← Back to event details</a>
        <h1 style="font-size:2rem;font-weight:700;margin:0.5rem 0;">Register for <?= e($event['title']) ?></h1>
        <p style="color:#475569;margin:0 0 1.5rem 0;">Pricing tier: <strong><?= e(ucfirst($pricingTier)) ?></strong><?php if ($pricingTier === 'early' && !empty($event['late_fee_starts_at'])): ?> — late pricing begins <?= e(date('j M Y', strtotime((string) $event['late_fee_starts_at']))) ?><?php endif; ?></p>

        <?php if (!$isOpen): ?>
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:0.5rem;padding:1rem;">
                <strong>Registration is not currently open.</strong>
            </div>
            <?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
            <?php exit; ?>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:0.5rem;padding:1rem;margin-bottom:1rem;">
                <strong style="color:#991b1b;">Please fix the following:</strong>
                <ul style="margin:0.5rem 0 0 1rem;color:#7f1d1d;"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($member): ?>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1.5rem;font-size:0.875rem;color:#065f46;">
                Logged in as <strong><?= e(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?></strong>. We've pre-filled your details below — please review and update if anything has changed.
            </div>
        <?php endif; ?>

        <form method="post" style="display:flex;flex-direction:column;gap:1.5rem;">
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">

            <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                <legend style="font-weight:600;padding:0 0.5rem;">Personal details</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                    <label style="display:block;">Name (attendee 1) *<br><input type="text" name="attendee1_name" required value="<?= e($prefill('attendee1_name')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Member # (attendee 1)<br><input type="text" name="attendee1_member_number" value="<?= e($prefill('attendee1_member_number')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" name="attendee1_is_over_65" value="1" <?= !empty($formData['attendee1_is_over_65']) ? 'checked' : '' ?>> Attendee 1 is 65 or over</label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem;">
                    <label style="display:block;">Name (attendee 2)<br><input type="text" name="attendee2_name" value="<?= e($formData['attendee2_name'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Member # (attendee 2)<br><input type="text" name="attendee2_member_number" value="<?= e($formData['attendee2_member_number'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:flex;align-items:center;gap:0.5rem;"><input type="checkbox" name="attendee2_is_over_65" value="1" <?= !empty($formData['attendee2_is_over_65']) ? 'checked' : '' ?>> Attendee 2 is 65 or over</label>
                </div>
                <label style="display:block;margin-top:1rem;">Children under 18 (name and age, one per line)<br><textarea name="children_text" rows="2" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"><?= e($formData['children_text'] ?? '') ?></textarea></label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem;">
                    <label style="display:block;">Contact phone 1 *<br><input type="text" name="contact_phone_1" required value="<?= e($prefill('contact_phone_1')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Contact phone 2<br><input type="text" name="contact_phone_2" value="<?= e($formData['contact_phone_2'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Email *<br><input type="email" name="email" required value="<?= e($prefill('email')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Chapter<br><input type="text" name="chapter" value="<?= e($formData['chapter'] ?? ($member['chapter_name'] ?? '')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                </div>
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-top:1rem;">
                    <label style="display:block;">Address<br><input type="text" name="address" value="<?= e($prefill('address')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Postcode<br><input type="text" name="postcode" value="<?= e($prefill('postcode')) ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                <legend style="font-weight:600;padding:0 0.5rem;">Motorcycle / trike / sidecar details</legend>
                <p style="font-size:0.875rem;color:#64748b;margin:0 0 0.75rem 0;">You must register your bike to be eligible for Show 'n' Shine. Up to two bikes per registration; leave blank if not applicable.</p>
                <?php for ($i = 1; $i <= 2; $i++): ?>
                    <div style="border-top:<?= $i === 1 ? '0' : '1px solid #e2e8f0' ?>;padding-top:<?= $i === 1 ? '0' : '0.75rem' ?>;margin-top:<?= $i === 1 ? '0' : '0.75rem' ?>;">
                        <strong style="display:block;margin-bottom:0.5rem;">Motorcycle <?= $i ?></strong>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;">
                            <label style="display:block;">Owner<br><input type="text" name="moto_<?= $i ?>_owner" value="<?= e($formData['moto_' . $i . '_owner'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                            <label style="display:block;">Make<br><input type="text" name="moto_<?= $i ?>_make" value="<?= e($formData['moto_' . $i . '_make'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                            <label style="display:block;">Model<br><input type="text" name="moto_<?= $i ?>_model" value="<?= e($formData['moto_' . $i . '_model'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                            <label style="display:block;">Year<br><input type="number" name="moto_<?= $i ?>_year" min="1950" max="2099" value="<?= e($formData['moto_' . $i . '_year'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                            <label style="display:block;">Rego<br><input type="text" name="moto_<?= $i ?>_rego" value="<?= e($formData['moto_' . $i . '_rego'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-top:0.5rem;font-size:0.875rem;">
                            <label><input type="checkbox" name="moto_<?= $i ?>_trike" value="1" <?= !empty($formData['moto_' . $i . '_trike']) ? 'checked' : '' ?>> Trike</label>
                            <label><input type="checkbox" name="moto_<?= $i ?>_sidecar" value="1" <?= !empty($formData['moto_' . $i . '_sidecar']) ? 'checked' : '' ?>> Sidecar</label>
                            <label><input type="checkbox" name="moto_<?= $i ?>_trailer" value="1" <?= !empty($formData['moto_' . $i . '_trailer']) ? 'checked' : '' ?>> Trailer</label>
                        </div>
                    </div>
                <?php endfor; ?>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                <legend style="font-weight:600;padding:0 0.5rem;">External emergency contacts</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                    <label style="display:block;">Name 1<br><input type="text" name="emergency_1_name" value="<?= e($formData['emergency_1_name'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Phone 1<br><input type="text" name="emergency_1_phone" value="<?= e($formData['emergency_1_phone'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Name 2<br><input type="text" name="emergency_2_name" value="<?= e($formData['emergency_2_name'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                    <label style="display:block;">Phone 2<br><input type="text" name="emergency_2_phone" value="<?= e($formData['emergency_2_phone'] ?? '') ?>" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"></label>
                </div>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                <legend style="font-weight:600;padding:0 0.5rem;">Items &amp; pricing</legend>
                <?php
                $groups = ['registration' => 'Registration', 'merchandise' => 'Merchandise', 'meal' => 'Additional meals', 'custom' => 'Other'];
                $grouped = ['registration' => [], 'merchandise' => [], 'meal' => [], 'custom' => []];
                foreach ($products as $p) {
                    $grouped[$p['category']][] = $p;
                }
                ?>
                <?php foreach ($groups as $catKey => $catLabel): ?>
                    <?php if (!$grouped[$catKey]) continue; ?>
                    <h4 style="margin:1rem 0 0.5rem 0;font-size:1rem;color:#0f172a;"><?= e($catLabel) ?></h4>
                    <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                        <thead>
                            <tr style="border-bottom:1px solid #e2e8f0;color:#64748b;">
                                <th style="text-align:left;padding:0.5rem 0;">Item</th>
                                <th style="text-align:right;padding:0.5rem 0;">Early</th>
                                <th style="text-align:right;padding:0.5rem 0;">Late</th>
                                <th style="text-align:right;padding:0.5rem 0;width:80px;">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped[$catKey] as $p): ?>
                                <?php
                                    $disabled = ((int) $p['member_only'] === 1 && !$isMember) || ((int) $p['non_member_only'] === 1 && $isMember);
                                    $qtyVal = (int) ($formData['product_' . $p['id'] . '_qty'] ?? 0);
                                ?>
                                <tr style="border-bottom:1px solid #f1f5f9;<?= $disabled ? 'opacity:0.5;' : '' ?>">
                                    <td style="padding:0.5rem 0;">
                                        <?= e($p['name']) ?>
                                        <?php if (!empty($p['description'])): ?><div style="font-size:0.75rem;color:#64748b;"><?= e($p['description']) ?></div><?php endif; ?>
                                        <?php if ((int) $p['member_only']): ?><span style="display:inline-block;background:#dbeafe;color:#1e40af;font-size:0.6875rem;padding:0.125rem 0.375rem;border-radius:0.25rem;margin-left:0.25rem;">members only</span><?php endif; ?>
                                        <?php if ((int) $p['non_member_only']): ?><span style="display:inline-block;background:#f3e8ff;color:#6b21a8;font-size:0.6875rem;padding:0.125rem 0.375rem;border-radius:0.25rem;margin-left:0.25rem;">non-members</span><?php endif; ?>
                                        <?php if ((int) $p['requires_choice'] && !empty($p['choices_json'])): ?>
                                            <?php $choices = json_decode($p['choices_json'], true) ?: []; ?>
                                            <select name="product_<?= (int) $p['id'] ?>_choice" style="display:block;margin-top:0.25rem;padding:0.25rem;border:1px solid #cbd5e1;border-radius:0.25rem;font-size:0.8125rem;" <?= $disabled ? 'disabled' : '' ?>>
                                                <option value="">— pick one —</option>
                                                <?php foreach ($choices as $c): ?>
                                                    <option value="<?= e($c) ?>" <?= ($formData['product_' . $p['id'] . '_choice'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;padding:0.5rem 0;<?= $pricingTier === 'early' ? 'font-weight:600;' : 'color:#94a3b8;text-decoration:line-through;' ?>">A$<?= number_format((float) $p['early_price'], 2) ?></td>
                                    <td style="text-align:right;padding:0.5rem 0;<?= $pricingTier === 'late' ? 'font-weight:600;' : 'color:#94a3b8;' ?>"><?= $p['late_price'] !== null ? 'A$' . number_format((float) $p['late_price'], 2) : '—' ?></td>
                                    <td style="text-align:right;padding:0.5rem 0;">
                                        <input type="number" name="product_<?= (int) $p['id'] ?>_qty" min="0" max="<?= (int) ($p['per_registration_limit'] ?: 99) ?>" value="<?= $qtyVal ?>" style="width:60px;text-align:right;padding:0.25rem;border:1px solid #cbd5e1;border-radius:0.25rem;" <?= $disabled ? 'disabled' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </fieldset>

            <?php if ($customFields): ?>
                <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                    <legend style="font-weight:600;padding:0 0.5rem;">Other questions</legend>
                    <div style="display:flex;flex-direction:column;gap:1rem;">
                        <?php foreach ($customFields as $field): ?>
                            <?php $name = 'field_' . $field['field_key']; $val = (string) ($formData[$name] ?? ''); ?>
                            <label style="display:block;">
                                <span style="font-weight:500;"><?= e($field['label']) ?><?= (int) $field['is_required'] ? ' *' : '' ?></span>
                                <?php if (!empty($field['helper_text'])): ?><span style="display:block;font-size:0.75rem;color:#64748b;"><?= e($field['helper_text']) ?></span><?php endif; ?>
                                <?php if ($field['field_type'] === 'textarea'): ?>
                                    <textarea name="<?= e($name) ?>" rows="3" <?= (int) $field['is_required'] ? 'required' : '' ?> style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"><?= e($val) ?></textarea>
                                <?php elseif ($field['field_type'] === 'checkbox'): ?>
                                    <input type="checkbox" name="<?= e($name) ?>" value="1" <?= $val ? 'checked' : '' ?>>
                                <?php elseif ($field['field_type'] === 'select'): $opts = json_decode($field['options_json'] ?? '[]', true) ?: []; ?>
                                    <select name="<?= e($name) ?>" <?= (int) $field['is_required'] ? 'required' : '' ?> style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;">
                                        <option value="">— pick —</option>
                                        <?php foreach ($opts as $opt): ?><option value="<?= e($opt) ?>" <?= $val === $opt ? 'selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="<?= $field['field_type'] === 'number' ? 'number' : 'text' ?>" name="<?= e($name) ?>" value="<?= e($val) ?>" <?= (int) $field['is_required'] ? 'required' : '' ?> style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;">
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endif; ?>

            <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                <legend style="font-weight:600;padding:0 0.5rem;">Dietary requirements</legend>
                <textarea name="dietary_requirements" rows="2" placeholder="Any dietary requirements for any attendee?" style="width:100%;padding:0.5rem;border:1px solid #cbd5e1;border-radius:0.375rem;"><?= e($formData['dietary_requirements'] ?? '') ?></textarea>
            </fieldset>

            <fieldset style="border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
                <legend style="font-weight:600;padding:0 0.5rem;">Payment method</legend>
                <div style="display:flex;flex-direction:column;gap:0.75rem;">
                    <?php if (!empty($event['allow_bank_transfer'])): ?>
                        <label style="display:flex;align-items:flex-start;gap:0.5rem;cursor:pointer;">
                            <input type="radio" name="payment_method" value="bank_transfer" <?= ($formData['payment_method'] ?? '') === 'bank_transfer' ? 'checked' : '' ?>>
                            <span>
                                <strong>Direct deposit / bank transfer <span style="color:#15803d;">(no fees)</span></strong>
                                <span style="display:block;font-size:0.875rem;color:#64748b;">Submit your registration now and we'll confirm once payment lands. Bank details will be shown after you submit.</span>
                            </span>
                        </label>
                    <?php endif; ?>
                    <?php if (!empty($event['allow_stripe'])): ?>
                        <label style="display:flex;align-items:flex-start;gap:0.5rem;cursor:pointer;">
                            <input type="radio" name="payment_method" value="stripe" <?= ($formData['payment_method'] ?? 'stripe') === 'stripe' ? 'checked' : '' ?>>
                            <span>
                                <strong>Pay now by card (Stripe)</strong>
                                <span style="display:block;font-size:0.875rem;color:#64748b;">You'll be redirected to Stripe Checkout to complete payment securely. Your card details never touch our servers — they go straight to Stripe.</span>
                            </span>
                        </label>
                        <div style="margin-left:1.5rem;">
                          <?php
                            $_secBlock = __DIR__ . '/../../app/Views/partials/stripe_security_block.php';
                            if (file_exists($_secBlock)) { require $_secBlock; }
                          ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php require __DIR__ . '/../../app/Views/partials/payment_refund_notice.php'; ?>
            </fieldset>

            <div style="display:flex;justify-content:flex-end;">
                <button type="submit" style="background:#1e293b;color:#fff;padding:0.75rem 2rem;border:0;border-radius:0.5rem;font-weight:600;cursor:pointer;font-size:1rem;">Submit registration</button>
            </div>
        </form>
    </div>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
