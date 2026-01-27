<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\NavigationService;
use App\Services\OrderService;
use App\Services\MembershipOrderService;
use App\Services\MembershipService;
use App\Services\PaymentSettingsService;
use App\Services\PaymentWebhookService;
use App\Services\StepUpService;
use App\Services\StripeService;
use App\Services\StripeSettingsService;
use App\Services\SettingsService;
use App\Services\BaseUrlService;
use App\Services\Validator;
use App\Services\MembershipPricingService;
use App\Services\MemberRepository;
use App\Services\MediaService;

header('Content-Type: application/json');

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function require_admin_json(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Unauthorized.'], 401);
    }
    if (!in_array('admin', $user['roles'] ?? [], true)) {
        json_response(['error' => 'Forbidden.'], 403);
    }
    return $user;
}

function require_user_json(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Unauthorized.'], 401);
    }
    return $user;
}

function require_roles_json(array $roles): array
{
    $user = require_user_json();
    $userRoles = $user['roles'] ?? [];
    foreach ($roles as $role) {
        if (in_array($role, $userRoles, true)) {
            return $user;
        }
    }
    json_response(['error' => 'Forbidden.'], 403);
}

function require_csrf_json(array $body): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? '');
    if (!Csrf::verify($token)) {
        json_response(['error' => 'Invalid CSRF token.'], 403);
    }
}

function require_stepup_json(array $user): void
{
    if (!StepUpService::isValid((int) $user['id'])) {
        json_response(['error' => 'Step-up required.'], 403);
    }
}

function require_stripe_checkout_enabled(): array
{
    $settings = StripeSettingsService::getSettings();
    if (empty($settings['checkout_enabled'])) {
        json_response(['error' => 'Checkout is currently unavailable.'], 422);
    }
    return $settings;
}

function stripe_admin_settings_response(): array
{
    $stripeSettings = StripeSettingsService::getSettings();
    $activeKeys = StripeSettingsService::getActiveKeys();
    $channel = PaymentSettingsService::getChannelByCode('primary');
    $paymentSettings = PaymentSettingsService::getSettingsByChannelId((int) ($channel['id'] ?? 0));
    $prices = $stripeSettings['membership_prices'] ?? [];
    if (!is_array($prices)) {
        $prices = [];
    }

    return [
        'mode' => $activeKeys['mode'] ?? 'test',
        'use_test_mode' => (bool) ($stripeSettings['use_test_mode'] ?? true),
        'keys' => [
            'test_publishable' => StripeSettingsService::maskValue($stripeSettings['test_publishable_key'] ?? ''),
            'test_secret' => StripeSettingsService::maskValue($stripeSettings['test_secret_key'] ?? ''),
            'live_publishable' => StripeSettingsService::maskValue($stripeSettings['live_publishable_key'] ?? ''),
            'live_secret' => StripeSettingsService::maskValue($stripeSettings['live_secret_key'] ?? ''),
        ],
        'webhook' => [
            'url' => BaseUrlService::buildUrl('/api/stripe/webhook'),
            'secret' => StripeSettingsService::maskValue($stripeSettings['webhook_secret'] ?? ''),
            'health' => StripeSettingsService::webhookHealth($paymentSettings),
        ],
        'checkout' => [
            'enabled' => (bool) ($stripeSettings['checkout_enabled'] ?? true),
            'allow_guest_checkout' => (bool) ($stripeSettings['allow_guest_checkout'] ?? true),
            'require_shipping_for_physical' => (bool) ($stripeSettings['require_shipping_for_physical'] ?? true),
            'digital_only_minimal' => (bool) ($stripeSettings['digital_only_minimal'] ?? true),
        ],
        'payment_methods' => [
            'apple_pay' => (bool) ($stripeSettings['enable_apple_pay'] ?? true),
            'google_pay' => (bool) ($stripeSettings['enable_google_pay'] ?? true),
            'bnpl' => (bool) ($stripeSettings['enable_bnpl'] ?? false),
        ],
        'receipts' => [
            'send_receipts' => (bool) ($stripeSettings['send_receipts'] ?? true),
            'save_invoice_refs' => (bool) ($stripeSettings['save_invoice_refs'] ?? true),
        ],
        'membership' => [
            'prices' => $prices,
            'default_term' => (string) ($stripeSettings['membership_default_term'] ?? '12M'),
            'allow_both_types' => (bool) ($stripeSettings['membership_allow_both_types'] ?? true),
        ],
        'customer_portal_enabled' => (bool) ($stripeSettings['customer_portal_enabled'] ?? false),
    ];
}

function insert_member_bikes($pdo, int $memberId, array $vehicles): void
{
    if (!$vehicles || $memberId <= 0) {
        return;
    }
    $bikeColumns = [];
    $bikeHasRego = true;
    $bikeHasColour = false;
    $bikeHasPrimary = false;
    try {
        $bikeColumns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(\PDO::FETCH_COLUMN, 0);
        $bikeHasRego = in_array('rego', $bikeColumns, true);
        $bikeHasColour = in_array('colour', $bikeColumns, true) || in_array('color', $bikeColumns, true);
        $bikeHasPrimary = in_array('is_primary', $bikeColumns, true);
    } catch (Throwable $e) {
        $bikeColumns = [];
        $bikeHasRego = true;
        $bikeHasColour = false;
        $bikeHasPrimary = false;
    }

    $primarySet = false;
    if ($bikeHasPrimary) {
        $primaryStmt = $pdo->prepare('SELECT 1 FROM member_bikes WHERE member_id = :member_id AND is_primary = 1 LIMIT 1');
        $primaryStmt->execute(['member_id' => $memberId]);
        $primarySet = (bool) $primaryStmt->fetchColumn();
    }

    foreach ($vehicles as $vehicle) {
        $make = trim((string) ($vehicle['make'] ?? ''));
        $model = trim((string) ($vehicle['model'] ?? ''));
        $yearValue = trim((string) ($vehicle['year'] ?? ''));
        $year = $yearValue !== '' && is_numeric($yearValue) ? (int) $yearValue : null;
        $rego = trim((string) ($vehicle['rego'] ?? ''));
        $colour = trim((string) ($vehicle['colour'] ?? ($vehicle['color'] ?? '')));
        if (strlen($rego) > 20) {
            $rego = substr($rego, 0, 20);
        }
        if ($make !== '' && $model !== '') {
            $columns = ['member_id', 'make', 'model', 'year', 'created_at'];
            $placeholders = [':member_id', ':make', ':model', ':year', 'NOW()'];
            $params = [
                'member_id' => $memberId,
                'make' => $make,
                'model' => $model,
                'year' => $year,
            ];
            if ($bikeHasRego) {
                $columns[] = 'rego';
                $placeholders[] = ':rego';
                $params['rego'] = $rego !== '' ? $rego : null;
            }
            if ($bikeHasColour && $colour !== '') {
                if (in_array('colour', $bikeColumns, true)) {
                    $columns[] = 'colour';
                    $placeholders[] = ':colour';
                    $params['colour'] = $colour;
                } elseif (in_array('color', $bikeColumns, true)) {
                    $columns[] = 'color';
                    $placeholders[] = ':color';
                    $params['color'] = $colour;
                }
            }
            if ($bikeHasPrimary && !$primarySet) {
                $columns[] = 'is_primary';
                $placeholders[] = ':is_primary';
                $params['is_primary'] = 1;
            }
            $stmt = $pdo->prepare('INSERT INTO member_bikes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
            $stmt->execute($params);
            if ($bikeHasPrimary && !$primarySet) {
                $primarySet = true;
            }
        }
    }
}

function handle_stripe_webhook_request(string $payload, string $signature): void
{
    $channel = PaymentSettingsService::getChannelByCode('primary');
    $channelId = (int) ($channel['id'] ?? 0);
    $secret = StripeSettingsService::getWebhookSecret();
    $event = StripeService::constructEvent($payload, $signature, $secret);
    if (!$event) {
        if ($channelId > 0) {
            PaymentSettingsService::updateWebhookStatus($channelId, 'Invalid signature');
        }
        json_response(['error' => 'Invalid signature.'], 400);
    }

    $isNew = PaymentWebhookService::recordEvent($event);
    if (!$isNew) {
        json_response(['received' => true]);
    }

    $eventId = $event['id'] ?? '';
    try {
        $type = $event['type'] ?? '';
        if ($type === 'checkout.session.completed') {
            PaymentWebhookService::handleCheckoutCompleted($event, $channelId);
        }
        if ($type === 'payment_intent.succeeded') {
            PaymentWebhookService::handlePaymentIntentSucceeded($event);
        }
        if (in_array($type, ['payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
            PaymentWebhookService::handlePaymentFailed($event);
        }
        if ($type === 'charge.refunded') {
            PaymentWebhookService::handleChargeRefunded($event);
        }
        if ($type === 'invoice.paid') {
            PaymentWebhookService::handleInvoicePaid($event);
        }
        if ($type === 'invoice.payment_failed') {
            PaymentWebhookService::handleInvoicePaymentFailed($event);
        }
        if (in_array($type, ['customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            PaymentWebhookService::handleSubscriptionUpdated($event);
        }
        PaymentWebhookService::markProcessed($eventId, 'processed', null);
        if ($channelId > 0) {
            PaymentSettingsService::updateWebhookStatus($channelId, null);
        }
    } catch (Throwable $e) {
        PaymentWebhookService::markProcessed($eventId, 'failed', $e->getMessage());
        if ($channelId > 0) {
            PaymentSettingsService::updateWebhookStatus($channelId, $e->getMessage());
        }
        json_response(['error' => 'Webhook processing failed.'], 500);
    }

    json_response(['received' => true]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = $_POST;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path ?? '', '/');
$segments = $path === '' ? [] : explode('/', $path);
if (!empty($segments) && $segments[0] === 'api') {
    array_shift($segments);
}
if (!empty($segments) && $segments[0] === 'index.php') {
    array_shift($segments);
}

if (empty($segments)) {
    json_response(['error' => 'Not found.'], 404);
}

$resource = $segments[0];

if ($resource === 'stripe') {
    if (count($segments) === 2 && $segments[1] === 'config') {
        if ($method !== 'GET') {
            json_response(['error' => 'Method not allowed.'], 405);
        }
        json_response(StripeSettingsService::getPublicConfig());
    }

    if (count($segments) === 2 && $segments[1] === 'create-application-payment-intent') {
        if ($method !== 'POST') {
            json_response(['error' => 'Method not allowed.'], 405);
        }
        require_csrf_json($body);
        $paymentMethod = strtolower(trim((string) ($body['payment_method'] ?? 'card')));
        if (!in_array($paymentMethod, ['card', 'bank_transfer'], true)) {
            $paymentMethod = 'card';
        }
        $stripeSettings = [];
        if ($paymentMethod === 'card') {
            $stripeSettings = require_stripe_checkout_enabled();
            $activeKeys = StripeSettingsService::getActiveKeys();
            if (empty($activeKeys['secret_key'])) {
                json_response(['error' => 'Stripe is not configured.'], 422);
            }
        }
        $fullSelected = !empty($body['membership_full']);
        $associateSelected = !empty($body['membership_associate']);
        if (!$fullSelected && !$associateSelected) {
            json_response(['error' => 'Select at least one membership type.'], 422);
        }
        $associateAdd = $body['associate_add'] ?? '';
        if ($associateSelected && $associateAdd !== 'yes') {
            json_response(['error' => 'Associate details are required.'], 422);
        }
        $fullMagazineType = strtoupper(trim((string) ($body['full_magazine_type'] ?? '')));
        if (!in_array($fullMagazineType, MembershipPricingService::MAGAZINE_TYPES, true)) {
            $fullMagazineType = 'PRINTED';
        }
        $fullPeriodKey = strtoupper(trim((string) ($body['full_period_key'] ?? '')));
        $associatePeriodKey = strtoupper(trim((string) ($body['associate_period_key'] ?? '')));

        $fullPriceCents = null;
        if ($fullSelected) {
            if ($fullPeriodKey === '') {
                json_response(['error' => 'Select a membership period for Full membership.'], 422);
            }
            $fullPriceCents = MembershipPricingService::getPriceCents($fullMagazineType, 'FULL', $fullPeriodKey);
            if ($fullPriceCents === null) {
                json_response(['error' => 'Unable to locate full membership pricing.'], 422);
            }
        }

        $associatePriceCents = null;
        if ($associateSelected) {
            if ($associatePeriodKey === '') {
                json_response(['error' => 'Select a membership period for the associate member.'], 422);
            }
            $associateMagazine = $fullSelected ? $fullMagazineType : 'PRINTED';
            $associatePriceCents = MembershipPricingService::getPriceCents($associateMagazine, 'ASSOCIATE', $associatePeriodKey);
            if ($associatePriceCents === null) {
                json_response(['error' => 'Unable to locate associate membership pricing.'], 422);
            }
        }

        $totalCents = (int) ($fullPriceCents ?? 0) + (int) ($associatePriceCents ?? 0);
        if ($totalCents <= 0) {
            json_response(['error' => 'Invalid membership amount.'], 422);
        }
        $storeSettings = store_get_settings();
        $processingFee = 0.0;
        if ((int) ($storeSettings['stripe_fee_enabled'] ?? 0) === 1) {
            $processingFee = store_calculate_processing_fee(
                $totalCents / 100,
                (float) ($storeSettings['stripe_fee_percent'] ?? 0),
                (float) ($storeSettings['stripe_fee_fixed'] ?? 0)
            );
        }
        $processingFeeCents = (int) round($processingFee * 100);
        $totalWithFeeCents = $totalCents + $processingFeeCents;

        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName = trim((string) ($body['last_name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        if ($firstName === '' || $lastName === '' || $email === '') {
            json_response(['error' => 'Primary member name and email are required.'], 422);
        }

        $requestId = trim((string) ($body['request_id'] ?? ''));
        $intentIdempotency = $requestId !== '' ? $requestId : 'membership_application_' . bin2hex(random_bytes(6));
        $payload = [
            'amount' => $totalWithFeeCents,
            'currency' => 'aud',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'purpose' => 'membership_application',
                'membership_full' => $fullSelected ? '1' : '0',
                'membership_associate' => $associateSelected ? '1' : '0',
                'full_magazine_type' => $fullMagazineType,
                'full_period_key' => $fullPeriodKey,
                'associate_period_key' => $associatePeriodKey,
                'associate_add' => $associateAdd,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'processing_fee_cents' => (string) $processingFeeCents,
                'total_with_fee_cents' => (string) $totalWithFeeCents,
            ],
        ];
        if ($email !== '') {
            $payload['receipt_email'] = $email;
        }

        $intent = StripeService::createPaymentIntent($payload, $intentIdempotency);
        if (!$intent || empty($intent['client_secret'])) {
            json_response(['error' => 'Unable to create Stripe payment.'], 500);
        }
        json_response([
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id'] ?? null,
        ]);
    }

    if (count($segments) === 2 && $segments[1] === 'create-payment-intent') {
        if ($method !== 'POST') {
            json_response(['error' => 'Method not allowed.'], 405);
        }
        require_csrf_json($body);
        $stripeSettings = require_stripe_checkout_enabled();
        $activeKeys = StripeSettingsService::getActiveKeys();
        if (empty($activeKeys['secret_key'])) {
            json_response(['error' => 'Stripe is not configured.'], 422);
        }
        $user = current_user();
        if (!$user && $paymentMethod === 'card' && empty($stripeSettings['allow_guest_checkout'])) {
            json_response(['error' => 'Guest checkout is disabled.'], 403);
        }
        if (!$user && $paymentMethod === 'bank_transfer' && empty((StripeSettingsService::getSettings())['allow_guest_checkout'])) {
            json_response(['error' => 'Guest checkout is disabled.'], 403);
        }

        $settingsStore = store_get_settings();
        $channel = PaymentSettingsService::getChannelByCode('primary');
        $channelId = (int) ($channel['id'] ?? 0);
        $cart = store_get_open_cart((int) ($user['id'] ?? 0));
        $itemsStmt = db()->prepare('SELECT ci.*, p.type, p.event_name, p.track_inventory, p.stock_quantity, v.stock_quantity as variant_stock FROM store_cart_items ci JOIN store_products p ON p.id = ci.product_id LEFT JOIN store_product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = :cart_id');
        $itemsStmt->execute(['cart_id' => $cart['id']]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            json_response(['error' => 'Your cart is empty.'], 422);
        }

        $requiresShipping = false;
        foreach ($items as $item) {
            if (($item['type'] ?? 'physical') === 'physical') {
                $requiresShipping = true;
                break;
            }
        }

        $fulfillment = 'shipping';

        $shipping = [
            'name' => trim((string) ($body['shipping_name'] ?? '')),
            'line1' => trim((string) ($body['shipping_line1'] ?? '')),
            'line2' => trim((string) ($body['shipping_line2'] ?? '')),
            'city' => trim((string) ($body['shipping_city'] ?? '')),
            'state' => trim((string) ($body['shipping_state'] ?? '')),
            'postal' => trim((string) ($body['shipping_postal'] ?? '')),
            'country' => trim((string) ($body['shipping_country'] ?? 'Australia')),
        ];

        $guestEmail = trim((string) ($body['guest_email'] ?? ''));
        $guestFirst = trim((string) ($body['guest_first_name'] ?? ''));
        $guestLast = trim((string) ($body['guest_last_name'] ?? ''));
        $guestPhone = trim((string) ($body['guest_phone'] ?? ''));
        if (!$user) {
            if ($guestEmail === '' || $guestFirst === '' || $guestLast === '') {
                json_response(['error' => 'Guest details are required.'], 422);
            }
        }

        if ($requiresShipping && $fulfillment === 'shipping' && !empty($stripeSettings['require_shipping_for_physical'])) {
            $country = strtolower($shipping['country']);
            if ($country !== 'australia' && $country !== 'au') {
                json_response(['error' => 'Shipping is available in Australia only.'], 422);
            }
            if ($shipping['line1'] === '' || $shipping['city'] === '' || $shipping['state'] === '' || $shipping['postal'] === '') {
                json_response(['error' => 'Shipping address is required.'], 422);
            }
        }

        $stockErrors = [];
        foreach ($items as $item) {
            if ((int) $item['track_inventory'] !== 1) {
                continue;
            }
            $available = $item['variant_id'] ? (int) ($item['variant_stock'] ?? 0) : (int) ($item['stock_quantity'] ?? 0);
            if ($available < (int) $item['quantity']) {
                $stockErrors[] = $item['title_snapshot'] . ' is out of stock.';
            }
        }
        if ($stockErrors) {
            json_response(['error' => implode(' ', $stockErrors)], 422);
        }

        $discount = null;
        if (!empty($cart['discount_code'])) {
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $result = store_validate_discount_code($cart['discount_code'], $subtotal);
            if (!empty($result['discount'])) {
                $discount = $result['discount'];
            }
        }

        $totals = store_calculate_cart_totals($items, $discount, $settingsStore, $fulfillment);
        $subtotalAfterDiscount = max(0.0, $totals['subtotal'] - $totals['discount_total']);
        if ($requiresShipping && $fulfillment === 'shipping') {
            $threshold = (float) ($settingsStore['shipping_free_threshold'] ?? 0);
            $flatRate = (float) ($settingsStore['shipping_flat_rate'] ?? 0);
            $shippingAvailable = false;
            if (!empty($settingsStore['shipping_free_enabled']) && $threshold > 0 && $subtotalAfterDiscount >= $threshold) {
                $shippingAvailable = true;
            } elseif (!empty($settingsStore['shipping_flat_enabled']) && $flatRate > 0) {
                $shippingAvailable = true;
            }
        }

        $orderNumber = store_generate_order_number();
        $customerName = $user ? ($user['name'] ?? '') : trim($guestFirst . ' ' . $guestLast);
        $customerEmail = $user ? ($user['email'] ?? '') : $guestEmail;

        $orderPayload = [
            'order_number' => $orderNumber,
            'user_id' => $user['id'] ?? null,
            'member_id' => $user['member_id'] ?? null,
            'status' => 'pending',
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'shipping_total' => $totals['shipping_total'],
            'processing_fee_total' => $totals['processing_fee_total'],
            'total' => $totals['total'],
            'discount_code' => $cart['discount_code'] ?? null,
            'discount_id' => $discount['id'] ?? null,
            'fulfillment_method' => $fulfillment,
            'shipping_name' => $fulfillment === 'shipping' ? $shipping['name'] : null,
            'shipping_address_line1' => $fulfillment === 'shipping' ? $shipping['line1'] : null,
            'shipping_address_line2' => $fulfillment === 'shipping' ? $shipping['line2'] : null,
            'shipping_city' => $fulfillment === 'shipping' ? $shipping['city'] : null,
            'shipping_state' => $fulfillment === 'shipping' ? $shipping['state'] : null,
            'shipping_postal_code' => $fulfillment === 'shipping' ? $shipping['postal'] : null,
            'shipping_country' => $fulfillment === 'shipping' ? 'Australia' : null,
            'pickup_instructions_snapshot' => $fulfillment === 'pickup' ? ($settingsStore['pickup_instructions'] ?? '') : null,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
        ];

        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO store_orders (order_number, user_id, member_id, status, subtotal, discount_total, shipping_total, processing_fee_total, total, discount_code, discount_id, fulfillment_method, shipping_name, shipping_address_line1, shipping_address_line2, shipping_city, shipping_state, shipping_postal_code, shipping_country, pickup_instructions_snapshot, customer_name, customer_email, created_at) VALUES (:order_number, :user_id, :member_id, :status, :subtotal, :discount_total, :shipping_total, :processing_fee_total, :total, :discount_code, :discount_id, :fulfillment_method, :shipping_name, :shipping_address_line1, :shipping_address_line2, :shipping_city, :shipping_state, :shipping_postal_code, :shipping_country, :pickup_instructions_snapshot, :customer_name, :customer_email, NOW())');
        $stmt->execute($orderPayload);
        $storeOrderId = (int) $pdo->lastInsertId();

        $itemsWithDiscount = store_apply_discount_to_items($items, $totals['discount_total']);
        foreach ($itemsWithDiscount as $item) {
            $stmt = $pdo->prepare('INSERT INTO store_order_items (order_id, product_id, variant_id, title_snapshot, variant_snapshot, sku_snapshot, type, event_name_snapshot, quantity, unit_price, unit_price_final, line_total, created_at) VALUES (:order_id, :product_id, :variant_id, :title_snapshot, :variant_snapshot, :sku_snapshot, :type, :event_name_snapshot, :quantity, :unit_price, :unit_price_final, :line_total, NOW())');
            $stmt->execute([
                'order_id' => $storeOrderId,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'title_snapshot' => $item['title_snapshot'],
                'variant_snapshot' => $item['variant_snapshot'],
                'sku_snapshot' => $item['sku_snapshot'],
                'type' => $item['type'],
                'event_name_snapshot' => $item['event_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'unit_price_final' => $item['unit_price_final'],
                'line_total' => $item['line_total'],
            ]);
        }

        if ($discount) {
            $stmt = $pdo->prepare('INSERT INTO store_order_discounts (order_id, discount_id, code, type, value, amount, created_at) VALUES (:order_id, :discount_id, :code, :type, :value, :amount, NOW())');
            $stmt->execute([
                'order_id' => $storeOrderId,
                'discount_id' => $discount['id'],
                'code' => $discount['code'],
                'type' => $discount['type'],
                'value' => $discount['value'],
                'amount' => $totals['discount_total'],
            ]);
        }

        $orderSubtotal = max(0.0, $totals['subtotal'] - $totals['discount_total'] + $totals['processing_fee_total']);
        $orderItems = array_map(function ($item) {
            return [
                'product_id' => $item['product_id'],
                'name' => $item['title_snapshot'] . ($item['variant_snapshot'] ? ' (' . $item['variant_snapshot'] . ')' : ''),
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price_final'],
                'is_physical' => ($item['type'] ?? '') === 'physical' ? 1 : 0,
            ];
        }, $itemsWithDiscount);
        if ($totals['processing_fee_total'] > 0) {
            $orderItems[] = [
                'product_id' => null,
                'name' => 'Payment processing fee',
                'quantity' => 1,
                'unit_price' => (float) $totals['processing_fee_total'],
                'is_physical' => 0,
            ];
        }

        $orderId = OrderService::createOrder([
            'user_id' => $user['id'] ?? null,
            'status' => 'pending',
            'order_type' => 'store',
            'currency' => 'AUD',
            'subtotal' => $orderSubtotal,
            'tax_total' => $totals['tax_total'] ?? 0,
            'shipping_total' => $totals['shipping_total'],
            'total' => $totals['total'],
            'channel_id' => $channelId,
            'shipping_required' => $requiresShipping ? 1 : 0,
            'shipping_address_json' => json_encode([
                'fulfillment' => $fulfillment,
                'shipping' => $fulfillment === 'shipping' ? $shipping : null,
                'pickup_instructions' => $fulfillment === 'pickup' ? ($settingsStore['pickup_instructions'] ?? '') : null,
                'store_order_id' => $storeOrderId,
                'store_order_number' => $orderNumber,
            ]),
        ], $orderItems);

        if ($paymentMethod === 'bank_transfer') {
            $stmt = $pdo->prepare('UPDATE orders SET payment_method = "bank_transfer", payment_status = "pending", updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $orderId]);
            $stmt = $pdo->prepare('UPDATE store_carts SET status = "converted", updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $cart['id']]);
            json_response([
                'orderId' => $orderId,
                'storeOrderId' => $storeOrderId,
                'payment_method' => 'bank_transfer',
            ]);
        }

        $amountCents = (int) round(((float) $totals['total']) * 100);
        $payload = [
            'amount' => $amountCents,
            'currency' => 'aud',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'order_id' => (string) $orderId,
                'order_type' => 'store',
                'store_order_id' => (string) $storeOrderId,
                'store_order_number' => (string) $orderNumber,
                'user_id' => (string) ($user['id'] ?? ''),
                'is_guest' => $user ? '0' : '1',
                'cart_id' => (string) ($cart['id'] ?? ''),
            ],
        ];
        if (!empty($stripeSettings['send_receipts'])) {
            $payload['receipt_email'] = $customerEmail;
        }
        if ($requiresShipping && $fulfillment === 'shipping') {
            $payload['shipping'] = [
                'name' => $shipping['name'],
                'phone' => $guestPhone !== '' ? $guestPhone : null,
                'address' => [
                    'line1' => $shipping['line1'],
                    'line2' => $shipping['line2'],
                    'city' => $shipping['city'],
                    'state' => $shipping['state'],
                    'postal_code' => $shipping['postal'],
                    'country' => 'AU',
                ],
            ];
        }

        $intent = StripeService::createPaymentIntent($payload, 'store_order_' . $storeOrderId);
        if (!$intent || empty($intent['client_secret'])) {
            json_response(['error' => 'Unable to start checkout.'], 500);
        }

        $stmt = $pdo->prepare('UPDATE store_orders SET stripe_payment_intent_id = :payment_intent_id WHERE id = :id');
        $stmt->execute(['payment_intent_id' => $intent['id'] ?? '', 'id' => $storeOrderId]);
        $stmt = $pdo->prepare('UPDATE orders SET stripe_payment_intent_id = :payment_intent_id, payment_method = "stripe", payment_status = "pending", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['payment_intent_id' => $intent['id'] ?? '', 'id' => $orderId]);
        $stmt = $pdo->prepare('UPDATE store_carts SET status = "converted", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $cart['id']]);

        json_response([
            'client_secret' => $intent['client_secret'],
            'orderId' => $orderId,
            'storeOrderId' => $storeOrderId,
        ]);
    }

    if (count($segments) === 2 && $segments[1] === 'create-subscription') {
        if ($method !== 'POST') {
            json_response(['error' => 'Method not allowed.'], 405);
        }
        require_csrf_json($body);
        $stripeSettings = require_stripe_checkout_enabled();
        $activeKeys = StripeSettingsService::getActiveKeys();
        if (empty($activeKeys['secret_key'])) {
            json_response(['error' => 'Stripe is not configured.'], 422);
        }
        $user = current_user();

        $fullSelected = !empty($body['membership_full']);
        $associateSelected = !empty($body['membership_associate']);
        $associateAdd = $body['associate_add'] ?? '';
        if (!$fullSelected && !$associateSelected) {
            json_response(['error' => 'Select at least one membership type.'], 422);
        }
        if (empty($stripeSettings['membership_allow_both_types']) && $fullSelected && $associateSelected) {
            json_response(['error' => 'Only one membership type can be selected.'], 422);
        }
        if ($associateSelected && $associateAdd !== 'yes') {
            json_response(['error' => 'Associate details are required.'], 422);
        }

        $term = strtoupper(trim((string) ($body['membership_term'] ?? ($stripeSettings['membership_default_term'] ?? '12M'))));
        if (!in_array($term, ['12M', '24M'], true)) {
            $term = '12M';
        }
        $termLabel = $term === '24M' ? '2Y' : '1Y';

        $prices = $stripeSettings['membership_prices'] ?? [];
        if (!is_array($prices)) {
            $prices = [];
        }
        $fullPriceId = $term === '24M' ? ($prices['FULL_24'] ?? '') : ($prices['FULL_12'] ?? '');
        $associatePriceId = $term === '24M' ? ($prices['ASSOCIATE_24'] ?? '') : ($prices['ASSOCIATE_12'] ?? '');
        if ($fullSelected && $fullPriceId === '') {
            json_response(['error' => 'Full membership pricing is not configured.'], 422);
        }
        if ($associateSelected && $associatePriceId === '') {
            json_response(['error' => 'Associate membership pricing is not configured.'], 422);
        }

        $items = [];
        if ($fullSelected) {
            $items[] = ['price' => $fullPriceId, 'quantity' => 1];
        }
        if ($associateSelected) {
            $items[] = ['price' => $associatePriceId, 'quantity' => 1];
        }

        $primaryFirst = trim((string) ($body['first_name'] ?? ''));
        $primaryLast = trim((string) ($body['last_name'] ?? ''));
        $primaryEmail = trim((string) ($body['email'] ?? ''));
        $primaryPhone = trim((string) ($body['phone'] ?? ''));
        $primaryAddress = [
            'line1' => trim((string) ($body['address_line1'] ?? '')),
            'line2' => trim((string) ($body['address_line2'] ?? '')),
            'city' => trim((string) ($body['city'] ?? '')),
            'state' => trim((string) ($body['state'] ?? '')),
            'postal' => trim((string) ($body['postal_code'] ?? '')),
            'country' => trim((string) ($body['country'] ?? 'Australia')),
        ];

        $associateFirst = trim((string) ($body['associate_first_name'] ?? ''));
        $associateLast = trim((string) ($body['associate_last_name'] ?? ''));
        $associateEmail = trim((string) ($body['associate_email'] ?? ''));

        $fullVehiclePayload = (string) ($body['full_vehicle_payload'] ?? '[]');
        $associateVehiclePayload = (string) ($body['associate_vehicle_payload'] ?? '[]');
        $fullVehicles = json_decode($fullVehiclePayload, true);
        $associateVehicles = json_decode($associateVehiclePayload, true);
        if (!is_array($fullVehicles)) {
            $fullVehicles = [];
        }
        if (!is_array($associateVehicles)) {
            $associateVehicles = [];
        }

        $membershipUserId = (int) ($user['id'] ?? 0);
        $memberId = $user['member_id'] ?? null;
        $pdo = db();
        $member = null;
        $createdMember = false;
        if ($memberId) {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $memberId]);
            $member = $stmt->fetch();
        }
        if (!$member && $membershipUserId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE user_id = :user_id LIMIT 1');
            $stmt->execute(['user_id' => $membershipUserId]);
            $member = $stmt->fetch();
            if ($member) {
                $memberId = (int) ($member['id'] ?? 0);
            }
        }

        if ($primaryFirst === '' && $member) {
            $primaryFirst = (string) ($member['first_name'] ?? '');
        }
        if ($primaryLast === '' && $member) {
            $primaryLast = (string) ($member['last_name'] ?? '');
        }
        if ($primaryEmail === '' && $member) {
            $primaryEmail = (string) ($member['email'] ?? '');
        }
        if ($primaryPhone === '' && $member) {
            $primaryPhone = (string) ($member['phone'] ?? '');
        }
        if ($primaryAddress['line1'] === '' && $member) {
            $primaryAddress['line1'] = (string) ($member['address_line1'] ?? '');
        }
        if ($primaryAddress['line2'] === '' && $member) {
            $primaryAddress['line2'] = (string) ($member['address_line2'] ?? '');
        }
        if ($primaryAddress['city'] === '' && $member) {
            $primaryAddress['city'] = (string) ($member['city'] ?? '');
        }
        if ($primaryAddress['state'] === '' && $member) {
            $primaryAddress['state'] = (string) ($member['state'] ?? '');
        }
        if ($primaryAddress['postal'] === '' && $member) {
            $primaryAddress['postal'] = (string) ($member['postal_code'] ?? '');
        }
        if ($primaryAddress['country'] === '' && $member) {
            $primaryAddress['country'] = (string) ($member['country'] ?? '');
        }

        if ($primaryEmail === '' && $user) {
            $primaryEmail = (string) ($user['email'] ?? '');
        }
        if (($primaryFirst === '' || $primaryLast === '') && $user && !empty($user['name'])) {
            $nameParts = preg_split('/\s+/', trim((string) $user['name']));
            if ($nameParts && count($nameParts) > 1) {
                $primaryLast = $primaryLast !== '' ? $primaryLast : array_pop($nameParts);
                $primaryFirst = $primaryFirst !== '' ? $primaryFirst : implode(' ', $nameParts);
            } elseif ($nameParts && $primaryFirst === '') {
                $primaryFirst = (string) $user['name'];
            }
        }

        if (!$user) {
            if (!Validator::required($primaryFirst) || !Validator::required($primaryLast) || !Validator::email($primaryEmail)) {
                json_response(['error' => 'Primary member details are required.'], 422);
            }
            if (!Validator::required($primaryAddress['line1']) || !Validator::required($primaryAddress['city']) || !Validator::required($primaryAddress['state']) || !Validator::required($primaryAddress['postal'])) {
                json_response(['error' => 'Address is required.'], 422);
            }
            $password = (string) ($body['password'] ?? '');
            if (strlen($password) < 8) {
                json_response(['error' => 'Password must be at least 8 characters.'], 422);
            }
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $primaryEmail]);
            if ($stmt->fetch()) {
                json_response(['error' => 'An account with this email already exists. Please log in.'], 409);
            }
            if (!MemberRepository::isEmailAvailable($primaryEmail)) {
                json_response(['error' => 'That email address is already linked to another member.'], 409);
            }

            $name = trim($primaryFirst . ' ' . $primaryLast);
            $stmt = $pdo->prepare('INSERT INTO users (member_id, name, email, password_hash, is_active, created_at) VALUES (NULL, :name, :email, :password_hash, 0, NOW())');
            $stmt->execute([
                'name' => $name,
                'email' => $primaryEmail,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $membershipUserId = (int) $pdo->lastInsertId();

            $memberType = $fullSelected ? 'FULL' : 'ASSOCIATE';
            $memberNumberBase = 0;
            $memberNumberSuffix = 0;
            $memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
            $associateSuffixStart = (int) SettingsService::getGlobal('membership.associate_suffix_start', 1);
            $stmt = $pdo->query('SELECT MAX(member_number_base) as max_base FROM members');
            $row = $stmt->fetch();
            $maxBase = (int) ($row['max_base'] ?? 0);
            $start = max($memberNumberStart, 1);
            $memberNumberBase = max($maxBase, $start - 1) + 1;
            if ($memberType === 'ASSOCIATE') {
                $memberNumberSuffix = max($associateSuffixStart, 1);
            }

            $stmt = $pdo->prepare('INSERT INTO members (user_id, member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, stripe_customer_id, first_name, last_name, email, phone, address_line1, address_line2, city, state, postal_code, country, created_at) VALUES (:user_id, :member_type, :status, :base, :suffix, NULL, NULL, NULL, :first_name, :last_name, :email, :phone, :address_line1, :address_line2, :city, :state, :postal, :country, NOW())');
            $stmt->execute([
                'user_id' => $membershipUserId,
                'member_type' => $memberType,
                'status' => 'PENDING',
                'base' => $memberNumberBase,
                'suffix' => $memberNumberSuffix,
                'first_name' => $primaryFirst,
                'last_name' => $primaryLast,
                'email' => $primaryEmail,
                'phone' => $primaryPhone !== '' ? $primaryPhone : null,
                'address_line1' => $primaryAddress['line1'],
                'address_line2' => $primaryAddress['line2'] ?: null,
                'city' => $primaryAddress['city'],
                'state' => $primaryAddress['state'],
                'postal' => $primaryAddress['postal'],
                'country' => $primaryAddress['country'] !== '' ? $primaryAddress['country'] : 'Australia',
            ]);
            $memberId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('UPDATE users SET member_id = :member_id WHERE id = :id');
            $stmt->execute(['member_id' => $memberId, 'id' => $membershipUserId]);
            $createdMember = true;
        } else {
            if (!Validator::required($primaryFirst) || !Validator::required($primaryLast) || !Validator::email($primaryEmail)) {
                json_response(['error' => 'Primary member details are required.'], 422);
            }
            if (!Validator::required($primaryAddress['line1']) || !Validator::required($primaryAddress['city']) || !Validator::required($primaryAddress['state']) || !Validator::required($primaryAddress['postal'])) {
                json_response(['error' => 'Address is required.'], 422);
            }

            if (!$memberId) {
                if (!MemberRepository::isEmailAvailable($primaryEmail)) {
                    json_response(['error' => 'That email address is already linked to another member.'], 409);
                }
                $memberType = $fullSelected ? 'FULL' : 'ASSOCIATE';
                $memberNumberBase = 0;
                $memberNumberSuffix = 0;
                $memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
                $associateSuffixStart = (int) SettingsService::getGlobal('membership.associate_suffix_start', 1);
                $stmt = $pdo->query('SELECT MAX(member_number_base) as max_base FROM members');
                $row = $stmt->fetch();
                $maxBase = (int) ($row['max_base'] ?? 0);
                $start = max($memberNumberStart, 1);
                $memberNumberBase = max($maxBase, $start - 1) + 1;
                if ($memberType === 'ASSOCIATE') {
                    $memberNumberSuffix = max($associateSuffixStart, 1);
                }

                $stmt = $pdo->prepare('INSERT INTO members (user_id, member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, stripe_customer_id, first_name, last_name, email, phone, address_line1, address_line2, city, state, postal_code, country, created_at) VALUES (:user_id, :member_type, :status, :base, :suffix, NULL, NULL, NULL, :first_name, :last_name, :email, :phone, :address_line1, :address_line2, :city, :state, :postal, :country, NOW())');
                $stmt->execute([
                    'user_id' => $membershipUserId,
                    'member_type' => $memberType,
                    'status' => 'PENDING',
                    'base' => $memberNumberBase,
                    'suffix' => $memberNumberSuffix,
                    'first_name' => $primaryFirst,
                    'last_name' => $primaryLast,
                    'email' => $primaryEmail,
                    'phone' => $primaryPhone !== '' ? $primaryPhone : null,
                    'address_line1' => $primaryAddress['line1'],
                    'address_line2' => $primaryAddress['line2'] ?: null,
                    'city' => $primaryAddress['city'],
                    'state' => $primaryAddress['state'],
                    'postal' => $primaryAddress['postal'],
                    'country' => $primaryAddress['country'] !== '' ? $primaryAddress['country'] : 'Australia',
                ]);
                $memberId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('UPDATE users SET member_id = :member_id WHERE id = :id');
                $stmt->execute(['member_id' => $memberId, 'id' => $membershipUserId]);
                $createdMember = true;
            } else {
                if (!MemberRepository::isEmailAvailable($primaryEmail, (int) $memberId)) {
                    json_response(['error' => 'That email address is already linked to another member.'], 409);
                }
                $stmt = $pdo->prepare('UPDATE members SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone, address_line1 = :address_line1, address_line2 = :address_line2, city = :city, state = :state, postal_code = :postal, country = :country, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    'first_name' => $primaryFirst,
                    'last_name' => $primaryLast,
                    'email' => $primaryEmail,
                    'phone' => $primaryPhone !== '' ? $primaryPhone : null,
                    'address_line1' => $primaryAddress['line1'],
                    'address_line2' => $primaryAddress['line2'] ?: null,
                    'city' => $primaryAddress['city'],
                    'state' => $primaryAddress['state'],
                    'postal' => $primaryAddress['postal'],
                    'country' => $primaryAddress['country'] !== '' ? $primaryAddress['country'] : 'Australia',
                    'id' => (int) $memberId,
                ]);
            }
        }

        if ($createdMember && $fullVehicles) {
            insert_member_bikes($pdo, (int) $memberId, $fullVehicles);
        }

        if (!$memberId) {
            json_response(['error' => 'Unable to locate member profile.'], 422);
        }

        $periodId = MembershipService::createMembershipPeriod((int) $memberId, $termLabel, date('Y-m-d'));

        $associateMemberId = null;
        $associatePeriodId = null;
        if ($associateSelected && $associateAdd === 'yes') {
            if (!Validator::required($associateFirst) || !Validator::required($associateLast)) {
                json_response(['error' => 'Associate member details are required.'], 422);
            }
            if (!Validator::email($associateEmail)) {
                json_response(['error' => 'A valid associate member email is required.'], 422);
            }
            if (!MemberRepository::isEmailAvailable($associateEmail)) {
                json_response(['error' => 'Associate member email is already linked to another member.'], 409);
            }
            $stmt = $pdo->prepare('SELECT MAX(member_number_suffix) as max_suffix FROM members WHERE full_member_id = :full_id');
            $stmt->execute(['full_id' => $memberId]);
            $row = $stmt->fetch();
            $maxSuffix = (int) ($row['max_suffix'] ?? 0);
            $suffixStart = (int) SettingsService::getGlobal('membership.associate_suffix_start', 1);
            $associateSuffix = max($maxSuffix, $suffixStart - 1) + 1;
            $baseNumber = $member ? (int) ($member['member_number_base'] ?? 0) : (int) (db()->query('SELECT member_number_base FROM members WHERE id = ' . (int) $memberId)->fetchColumn());

            $stmt = $pdo->prepare('INSERT INTO members (user_id, member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, stripe_customer_id, first_name, last_name, email, phone, address_line1, address_line2, city, state, postal_code, country, created_at) VALUES (NULL, "ASSOCIATE", "PENDING", :base, :suffix, :full_member_id, NULL, NULL, :first_name, :last_name, :email, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NOW())');
            $stmt->execute([
                'base' => $baseNumber,
                'suffix' => $associateSuffix,
                'full_member_id' => $memberId,
                'first_name' => $associateFirst,
                'last_name' => $associateLast,
                'email' => $associateEmail !== '' ? $associateEmail : $primaryEmail,
            ]);
            $associateMemberId = (int) $pdo->lastInsertId();
            $associatePeriodId = MembershipService::createMembershipPeriod($associateMemberId, $termLabel, date('Y-m-d'));
        }

        $internalNotes = json_encode([
            'associate_member_id' => $associateMemberId,
            'associate_period_id' => $associatePeriodId,
            'membership_term' => $termLabel,
            'membership_selection' => [
                'full_selected' => $fullSelected,
                'associate_selected' => $associateSelected,
                'associate_add' => $associateAdd,
            ],
            'vehicles' => [
                'full' => $fullVehicles,
                'associate' => $associateVehicles,
            ],
            'associate_details' => [
                'first_name' => $associateFirst,
                'last_name' => $associateLast,
                'email' => $associateEmail,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $order = MembershipOrderService::createMembershipOrder((int) $memberId, $periodId, 0, [
            'payment_method' => 'stripe',
            'payment_status' => 'pending',
            'fulfillment_status' => 'pending',
            'currency' => 'AUD',
            'items' => array_map(function ($item) use ($termLabel) {
                $label = $termLabel === '2Y' ? '24 month' : '12 month';
                $name = 'Membership ' . $label;
                return [
                    'product_id' => null,
                    'name' => $name,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => 0,
                    'is_physical' => 0,
                ];
            }, $items),
            'internal_notes' => $internalNotes,
            'user_id' => $membershipUserId,
        ]);
        if (!$order || empty($order['id'])) {
            json_response(['error' => 'Unable to create membership order.'], 500);
        }

        $customerId = null;
        if ($memberId) {
            $stmt = $pdo->prepare('SELECT stripe_customer_id FROM members WHERE id = :id');
            $stmt->execute(['id' => $memberId]);
            $customerId = $stmt->fetchColumn() ?: null;
        }
        if (!$customerId) {
            $existing = StripeService::findCustomerByEmail($primaryEmail);
            if ($existing) {
                $customerId = $existing['id'] ?? null;
            }
        }
        if (!$customerId) {
            $customer = StripeService::createCustomer(StripeSettingsService::getActiveSecretKey(), [
                'email' => $primaryEmail,
                'name' => trim($primaryFirst . ' ' . $primaryLast),
                'phone' => $primaryPhone !== '' ? $primaryPhone : null,
                'metadata' => [
                    'member_id' => (string) $memberId,
                    'user_id' => (string) $membershipUserId,
                ],
            ]);
            $customerId = $customer['id'] ?? null;
        }
        if (!$customerId) {
            json_response(['error' => 'Unable to create Stripe customer.'], 500);
        }
        $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :customer_id WHERE id = :id');
        $stmt->execute(['customer_id' => $customerId, 'id' => $memberId]);

        $subscription = StripeService::createSubscription([
            'customer' => $customerId,
            'items' => $items,
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => [
                'order_id' => (string) ($order['id'] ?? ''),
                'member_id' => (string) $memberId,
                'associate_member_id' => (string) ($associateMemberId ?? ''),
                'associate_period_id' => (string) ($associatePeriodId ?? ''),
                'order_type' => 'membership',
                'term' => $termLabel,
            ],
        ]);
        if (!$subscription || empty($subscription['latest_invoice']['payment_intent']['client_secret'])) {
            json_response(['error' => 'Unable to start membership payment.'], 500);
        }

        $paymentIntentId = $subscription['latest_invoice']['payment_intent']['id'] ?? '';
        $invoiceId = $subscription['latest_invoice']['id'] ?? '';
        $invoice = $subscription['latest_invoice'] ?? [];
        $totalCents = (int) ($invoice['amount_due'] ?? 0);
        if ($totalCents <= 0) {
            $totalCents = (int) ($invoice['total'] ?? 0);
        }
        $subtotalCents = (int) ($invoice['subtotal'] ?? $totalCents);
        $taxCents = (int) ($invoice['tax'] ?? 0);

        $stmt = $pdo->prepare('UPDATE orders SET subtotal = :subtotal, tax_total = :tax_total, total = :total, stripe_payment_intent_id = :payment_intent_id, stripe_subscription_id = :subscription_id, stripe_invoice_id = :invoice_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'subtotal' => $subtotalCents > 0 ? $subtotalCents / 100 : 0,
            'tax_total' => $taxCents > 0 ? $taxCents / 100 : 0,
            'total' => $totalCents > 0 ? $totalCents / 100 : 0,
            'payment_intent_id' => $paymentIntentId,
            'subscription_id' => $subscription['id'] ?? '',
            'invoice_id' => $invoiceId,
            'id' => (int) ($order['id'] ?? 0),
        ]);

        $lineItems = $invoice['lines']['data'] ?? [];
        if (!is_array($lineItems)) {
            $lineItems = [];
        }
        $orderItems = [];
        foreach ($lineItems as $line) {
            $amount = (int) ($line['amount'] ?? 0);
            if ($amount <= 0 || empty($line['price'])) {
                continue;
            }
            $quantity = (int) ($line['quantity'] ?? 1);
            $unitAmount = $line['price']['unit_amount'] ?? null;
            $unitPrice = $unitAmount !== null ? $unitAmount / 100 : ($amount / max(1, $quantity) / 100);
            $description = (string) ($line['description'] ?? '');
            $name = $description !== '' ? $description : (string) ($line['price']['nickname'] ?? 'Membership');
            $orderItems[] = [
                'product_id' => null,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'is_physical' => 0,
            ];
        }
        if ($orderItems) {
            $stmt = $pdo->prepare('DELETE FROM order_items WHERE order_id = :order_id');
            $stmt->execute(['order_id' => (int) ($order['id'] ?? 0)]);
            OrderService::insertItems((int) ($order['id'] ?? 0), $orderItems);
        }

        json_response([
            'client_secret' => $subscription['latest_invoice']['payment_intent']['client_secret'],
            'orderId' => $order['id'] ?? null,
            'subscriptionId' => $subscription['id'] ?? null,
        ]);
    }

    if (count($segments) === 2 && $segments[1] === 'webhook') {
        if ($method !== 'POST') {
            json_response(['error' => 'Method not allowed.'], 405);
        }
        handle_stripe_webhook_request($raw, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    }
}

if ($resource === 'menus') {
    if (count($segments) === 1) {
        if ($method === 'GET') {
            require_admin_json();
            json_response([
                'menus' => NavigationService::listMenus(),
                'locations' => NavigationService::listLocations(),
            ]);
        }
        if ($method === 'POST') {
            require_admin_json();
            require_csrf_json($body);
            $result = NavigationService::createMenu((string) ($body['name'] ?? ''));
            if (!empty($result['error'])) {
                json_response(['error' => $result['error']], 422);
            }
            if (!empty($body['location_key'])) {
                NavigationService::assignMenuToLocation((string) $body['location_key'], (int) $result['id']);
            }
            json_response(['menu' => $result], 201);
        }
    }
    if (count($segments) >= 2) {
        $menuId = (int) $segments[1];
        if ($menuId <= 0) {
            json_response(['error' => 'Invalid menu id.'], 400);
        }
        if (count($segments) === 2) {
            if ($method === 'PUT') {
                require_admin_json();
                require_csrf_json($body);
                $result = NavigationService::renameMenu($menuId, (string) ($body['name'] ?? ''));
                if (!empty($result['error'])) {
                    json_response(['error' => $result['error']], 422);
                }
                json_response(['menu' => $result]);
            }
            if ($method === 'DELETE') {
                require_admin_json();
                require_csrf_json($body);
                NavigationService::deleteMenu($menuId);
                json_response(['success' => true]);
            }
        }
        if (count($segments) === 3 && $segments[2] === 'items') {
            if ($method === 'GET') {
                require_admin_json();
                $items = NavigationService::getMenuItemsTree($menuId);
                json_response(['items' => $items]);
            }
            if ($method === 'PUT') {
                require_admin_json();
                require_csrf_json($body);
                $result = NavigationService::replaceMenuItemsTree($menuId, $body['items'] ?? []);
                if (!empty($result['error'])) {
                    json_response(['error' => $result['error']], 422);
                }
                json_response(['success' => true]);
            }
        }
    }
}

if ($resource === 'menu-locations') {
    if ($method === 'PUT') {
        require_admin_json();
        require_csrf_json($body);
        $locationKey = (string) ($body['location_key'] ?? '');
        $menuId = isset($body['menu_id']) && $body['menu_id'] !== '' ? (int) $body['menu_id'] : null;
        $result = NavigationService::assignMenuToLocation($locationKey, $menuId);
        if (!empty($result['error'])) {
            json_response(['error' => $result['error']], 422);
        }
        json_response(['location' => $result]);
    }
}

if ($resource === 'navigation') {
    if (count($segments) === 2 && $method === 'GET') {
        $locationKey = (string) $segments[1];
        $data = NavigationService::getNavigationTree($locationKey, current_user());
        json_response(['items' => $data]);
    }
}

if ($resource === 'checkout' && count($segments) >= 2 && $segments[1] === 'create-session') {
    if ($method !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $user = require_user_json();
    $orderType = (string) ($body['order_type'] ?? '');
    if (!in_array($orderType, ['membership', 'store'], true)) {
        json_response(['error' => 'Invalid order type.'], 422);
    }

    $channelCode = (string) ($body['channel_code'] ?? 'primary');
    $channel = PaymentSettingsService::getChannelByCode($channelCode);
    $settings = PaymentSettingsService::getSettingsByChannelId((int) $channel['id']);
    $secretKey = $settings['secret_key'] ?? '';
    if ($secretKey === '') {
        json_response(['error' => 'Stripe is not configured.'], 422);
    }
    if (!SettingsService::getGlobal('payments.stripe.checkout_enabled', true)) {
        json_response(['error' => 'Checkout is currently unavailable.'], 422);
    }

    if ($orderType === 'membership') {
        $amount = (float) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            json_response(['error' => 'Invalid membership amount.'], 422);
        }
        $membershipYear = isset($body['membership_year']) ? (int) $body['membership_year'] : (int) date('Y');
        $orderId = OrderService::createOrder([
            'user_id' => $user['id'],
            'status' => 'pending',
            'order_type' => 'membership',
            'currency' => 'AUD',
            'subtotal' => $amount,
            'tax_total' => 0,
            'shipping_total' => 0,
            'total' => $amount,
            'channel_id' => $channel['id'],
            'shipping_required' => 0,
        ], [
            [
                'product_id' => null,
                'name' => 'Membership ' . $membershipYear,
                'quantity' => 1,
                'unit_price' => $amount,
                'is_physical' => 0,
            ],
        ]);

        $successUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&success=1');
        $cancelUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&cancel=1');
        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user['email'] ?? '',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'aud',
                        'product_data' => ['name' => 'Membership ' . $membershipYear],
                        'unit_amount' => (int) round($amount * 100),
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'order_id' => (string) $orderId,
                'channel_id' => (string) $channel['id'],
                'order_type' => 'membership',
                'membership_year' => (string) $membershipYear,
                'member_id' => (string) ($user['member_id'] ?? ''),
            ],
        ];

        try {
            $session = StripeService::createCheckoutSessionForOrder($secretKey, $payload);
        } catch (Throwable $e) {
            json_response(['error' => 'Unable to start checkout.'], 500);
        }

        if (empty($session['id'])) {
            json_response(['error' => 'Unable to start checkout.'], 500);
        }
        OrderService::updateStripeSession($orderId, $session['id']);
        json_response(['session_url' => $session['url'] ?? null, 'session_id' => $session['id']]);
    }

    if ($orderType === 'store') {
        $settingsStore = store_get_settings();
        if (!empty($settingsStore['members_only']) && empty($user['member_id'])) {
            json_response(['error' => 'Members only.'], 403);
        }
        $cart = store_get_open_cart((int) $user['id']);
        $itemsStmt = db()->prepare('SELECT ci.*, p.type, p.event_name, p.track_inventory, p.stock_quantity, v.stock_quantity as variant_stock FROM store_cart_items ci JOIN store_products p ON p.id = ci.product_id LEFT JOIN store_product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = :cart_id');
        $itemsStmt->execute(['cart_id' => $cart['id']]);
        $items = $itemsStmt->fetchAll();
        if (!$items) {
            json_response(['error' => 'Your cart is empty.'], 422);
        }

        $pickupEnabled = (int) ($settingsStore['pickup_enabled'] ?? 0) === 1;
        $fulfillment = $body['fulfillment'] ?? 'shipping';
        if ($fulfillment !== 'pickup' && $fulfillment !== 'shipping') {
            $fulfillment = 'shipping';
        }

        $requiresShipping = false;
        foreach ($items as $item) {
            if (($item['type'] ?? 'physical') === 'physical') {
                $requiresShipping = true;
                break;
            }
        }
        if (!$requiresShipping) {
            $fulfillment = 'pickup';
        }
        if ($fulfillment === 'pickup' && $requiresShipping && !$pickupEnabled) {
            json_response(['error' => 'Pickup is not available.'], 422);
        }

        $address = [
            'name' => trim($body['shipping_name'] ?? ''),
            'line1' => trim($body['shipping_line1'] ?? ''),
            'line2' => trim($body['shipping_line2'] ?? ''),
            'city' => trim($body['shipping_city'] ?? ''),
            'state' => trim($body['shipping_state'] ?? ''),
            'postal' => trim($body['shipping_postal'] ?? ''),
            'country' => trim($body['shipping_country'] ?? 'Australia'),
        ];
        if ($requiresShipping && $fulfillment === 'shipping') {
            $country = strtolower($address['country']);
            if ($country !== 'australia' && $country !== 'au') {
                json_response(['error' => 'Shipping is available in Australia only.'], 422);
            }
            if ($address['line1'] === '' || $address['city'] === '' || $address['state'] === '' || $address['postal'] === '') {
                json_response(['error' => 'Shipping address is required.'], 422);
            }
        }

        $stockErrors = [];
        foreach ($items as $item) {
            if ((int) $item['track_inventory'] !== 1) {
                continue;
            }
            $available = $item['variant_id'] ? (int) ($item['variant_stock'] ?? 0) : (int) ($item['stock_quantity'] ?? 0);
            if ($available < (int) $item['quantity']) {
                $stockErrors[] = $item['title_snapshot'] . ' is out of stock.';
            }
        }
        if ($stockErrors) {
            json_response(['error' => implode(' ', $stockErrors)], 422);
        }

        $discount = null;
        if (!empty($cart['discount_code'])) {
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $result = store_validate_discount_code($cart['discount_code'], $subtotal);
            if (!empty($result['discount'])) {
                $discount = $result['discount'];
            }
        }

        $totals = store_calculate_cart_totals($items, $discount, $settingsStore, $fulfillment);
        $subtotalAfterDiscount = max(0.0, $totals['subtotal'] - $totals['discount_total']);
        $shippingAvailable = false;
        if ($requiresShipping) {
            $threshold = (float) ($settingsStore['shipping_free_threshold'] ?? 0);
            $flatRate = (float) ($settingsStore['shipping_flat_rate'] ?? 0);
            if (!empty($settingsStore['shipping_free_enabled']) && $threshold > 0 && $subtotalAfterDiscount >= $threshold) {
                $shippingAvailable = true;
            } elseif (!empty($settingsStore['shipping_flat_enabled']) && $flatRate > 0) {
                $shippingAvailable = true;
            }
        }
        if ($requiresShipping && $fulfillment === 'shipping' && !$shippingAvailable) {
            json_response(['error' => 'Shipping is not available for this order.'], 422);
        }

        $orderNumber = store_generate_order_number();

        $orderPayload = [
            'order_number' => $orderNumber,
            'user_id' => $user['id'],
            'member_id' => $user['member_id'] ?? null,
            'status' => 'pending',
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'shipping_total' => $totals['shipping_total'],
            'processing_fee_total' => $totals['processing_fee_total'],
            'total' => $totals['total'],
            'discount_code' => $cart['discount_code'] ?? null,
            'discount_id' => $discount['id'] ?? null,
            'fulfillment_method' => $fulfillment,
            'shipping_name' => $fulfillment === 'shipping' ? $address['name'] : null,
            'shipping_address_line1' => $fulfillment === 'shipping' ? $address['line1'] : null,
            'shipping_address_line2' => $fulfillment === 'shipping' ? $address['line2'] : null,
            'shipping_city' => $fulfillment === 'shipping' ? $address['city'] : null,
            'shipping_state' => $fulfillment === 'shipping' ? $address['state'] : null,
            'shipping_postal_code' => $fulfillment === 'shipping' ? $address['postal'] : null,
            'shipping_country' => $fulfillment === 'shipping' ? 'Australia' : null,
            'pickup_instructions_snapshot' => $fulfillment === 'pickup' ? ($settingsStore['pickup_instructions'] ?? '') : null,
            'customer_name' => $address['name'],
            'customer_email' => $user['email'] ?? '',
        ];

        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO store_orders (order_number, user_id, member_id, status, subtotal, discount_total, shipping_total, processing_fee_total, total, discount_code, discount_id, fulfillment_method, shipping_name, shipping_address_line1, shipping_address_line2, shipping_city, shipping_state, shipping_postal_code, shipping_country, pickup_instructions_snapshot, customer_name, customer_email, created_at) VALUES (:order_number, :user_id, :member_id, :status, :subtotal, :discount_total, :shipping_total, :processing_fee_total, :total, :discount_code, :discount_id, :fulfillment_method, :shipping_name, :shipping_address_line1, :shipping_address_line2, :shipping_city, :shipping_state, :shipping_postal_code, :shipping_country, :pickup_instructions_snapshot, :customer_name, :customer_email, NOW())');
        $stmt->execute($orderPayload);
        $storeOrderId = (int) $pdo->lastInsertId();

        $itemsWithDiscount = store_apply_discount_to_items($items, $totals['discount_total']);
        foreach ($itemsWithDiscount as $item) {
            $stmt = $pdo->prepare('INSERT INTO store_order_items (order_id, product_id, variant_id, title_snapshot, variant_snapshot, sku_snapshot, type, event_name_snapshot, quantity, unit_price, unit_price_final, line_total, created_at) VALUES (:order_id, :product_id, :variant_id, :title_snapshot, :variant_snapshot, :sku_snapshot, :type, :event_name_snapshot, :quantity, :unit_price, :unit_price_final, :line_total, NOW())');
            $stmt->execute([
                'order_id' => $storeOrderId,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'title_snapshot' => $item['title_snapshot'],
                'variant_snapshot' => $item['variant_snapshot'],
                'sku_snapshot' => $item['sku_snapshot'],
                'type' => $item['type'],
                'event_name_snapshot' => $item['event_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'unit_price_final' => $item['unit_price_final'],
                'line_total' => $item['line_total'],
            ]);
        }

        if ($discount) {
            $stmt = $pdo->prepare('INSERT INTO store_order_discounts (order_id, discount_id, code, type, value, amount, created_at) VALUES (:order_id, :discount_id, :code, :type, :value, :amount, NOW())');
            $stmt->execute([
                'order_id' => $storeOrderId,
                'discount_id' => $discount['id'],
                'code' => $discount['code'],
                'type' => $discount['type'],
                'value' => $discount['value'],
                'amount' => $totals['discount_total'],
            ]);
        }

        $orderSubtotal = max(0.0, $totals['subtotal'] - $totals['discount_total'] + $totals['processing_fee_total']);
        $orderItems = array_map(function ($item) {
            return [
                'product_id' => $item['product_id'],
                'name' => $item['title_snapshot'] . ($item['variant_snapshot'] ? ' (' . $item['variant_snapshot'] . ')' : ''),
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price_final'],
                'is_physical' => ($item['type'] ?? '') === 'physical' ? 1 : 0,
            ];
        }, $itemsWithDiscount);
        if ($totals['processing_fee_total'] > 0) {
            $orderItems[] = [
                'product_id' => null,
                'name' => 'Payment processing fee',
                'quantity' => 1,
                'unit_price' => (float) $totals['processing_fee_total'],
                'is_physical' => 0,
            ];
        }

        $orderId = OrderService::createOrder([
            'user_id' => $user['id'],
            'status' => 'pending',
            'order_type' => 'store',
            'currency' => 'AUD',
            'subtotal' => $orderSubtotal,
            'tax_total' => $totals['tax_total'] ?? 0,
            'shipping_total' => $totals['shipping_total'],
            'total' => $totals['total'],
            'channel_id' => $channel['id'],
            'shipping_required' => $requiresShipping ? 1 : 0,
            'shipping_address_json' => json_encode([
                'fulfillment' => $fulfillment,
                'shipping' => $fulfillment === 'shipping' ? $address : null,
                'pickup_instructions' => $fulfillment === 'pickup' ? ($settingsStore['pickup_instructions'] ?? '') : null,
                'store_order_id' => $storeOrderId,
                'store_order_number' => $orderNumber,
            ]),
        ], $orderItems);

        $lineItems = [];
        foreach ($itemsWithDiscount as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => $item['title_snapshot'] . ($item['variant_snapshot'] ? ' (' . $item['variant_snapshot'] . ')' : ''),
                    ],
                    'unit_amount' => (int) round($item['unit_price_final'] * 100),
                ],
                'quantity' => (int) $item['quantity'],
            ];
        }
        if ($totals['shipping_total'] > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => 'Shipping',
                    ],
                    'unit_amount' => (int) round($totals['shipping_total'] * 100),
                ],
                'quantity' => 1,
            ];
        }
        if (!empty($totals['tax_total'])) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => 'GST',
                    ],
                    'unit_amount' => (int) round($totals['tax_total'] * 100),
                ],
                'quantity' => 1,
            ];
        }
        if ($totals['processing_fee_total'] > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => 'Payment processing fee',
                    ],
                    'unit_amount' => (int) round($totals['processing_fee_total'] * 100),
                ],
                'quantity' => 1,
            ];
        }

        $successUrl = BaseUrlService::buildUrl('/store/orders/' . $orderNumber . '?success=1');
        $cancelUrl = BaseUrlService::buildUrl('/store/cart?cancel=1');
        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user['email'] ?? '',
            'line_items' => $lineItems,
            'metadata' => [
                'order_id' => (string) $orderId,
                'channel_id' => (string) $channel['id'],
                'order_type' => 'store',
                'store_order_id' => (string) $storeOrderId,
                'store_order_number' => (string) $orderNumber,
            ],
        ];

        try {
            $session = StripeService::createCheckoutSessionForOrder($secretKey, $payload);
        } catch (Throwable $e) {
            json_response(['error' => 'Unable to start checkout.'], 500);
        }
        if (empty($session['id'])) {
            json_response(['error' => 'Unable to start checkout.'], 500);
        }

        $stmt = $pdo->prepare('UPDATE store_orders SET stripe_session_id = :session_id WHERE id = :id');
        $stmt->execute(['session_id' => $session['id'], 'id' => $storeOrderId]);
        OrderService::updateStripeSession($orderId, $session['id']);
        $stmt = $pdo->prepare('UPDATE store_carts SET status = "converted", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $cart['id']]);

        json_response(['session_url' => $session['url'] ?? null, 'session_id' => $session['id']]);
    }
}

if ($resource === 'billing' && count($segments) >= 2 && $segments[1] === 'portal') {
    if ($method !== 'GET') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    if (!SettingsService::getGlobal('payments.stripe.customer_portal_enabled', false)) {
        json_response(['error' => 'Customer portal is disabled.'], 403);
    }
    $user = require_user_json();
    $channel = PaymentSettingsService::getChannelByCode('primary');
    $settings = PaymentSettingsService::getSettingsByChannelId((int) $channel['id']);
    $secretKey = $settings['secret_key'] ?? '';
    if ($secretKey === '') {
        json_response(['error' => 'Stripe is not configured.'], 422);
    }

    $pdo = db();
    $memberId = $user['member_id'] ?? null;
    if (!$memberId) {
        json_response(['error' => 'Members only.'], 403);
    }
    $customerId = null;
    if ($memberId) {
        $stmt = $pdo->prepare('SELECT stripe_customer_id FROM members WHERE id = :id');
        $stmt->execute(['id' => $memberId]);
        $row = $stmt->fetch();
        $customerId = $row['stripe_customer_id'] ?? null;
    }

    if (!$customerId) {
        $customer = StripeService::createCustomer($secretKey, [
            'email' => $user['email'] ?? null,
            'name' => $user['name'] ?? null,
            'metadata' => ['user_id' => (string) ($user['id'] ?? '')],
        ]);
        $customerId = $customer['id'] ?? null;
        if ($customerId && $memberId) {
            $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :customer_id WHERE id = :id');
            $stmt->execute(['customer_id' => $customerId, 'id' => $memberId]);
        }
    }

    if (!$customerId) {
        json_response(['error' => 'Unable to create Stripe customer.'], 500);
    }

    $baseUrl = SettingsService::getGlobal('site.base_url', '');
    $returnUrl = $baseUrl . '/member/index.php?page=billing';
    $portal = StripeService::createCustomerPortalSession($secretKey, [
        'customer' => $customerId,
        'return_url' => $returnUrl,
    ]);
    json_response(['url' => $portal['url'] ?? null]);
}

if ($resource === 'uploads' && count($segments) >= 2 && $segments[1] === 'image') {
    if ($method !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $user = require_user_json();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!Csrf::verify($token)) {
        json_response(['error' => 'Invalid CSRF token.'], 403);
    }
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_response(['error' => 'No file uploaded.'], 422);
    }

    $context = strtolower(trim((string) ($_POST['context'] ?? 'members')));
    $context = preg_replace('/[^a-z0-9_-]/', '', $context);
    if ($context === '') {
        $context = 'members';
    }

    $allowedTypes = SettingsService::getGlobal('media.allowed_types', []);
    if (!is_array($allowedTypes) || empty($allowedTypes)) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    }
    if (in_array($context, ['avatars', 'bikes'], true)) {
        $allowedTypes = array_values(array_filter($allowedTypes, function ($type) {
            return str_starts_with($type, 'image/');
        }));
    }

    $file = $_FILES['file'];
    if (!empty($file['error'])) {
        json_response(['error' => 'Upload failed.'], 422);
    }
    $maxUploadMb = (float) SettingsService::getGlobal('media.max_upload_mb', 10);
    if ($file['size'] > ($maxUploadMb * 1024 * 1024)) {
        json_response(['error' => 'File exceeds upload limit.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: ($file['type'] ?? '');
    if (!in_array($mime, $allowedTypes, true)) {
        json_response(['error' => 'Unsupported file type.'], 422);
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    $extension = $extensionMap[$mime] ?? 'bin';

    $baseDir = __DIR__ . '/../uploads';
    $subdir = in_array($context, ['avatars', 'bikes', 'notices', 'notifications'], true) ? $context : 'members';
    $targetDir = $baseDir . '/' . $subdir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        json_response(['error' => 'Unable to create upload directory.'], 500);
    }

    $safeName = $context . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        json_response(['error' => 'Unable to save upload.'], 500);
    }

    $type = $mime === 'application/pdf' ? 'pdf' : 'image';
    $url = '/uploads/' . $subdir . '/' . $safeName;
    MediaService::registerUpload([
        'path' => $url,
        'file_type' => $mime,
        'file_size' => (int) ($file['size'] ?? 0),
        'type' => $type,
        'title' => $file['name'] ?? $safeName,
        'uploaded_by_user_id' => (int) ($user['id'] ?? 0),
        'source_context' => $context,
    ]);
    json_response(['url' => $url, 'type' => $type]);
}

if ($resource === 'webhooks' && count($segments) >= 2 && $segments[1] === 'stripe') {
    if ($method !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    handle_stripe_webhook_request($raw, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
}

if ($resource === 'admin' && count($segments) >= 3 && $segments[1] === 'settings' && $segments[2] === 'stripe') {
    $user = require_admin_json();
    if (count($segments) === 3) {
        if ($method === 'GET') {
            json_response(stripe_admin_settings_response());
        }
        if ($method === 'PUT') {
            require_csrf_json($body);
            require_stepup_json($user);
            $current = StripeSettingsService::getSettings();
            $prices = $current['membership_prices'] ?? [];
            if (!is_array($prices)) {
                $prices = [];
            }
            $payload = [
                'stripe_use_test_mode' => array_key_exists('use_test_mode', $body) ? !empty($body['use_test_mode']) : (bool) ($current['use_test_mode'] ?? true),
                'stripe_test_publishable_key' => trim((string) ($body['test_publishable_key'] ?? '')),
                'stripe_test_secret_key' => trim((string) ($body['test_secret_key'] ?? '')),
                'stripe_live_publishable_key' => trim((string) ($body['live_publishable_key'] ?? '')),
                'stripe_live_secret_key' => trim((string) ($body['live_secret_key'] ?? '')),
                'stripe_webhook_secret' => trim((string) ($body['webhook_secret'] ?? '')),
                'stripe_checkout_enabled' => array_key_exists('checkout_enabled', $body) ? !empty($body['checkout_enabled']) : (bool) ($current['checkout_enabled'] ?? true),
                'stripe_allow_guest_checkout' => array_key_exists('allow_guest_checkout', $body) ? !empty($body['allow_guest_checkout']) : (bool) ($current['allow_guest_checkout'] ?? true),
                'stripe_require_shipping_for_physical' => array_key_exists('require_shipping_for_physical', $body) ? !empty($body['require_shipping_for_physical']) : (bool) ($current['require_shipping_for_physical'] ?? true),
                'stripe_digital_only_minimal' => array_key_exists('digital_only_minimal', $body) ? !empty($body['digital_only_minimal']) : (bool) ($current['digital_only_minimal'] ?? true),
                'stripe_enable_apple_pay' => array_key_exists('enable_apple_pay', $body) ? !empty($body['enable_apple_pay']) : (bool) ($current['enable_apple_pay'] ?? true),
                'stripe_enable_google_pay' => array_key_exists('enable_google_pay', $body) ? !empty($body['enable_google_pay']) : (bool) ($current['enable_google_pay'] ?? true),
                'stripe_enable_bnpl' => array_key_exists('enable_bnpl', $body) ? !empty($body['enable_bnpl']) : (bool) ($current['enable_bnpl'] ?? false),
                'stripe_send_receipts' => array_key_exists('send_receipts', $body) ? !empty($body['send_receipts']) : (bool) ($current['send_receipts'] ?? true),
                'stripe_save_invoice_refs' => array_key_exists('save_invoice_refs', $body) ? !empty($body['save_invoice_refs']) : (bool) ($current['save_invoice_refs'] ?? true),
                'stripe_customer_portal_enabled' => array_key_exists('customer_portal_enabled', $body) ? !empty($body['customer_portal_enabled']) : (bool) ($current['customer_portal_enabled'] ?? false),
                'membership_default_term' => array_key_exists('membership_default_term', $body) ? (string) ($body['membership_default_term'] ?? '') : (string) ($current['membership_default_term'] ?? '12M'),
                'membership_allow_both_types' => array_key_exists('membership_allow_both_types', $body) ? !empty($body['membership_allow_both_types']) : (bool) ($current['membership_allow_both_types'] ?? true),
                'price_full_12' => array_key_exists('price_full_12', $body) ? trim((string) ($body['price_full_12'] ?? '')) : (string) ($prices['FULL_12'] ?? ''),
                'price_associate_12' => array_key_exists('price_associate_12', $body) ? trim((string) ($body['price_associate_12'] ?? '')) : (string) ($prices['ASSOCIATE_12'] ?? ''),
                'price_full_24' => array_key_exists('price_full_24', $body) ? trim((string) ($body['price_full_24'] ?? '')) : (string) ($prices['FULL_24'] ?? ''),
                'price_associate_24' => array_key_exists('price_associate_24', $body) ? trim((string) ($body['price_associate_24'] ?? '')) : (string) ($prices['ASSOCIATE_24'] ?? ''),
                'price_full_1y' => array_key_exists('price_full_1y', $body) ? trim((string) ($body['price_full_1y'] ?? '')) : (string) ($prices['FULL_1Y'] ?? ''),
                'price_full_3y' => array_key_exists('price_full_3y', $body) ? trim((string) ($body['price_full_3y'] ?? '')) : (string) ($prices['FULL_3Y'] ?? ''),
                'price_associate_1y' => array_key_exists('price_associate_1y', $body) ? trim((string) ($body['price_associate_1y'] ?? '')) : (string) ($prices['ASSOCIATE_1Y'] ?? ''),
                'price_associate_3y' => array_key_exists('price_associate_3y', $body) ? trim((string) ($body['price_associate_3y'] ?? '')) : (string) ($prices['ASSOCIATE_3Y'] ?? ''),
                'price_life' => array_key_exists('price_life', $body) ? trim((string) ($body['price_life'] ?? '')) : (string) ($prices['LIFE'] ?? ''),
                'stripe_invoice_prefix' => array_key_exists('stripe_invoice_prefix', $body) ? trim((string) ($body['stripe_invoice_prefix'] ?? '')) : (string) SettingsService::getGlobal('payments.stripe.invoice_prefix', 'INV'),
                'stripe_invoice_email_template' => array_key_exists('stripe_invoice_email_template', $body) ? trim((string) ($body['stripe_invoice_email_template'] ?? '')) : (string) SettingsService::getGlobal('payments.stripe.invoice_email_template', ''),
                'stripe_generate_pdf' => array_key_exists('stripe_generate_pdf', $body) ? !empty($body['stripe_generate_pdf']) : (bool) SettingsService::getGlobal('payments.stripe.generate_pdf', false),
                'bank_transfer_instructions' => array_key_exists('bank_transfer_instructions', $body) ? trim((string) ($body['bank_transfer_instructions'] ?? '')) : (string) SettingsService::getGlobal('payments.bank_transfer_instructions', ''),
            ];

            $errors = [];
            StripeSettingsService::saveAdminSettings((int) $user['id'], $payload, $errors);
            if ($errors) {
                json_response(['error' => implode(' ', $errors)], 422);
            }
            json_response(stripe_admin_settings_response());
        }
        json_response(['error' => 'Method not allowed.'], 405);
    }

    if (count($segments) === 4 && $segments[3] === 'test-connection') {
        if ($method !== 'POST') {
            json_response(['error' => 'Method not allowed.'], 405);
        }
        require_csrf_json($body);
        require_stepup_json($user);
        $activeKeys = StripeSettingsService::getActiveKeys();
        if (empty($activeKeys['secret_key'])) {
            json_response(['error' => 'Stripe is not configured.'], 422);
        }
        $account = StripeService::retrieveAccount($activeKeys['secret_key']);
        if (!$account) {
            json_response(['error' => 'Unable to connect to Stripe.'], 422);
        }
        $accountId = (string) ($account['id'] ?? '');
        $accountName = (string) ($account['business_profile']['name'] ?? '');
        if ($accountName === '') {
            $accountName = (string) ($account['settings']['dashboard']['display_name'] ?? '');
        }
        json_response([
            'ok' => true,
            'mode' => $activeKeys['mode'] ?? 'test',
            'account' => [
                'name' => $accountName !== '' ? $accountName : 'Stripe Account',
                'id_last4' => $accountId !== '' ? substr($accountId, -4) : '',
            ],
        ]);
    }

    json_response(['error' => 'Not found.'], 404);
}

if ($resource === 'admin' && count($segments) >= 3 && $segments[1] === 'refunds' && $segments[2] === 'create') {
    if ($method !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $user = require_roles_json(['committee', 'treasurer', 'admin']);
    require_csrf_json($body);
    require_stepup_json($user);
    $orderId = (int) ($body['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_response(['error' => 'Order id is required.'], 422);
    }
    $order = OrderService::getOrderById($orderId);
    if (!$order) {
        json_response(['error' => 'Order not found.'], 404);
    }
    if (($order['status'] ?? '') !== 'paid') {
        json_response(['error' => 'Order is not paid.'], 422);
    }

    $settings = PaymentSettingsService::getSettingsByChannelId((int) $order['channel_id']);
    $secretKey = $settings['secret_key'] ?? '';
    if ($secretKey === '') {
        json_response(['error' => 'Stripe is not configured.'], 422);
    }

    $paymentIntentId = $order['stripe_payment_intent_id'] ?? '';
    if ($paymentIntentId === '') {
        json_response(['error' => 'Missing payment intent id.'], 422);
    }

    try {
        $refund = StripeService::createRefundWithParams($secretKey, [
            'payment_intent' => $paymentIntentId,
        ]);
    } catch (Throwable $e) {
        json_response(['error' => 'Refund failed.'], 500);
    }

    OrderService::markRefunded($orderId);
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO refunds (order_id, stripe_refund_id, refunded_by_user_id, refunded_at, reason, created_at) VALUES (:order_id, :stripe_refund_id, :refunded_by_user_id, NOW(), :reason, NOW())');
    $stmt->execute([
        'order_id' => $orderId,
        'stripe_refund_id' => $refund['id'] ?? '',
        'refunded_by_user_id' => $user['id'],
        'reason' => $body['reason'] ?? null,
    ]);

    if (($order['order_type'] ?? '') === 'membership') {
        $stmt = $pdo->prepare('UPDATE memberships SET status = "unpaid", updated_at = NOW() WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
    }
    if (!empty($order['stripe_payment_intent_id'])) {
        $stmt = $pdo->prepare('UPDATE store_orders SET status = "refunded", updated_at = NOW() WHERE stripe_payment_intent_id = :payment_intent_id');
        $stmt->execute(['payment_intent_id' => $order['stripe_payment_intent_id']]);
    }

    json_response(['success' => true, 'refund_id' => $refund['id'] ?? null]);
}

json_response(['error' => 'Not found.'], 404);
