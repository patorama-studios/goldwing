<?php

use App\Services\EmailService;
use App\Services\SettingsService;

function store_require_admin(): void
{
    require_login();
    $user = current_user();
    $roles = $user['roles'] ?? [];
    $allowed = ['admin', 'super_admin', 'store_manager'];
    foreach ($allowed as $role) {
        if (in_array($role, $roles, true)) {
            return;
        }
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function store_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : bin2hex(random_bytes(3));
}

function store_unique_slug(string $table, string $slug, int $excludeId = 0): string
{
    $allowed = ['store_categories', 'store_tags', 'store_products'];
    if (!in_array($table, $allowed, true)) {
        return $slug;
    }
    $pdo = db();
    $base = $slug;
    $suffix = 1;
    $current = $slug;
    while (true) {
        $sql = 'SELECT id FROM ' . $table . ' WHERE slug = :slug';
        $params = ['slug' => $current];
        if ($excludeId > 0) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $current;
        }
        $suffix++;
        $current = $base . '-' . $suffix;
    }
}

function store_generate_order_number(): string
{
    $pdo = db();
    do {
        $candidate = 'GW-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT id FROM store_orders WHERE order_number = :order_number LIMIT 1');
        $stmt->execute(['order_number' => $candidate]);
    } while ($stmt->fetch());
    return $candidate;
}

function store_settings_defaults(): array
{
    return [
        'store_name' => 'Australian Goldwing Association Store',
        'store_slug' => 'store',
        'members_only' => 1,
        'shipping_region' => 'AU',
        'gst_enabled' => 1,
        'notification_emails' => '',
        'stripe_fee_enabled' => 1,
        'stripe_fee_percent' => 0.0,
        'stripe_fee_fixed' => 0.0,
        'shipping_flat_enabled' => 0,
        'shipping_flat_rate' => null,
        'shipping_free_enabled' => 0,
        'shipping_free_threshold' => null,
        'pickup_enabled' => 0,
        'pickup_instructions' => 'Pickup from Canberra -- we will email instructions.',
        'email_logo_url' => '',
        'email_footer_text' => 'Thanks for supporting the Australian Goldwing Association.',
        'support_email' => '',
    ];
}

function store_get_settings(): array
{
    $defaults = store_settings_defaults();
    $settings = [
        'store_name' => SettingsService::getGlobal('store.name', $defaults['store_name']),
        'store_slug' => SettingsService::getGlobal('store.slug', $defaults['store_slug']),
        'members_only' => SettingsService::getGlobal('store.members_only', $defaults['members_only']) ? 1 : 0,
        'notification_emails' => SettingsService::getGlobal('store.notification_emails', $defaults['notification_emails']),
        'stripe_fee_enabled' => SettingsService::getGlobal('store.pass_stripe_fees', $defaults['stripe_fee_enabled']) ? 1 : 0,
        'stripe_fee_percent' => (float) SettingsService::getGlobal('store.stripe_fee_percent', $defaults['stripe_fee_percent']),
        'stripe_fee_fixed' => (float) SettingsService::getGlobal('store.stripe_fee_fixed', $defaults['stripe_fee_fixed']),
        'shipping_flat_enabled' => SettingsService::getGlobal('store.shipping_flat_enabled', $defaults['shipping_flat_enabled']) ? 1 : 0,
        'shipping_flat_rate' => SettingsService::getGlobal('store.shipping_flat_rate', $defaults['shipping_flat_rate']),
        'shipping_free_enabled' => SettingsService::getGlobal('store.shipping_free_enabled', $defaults['shipping_free_enabled']) ? 1 : 0,
        'shipping_free_threshold' => SettingsService::getGlobal('store.shipping_free_threshold', $defaults['shipping_free_threshold']),
        'pickup_enabled' => SettingsService::getGlobal('store.pickup_enabled', $defaults['pickup_enabled']) ? 1 : 0,
        'pickup_instructions' => SettingsService::getGlobal('store.pickup_instructions', $defaults['pickup_instructions']),
        'email_logo_url' => SettingsService::getGlobal('store.email_logo_url', $defaults['email_logo_url']),
        'email_footer_text' => SettingsService::getGlobal('store.email_footer_text', $defaults['email_footer_text']),
        'support_email' => SettingsService::getGlobal('store.support_email', $defaults['support_email']),
        'shipping_region' => SettingsService::getGlobal('store.shipping_region', 'AU'),
        'gst_enabled' => SettingsService::getGlobal('store.gst_enabled', true) ? 1 : 0,
    ];

    return array_merge($defaults, $settings);
}

function store_parse_emails(string $value): array
{
    $parts = preg_split('/[,\n;]+/', $value);
    $emails = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    return array_values(array_unique($emails));
}

function store_money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

function store_calculate_discount(array $discount, float $subtotal): float
{
    if ($subtotal <= 0) {
        return 0.0;
    }
    $type = $discount['type'] ?? '';
    $value = (float) ($discount['value'] ?? 0);
    if ($type === 'percent') {
        return round($subtotal * ($value / 100), 2);
    }
    if ($type === 'fixed') {
        return min(round($value, 2), $subtotal);
    }
    return 0.0;
}

function store_calculate_processing_fee(float $baseAmount, float $percent, float $fixed): float
{
    $percent = max(0.0, $percent);
    $fixed = max(0.0, $fixed);
    if ($baseAmount <= 0 || ($percent <= 0 && $fixed <= 0)) {
        return 0.0;
    }
    $rate = $percent / 100;
    if ($rate >= 1) {
        return 0.0;
    }
    $fee = ($rate * $baseAmount + $fixed) / (1 - $rate);
    return round($fee, 2);
}

function store_calculate_shipping(float $subtotalAfterDiscount, array $settings, string $fulfillment): float
{
    if ($fulfillment === 'pickup') {
        return 0.0;
    }
    $freeEnabled = (int) ($settings['shipping_free_enabled'] ?? 0) === 1;
    $flatEnabled = (int) ($settings['shipping_flat_enabled'] ?? 0) === 1;
    $threshold = (float) ($settings['shipping_free_threshold'] ?? 0);
    $flatRate = (float) ($settings['shipping_flat_rate'] ?? 0);

    if ($freeEnabled && $threshold > 0 && $subtotalAfterDiscount >= $threshold) {
        return 0.0;
    }
    if ($flatEnabled && $flatRate > 0) {
        return round($flatRate, 2);
    }
    return 0.0;
}

function store_get_open_cart(int $userId): array
{
    $pdo = db();
    $userId = (int) $userId;
    if ($userId <= 0) {
        $sessionCartId = (int) ($_SESSION['guest_cart_id'] ?? 0);
        if ($sessionCartId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM store_carts WHERE id = :id AND user_id IS NULL AND status = "open" LIMIT 1');
            $stmt->execute(['id' => $sessionCartId]);
            $cart = $stmt->fetch();
            if ($cart) {
                return $cart;
            }
        }
        $stmt = $pdo->prepare('INSERT INTO store_carts (user_id, status, created_at) VALUES (NULL, "open", NOW())');
        $stmt->execute();
        $cartId = (int) $pdo->lastInsertId();
        $_SESSION['guest_cart_id'] = $cartId;
        return [
            'id' => $cartId,
            'user_id' => null,
            'status' => 'open',
            'discount_code' => null,
        ];
    }
    $stmt = $pdo->prepare('SELECT * FROM store_carts WHERE user_id = :user_id AND status = "open" LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $cart = $stmt->fetch();
    if ($cart) {
        return $cart;
    }
    $stmt = $pdo->prepare('INSERT INTO store_carts (user_id, status, created_at) VALUES (:user_id, "open", NOW())');
    $stmt->execute(['user_id' => $userId]);
    $cartId = (int) $pdo->lastInsertId();
    return [
        'id' => $cartId,
        'user_id' => $userId,
        'status' => 'open',
        'discount_code' => null,
    ];
}

function store_get_cart_items(int $cartId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM store_cart_items WHERE cart_id = :cart_id ORDER BY created_at ASC');
    $stmt->execute(['cart_id' => $cartId]);
    return $stmt->fetchAll();
}

function store_validate_discount_code(string $code, float $subtotal): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM store_discounts WHERE code = :code AND is_active = 1 LIMIT 1');
    $stmt->execute(['code' => strtoupper(trim($code))]);
    $discount = $stmt->fetch();
    if (!$discount) {
        return ['error' => 'Discount code not found.'];
    }
    $today = date('Y-m-d');
    if (!empty($discount['start_date']) && $discount['start_date'] > $today) {
        return ['error' => 'Discount is not active yet.'];
    }
    if (!empty($discount['end_date']) && $discount['end_date'] < $today) {
        return ['error' => 'Discount has expired.'];
    }
    if (!empty($discount['max_uses']) && (int) $discount['used_count'] >= (int) $discount['max_uses']) {
        return ['error' => 'Discount has reached its usage limit.'];
    }
    if (!empty($discount['min_spend']) && $subtotal < (float) $discount['min_spend']) {
        return ['error' => 'Order total does not meet minimum spend.'];
    }
    return ['discount' => $discount];
}

function store_apply_discount_to_items(array $items, float $discountTotal): array
{
    if ($discountTotal <= 0) {
        foreach ($items as &$item) {
            $item['unit_price_final'] = $item['unit_price'];
            $item['line_total'] = round($item['unit_price'] * $item['quantity'], 2);
        }
        return $items;
    }
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += $item['unit_price'] * $item['quantity'];
    }
    if ($subtotal <= 0) {
        return $items;
    }
    $remainingDiscount = $discountTotal;
    $updated = [];
    foreach ($items as $index => $item) {
        $lineTotal = $item['unit_price'] * $item['quantity'];
        $share = $lineTotal / $subtotal;
        $lineDiscount = $index === array_key_last($items)
            ? $remainingDiscount
            : round($discountTotal * $share, 2);
        $remainingDiscount -= $lineDiscount;
        $lineTotalAfter = max(0.0, round($lineTotal - $lineDiscount, 2));
        $unitFinal = $item['quantity'] > 0 ? round($lineTotalAfter / $item['quantity'], 2) : $item['unit_price'];
        $item['unit_price_final'] = $unitFinal;
        $item['line_total'] = $lineTotalAfter;
        $updated[] = $item;
    }
    return $updated;
}

function store_build_variant_label(array $optionValues): string
{
    if (empty($optionValues)) {
        return '';
    }
    $parts = [];
    foreach ($optionValues as $option) {
        $label = trim(($option['option_name'] ?? '') . ': ' . ($option['value'] ?? ''));
        if ($label !== ':') {
            $parts[] = $label;
        }
    }
    return implode(', ', $parts);
}

function store_calculate_cart_totals(array $items, ?array $discount, array $settings, string $fulfillment): array
{
    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
    }
    $discountTotal = $discount ? store_calculate_discount($discount, $subtotal) : 0.0;
    $subtotalAfterDiscount = max(0.0, $subtotal - $discountTotal);
    $shippingTotal = store_calculate_shipping($subtotalAfterDiscount, $settings, $fulfillment);
    $gstEnabled = (int) ($settings['gst_enabled'] ?? 0) === 1;
    $taxTotal = $gstEnabled ? round($subtotalAfterDiscount * 0.1, 2) : 0.0;
    $processingBase = $subtotalAfterDiscount + $shippingTotal + $taxTotal;
    $processingFee = (int) ($settings['stripe_fee_enabled'] ?? 0) === 1
        ? store_calculate_processing_fee($processingBase, (float) $settings['stripe_fee_percent'], (float) $settings['stripe_fee_fixed'])
        : 0.0;
    $total = $subtotalAfterDiscount + $shippingTotal + $taxTotal + $processingFee;

    return [
        'subtotal' => round($subtotal, 2),
        'discount_total' => round($discountTotal, 2),
        'shipping_total' => round($shippingTotal, 2),
        'tax_total' => round($taxTotal, 2),
        'processing_fee_total' => round($processingFee, 2),
        'total' => round($total, 2),
    ];
}

function store_build_email(string $subject, string $bodyHtml, array $settings): string
{
    return EmailService::wrapHtml($subject, $bodyHtml);
}

function store_send_email(string $to, string $subject, string $bodyHtml, array $settings): void
{
    $html = store_build_email($subject, $bodyHtml, $settings);
    EmailService::send($to, $subject, $html);
}

function store_send_admin_notifications(string $subject, string $bodyHtml, array $settings): void
{
    $emails = store_parse_emails((string) ($settings['notification_emails'] ?? ''));
    if (!$emails) {
        return;
    }
    $html = store_build_email($subject, $bodyHtml, $settings);
    foreach ($emails as $email) {
        EmailService::send($email, $subject, $html);
    }
}

function store_order_items_html(array $items): string
{
    $rows = '';
    foreach ($items as $item) {
        $label = $item['title_snapshot'];
        if (!empty($item['variant_snapshot'])) {
            $label .= ' (' . $item['variant_snapshot'] . ')';
        }
        $rows .= '<tr>'
            . '<td style="padding:6px 8px; border-bottom:1px solid #e5e7eb;">' . e($label) . '</td>'
            . '<td style="padding:6px 8px; border-bottom:1px solid #e5e7eb; text-align:center;">' . e((string) $item['quantity']) . '</td>'
            . '<td style="padding:6px 8px; border-bottom:1px solid #e5e7eb; text-align:right;">$' . e(store_money((float) $item['unit_price_final'])) . '</td>'
            . '<td style="padding:6px 8px; border-bottom:1px solid #e5e7eb; text-align:right;">$' . e(store_money((float) $item['line_total'])) . '</td>'
            . '</tr>';
    }
    return '<table style="width:100%; border-collapse:collapse; font-size:13px;">'
        . '<thead><tr>'
        . '<th style="text-align:left; padding:6px 8px; border-bottom:2px solid #e5e7eb;">Item</th>'
        . '<th style="text-align:center; padding:6px 8px; border-bottom:2px solid #e5e7eb;">Qty</th>'
        . '<th style="text-align:right; padding:6px 8px; border-bottom:2px solid #e5e7eb;">Price</th>'
        . '<th style="text-align:right; padding:6px 8px; border-bottom:2px solid #e5e7eb;">Total</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';
}

function store_order_totals_html(array $order): string
{
    $gstEnabled = SettingsService::getGlobal('store.gst_enabled', false);
    $taxTotal = $gstEnabled ? round(max(0.0, (float) $order['subtotal'] - (float) $order['discount_total']) * 0.1, 2) : 0.0;
    $taxRow = $gstEnabled
        ? '<tr><td style="padding:4px 0;">GST</td><td style="padding:4px 0; text-align:right;">$' . e(store_money($taxTotal)) . '</td></tr>'
        : '';

    return '<table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:12px;">'
        . '<tr><td style="padding:4px 0;">Subtotal</td><td style="padding:4px 0; text-align:right;">$' . e(store_money((float) $order['subtotal'])) . '</td></tr>'
        . '<tr><td style="padding:4px 0;">Discount</td><td style="padding:4px 0; text-align:right;">-$' . e(store_money((float) $order['discount_total'])) . '</td></tr>'
        . $taxRow
        . '<tr><td style="padding:4px 0;">Shipping</td><td style="padding:4px 0; text-align:right;">$' . e(store_money((float) $order['shipping_total'])) . '</td></tr>'
        . '<tr><td style="padding:4px 0;">Payment processing fee</td><td style="padding:4px 0; text-align:right;">$' . e(store_money((float) $order['processing_fee_total'])) . '</td></tr>'
        . '<tr><td style="padding:6px 0; font-weight:bold;">Total</td><td style="padding:6px 0; text-align:right; font-weight:bold;">$' . e(store_money((float) $order['total'])) . '</td></tr>'
        . '</table>';
}

function store_order_address_html(array $order): string
{
    if ($order['fulfillment_method'] === 'pickup') {
        return '<p><strong>Pickup</strong><br>' . e($order['pickup_instructions_snapshot'] ?? '') . '</p>';
    }
    $parts = array_filter([
        $order['shipping_name'] ?? '',
        $order['shipping_address_line1'] ?? '',
        $order['shipping_address_line2'] ?? '',
        trim(($order['shipping_city'] ?? '') . ' ' . ($order['shipping_state'] ?? '') . ' ' . ($order['shipping_postal_code'] ?? '')),
        $order['shipping_country'] ?? '',
    ]);
    $safeParts = array_map('e', $parts);
    return '<p><strong>Shipping address</strong><br>' . implode('<br>', $safeParts) . '</p>';
}

function store_ticket_list_html(array $tickets): string
{
    if (!$tickets) {
        return '';
    }
    $rows = '';
    foreach ($tickets as $ticket) {
        $rows .= '<tr>'
            . '<td style="padding:6px 8px; border-bottom:1px solid #e5e7eb;">' . e($ticket['event_name'] ?? '') . '</td>'
            . '<td style="padding:6px 8px; border-bottom:1px solid #e5e7eb; font-weight:bold;">' . e($ticket['ticket_code']) . '</td>'
            . '</tr>';
    }
    return '<h3 style="margin-top:16px;">Ticket codes</h3>'
        . '<table style="width:100%; border-collapse:collapse; font-size:13px;">'
        . '<thead><tr>'
        . '<th style="text-align:left; padding:6px 8px; border-bottom:2px solid #e5e7eb;">Event</th>'
        . '<th style="text-align:left; padding:6px 8px; border-bottom:2px solid #e5e7eb;">Code</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';
}
