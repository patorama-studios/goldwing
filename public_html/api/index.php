<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\NavigationService;
use App\Services\OrderService;
use App\Services\PaymentSettingsService;
use App\Services\PaymentWebhookService;
use App\Services\StepUpService;
use App\Services\StripeService;
use App\Services\SettingsService;
use App\Services\BaseUrlService;

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
    json_response(['url' => $url, 'type' => $type]);
}

if ($resource === 'webhooks' && count($segments) >= 2 && $segments[1] === 'stripe') {
    if ($method !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $payload = $raw;
    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $channel = PaymentSettingsService::getChannelByCode('primary');
    $settings = PaymentSettingsService::getSettingsByChannelId((int) $channel['id']);
    $secret = $settings['webhook_secret'] ?? '';

    $event = StripeService::constructEvent($payload, $signature, $secret);
    if (!$event) {
        PaymentSettingsService::updateWebhookStatus((int) $channel['id'], 'Invalid signature');
        json_response(['error' => 'Invalid signature.'], 400);
    }

    $isNew = PaymentWebhookService::recordEvent($event);
    if (!$isNew) {
        json_response(['received' => true]);
    }

    $eventId = $event['id'] ?? '';
    try {
        if (($event['type'] ?? '') === 'checkout.session.completed') {
            PaymentWebhookService::handleCheckoutCompleted($event, (int) $channel['id']);
        }
        if (($event['type'] ?? '') === 'charge.refunded') {
            PaymentWebhookService::handleChargeRefunded($event);
        }
        if (in_array($event['type'] ?? '', ['payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
            PaymentWebhookService::handlePaymentFailed($event);
        }
        PaymentWebhookService::markProcessed($eventId, 'processed', null);
        PaymentSettingsService::updateWebhookStatus((int) $channel['id'], null);
    } catch (Throwable $e) {
        PaymentWebhookService::markProcessed($eventId, 'failed', $e->getMessage());
        PaymentSettingsService::updateWebhookStatus((int) $channel['id'], $e->getMessage());
        json_response(['error' => 'Webhook processing failed.'], 500);
    }

    json_response(['received' => true]);
}

if ($resource === 'admin' && count($segments) >= 3 && $segments[1] === 'refunds' && $segments[2] === 'create') {
    if ($method !== 'POST') {
        json_response(['error' => 'Method not allowed.'], 405);
    }
    $user = require_roles_json(['committee', 'treasurer', 'admin', 'super_admin']);
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
