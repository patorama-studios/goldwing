<?php
/**
 * Admin store-test diagnostic.
 *
 * Read-only dump of the state the public store / checkout pages would see
 * for a given user. Helps the owner reproduce "I can't order anything" reports
 * without impersonating the user or risking real payments.
 *
 * Conventions copied from diagnose-stripe-order.php:
 *   - require_permission('admin.members.view')
 *   - header('Content-Type: text/plain; charset=utf-8')
 *   - plain-text output with "--- N. Heading ---" section markers
 *
 * Usage:
 *   /admin/store-test.php?user=eightysix86@hotmail.com
 *   /admin/store-test.php?user=15
 *
 * TODO (out of scope for this batch):
 *   - Real Stripe test-mode dummy checkout for the selected user.
 *   - Session impersonation / "view-as" support.
 *   - Auto-fix tools (e.g. one-click add missing shipping address to member).
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;
use App\Services\StripeSettingsService;

require_permission('admin.members.view');

header('Content-Type: text/plain; charset=utf-8');

$rawUser = isset($_GET['user']) ? trim((string) $_GET['user']) : '';

echo "=== Store Checkout Diagnostic (read-only) ===\n";
echo "Time:  " . date('c') . "\n";
echo "Query: ?user=" . ($rawUser !== '' ? $rawUser : '(none)') . "\n\n";

if ($rawUser === '') {
    echo "Provide ?user=<email> or ?user=<user_id>.\n";
    echo "Examples:\n";
    echo "  /admin/store-test.php?user=eightysix86@hotmail.com\n";
    echo "  /admin/store-test.php?user=15\n";
    exit;
}

$pdo = Database::connection();

/* --------------------------------------------------------------------------
 * 1) Resolve the user row from email or numeric id.
 * ------------------------------------------------------------------------ */
echo "--- 1. Resolve user ---\n";

if (ctype_digit($rawUser)) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $rawUser]);
} else {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $rawUser]);
}
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "No `users` row found for: {$rawUser}\n";
    echo "Cannot continue.\n";
    exit;
}

foreach (['id', 'email', 'name', 'member_id', 'is_active', 'created_at', 'updated_at'] as $k) {
    if (!array_key_exists($k, $user)) {
        continue;
    }
    $v = $user[$k];
    echo str_pad($k, 16) . ': ' . (is_null($v) ? '(null)' : (string) $v) . "\n";
}
echo "\n";

/* --------------------------------------------------------------------------
 * 2) Roles (joined via user_roles -> roles).
 * ------------------------------------------------------------------------ */
echo "--- 2. Roles ---\n";
$roleNames = [];
try {
    $stmt = $pdo->prepare('SELECT r.id, r.name, r.is_active FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :uid');
    $stmt->execute([':uid' => $user['id']]);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$roles) {
        echo "(no roles)\n";
    } else {
        foreach ($roles as $r) {
            $roleNames[] = $r['name'];
            echo "  - {$r['name']} (id={$r['id']}, is_active=" . ($r['is_active'] ?? '?') . ")\n";
        }
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
$isAdmin = in_array('admin', $roleNames, true);
echo "Is admin role: " . ($isAdmin ? 'yes' : 'no') . "\n\n";

/* --------------------------------------------------------------------------
 * 3) Member row (the store form pulls shipping defaults from here).
 * ------------------------------------------------------------------------ */
echo "--- 3. Member row ---\n";
$member = null;
if (!empty($user['member_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user['member_id']]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$member) {
    echo "(user has no linked member row)\n";
} else {
    $memberFields = [
        'id', 'status', 'member_type', 'first_name', 'last_name', 'email', 'phone',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'stripe_customer_id', 'chapter_id', 'created_at',
    ];
    foreach ($memberFields as $k) {
        if (!array_key_exists($k, $member)) {
            continue;
        }
        $v = $member[$k];
        echo str_pad($k, 20) . ': ' . (is_null($v) ? '(null)' : (string) $v) . "\n";
    }
}
echo "\n";

/* --------------------------------------------------------------------------
 * 4) Stripe settings (primary account) the public checkout consults.
 * ------------------------------------------------------------------------ */
echo "--- 4. Stripe settings (primary) ---\n";
$stripeSettings = StripeSettingsService::getSettings(StripeSettingsService::ACCOUNT_PRIMARY);
$stripeActive = StripeSettingsService::getActiveKeys(StripeSettingsService::ACCOUNT_PRIMARY);
$checkoutEnabled = (bool) ($stripeSettings['checkout_enabled'] ?? false);
$allowGuest = (bool) ($stripeSettings['allow_guest_checkout'] ?? false);
$requireShipping = (bool) ($stripeSettings['require_shipping_for_physical'] ?? true);
$secret = (string) ($stripeActive['secret_key'] ?? '');
$secretPrefix = $secret !== '' ? substr($secret, 0, 8) . '...(' . strlen($secret) . ' chars)' : '(empty)';
echo "checkout_enabled:              " . ($checkoutEnabled ? 'true' : 'false') . "\n";
echo "allow_guest_checkout:          " . ($allowGuest ? 'true' : 'false') . "\n";
echo "require_shipping_for_physical: " . ($requireShipping ? 'true' : 'false') . "\n";
echo "Active mode:                   " . ($stripeActive['mode'] ?? '?') . "\n";
echo "Active secret:                 {$secretPrefix}\n\n";

/* --------------------------------------------------------------------------
 * 5) Open cart + items for the user.
 * ------------------------------------------------------------------------ */
echo "--- 5. Open cart(s) ---\n";
$stmt = $pdo->prepare('SELECT id, status, discount_code, created_at, updated_at FROM store_carts WHERE user_id = :uid ORDER BY id DESC');
$stmt->execute([':uid' => $user['id']]);
$carts = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$carts) {
    echo "(no store_carts rows for this user)\n";
} else {
    foreach ($carts as $c) {
        echo "  cart_id={$c['id']} status={$c['status']} discount=" . ($c['discount_code'] ?: '(none)')
            . " created={$c['created_at']} updated=" . ($c['updated_at'] ?: '(null)') . "\n";
    }
}
echo "\n";

$openCart = null;
foreach ($carts as $c) {
    if (($c['status'] ?? '') === 'open') {
        $openCart = $c;
        break;
    }
}

$items = [];
$requiresShipping = false;
$cartSubtotal = 0.0;
if ($openCart) {
    echo "--- 5b. Items in open cart (id={$openCart['id']}) ---\n";
    $stmt = $pdo->prepare('SELECT ci.id, ci.product_id, ci.variant_id, ci.quantity, ci.unit_price, ci.title_snapshot, ci.variant_snapshot, p.type, p.is_active AS product_active, p.track_inventory, p.stock_quantity, v.stock_quantity AS variant_stock FROM store_cart_items ci LEFT JOIN store_products p ON p.id = ci.product_id LEFT JOIN store_product_variants v ON v.id = ci.variant_id WHERE ci.cart_id = :cid');
    $stmt->execute([':cid' => $openCart['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        echo "(cart is empty)\n";
    } else {
        foreach ($items as $it) {
            $line = (float) $it['unit_price'] * (int) $it['quantity'];
            $cartSubtotal += $line;
            if (($it['type'] ?? 'physical') === 'physical') {
                $requiresShipping = true;
            }
            echo "  item_id={$it['id']} qty={$it['quantity']} unit=\${$it['unit_price']} line=\$"
                . number_format($line, 2) . " type=" . ($it['type'] ?? '?')
                . " product_active=" . ($it['product_active'] ?? '?')
                . " '" . ($it['title_snapshot'] ?? '') . "'"
                . ($it['variant_snapshot'] ? " (" . $it['variant_snapshot'] . ")" : '')
                . "\n";
        }
        echo "Subtotal:           $" . number_format($cartSubtotal, 2) . "\n";
        echo "Requires shipping:  " . ($requiresShipping ? 'yes' : 'no') . "\n";
    }
    echo "\n";
}

/* --------------------------------------------------------------------------
 * 6) Member shipping completeness (matches the JS validator on /checkout
 *    and the server-side validator on /store/checkout).
 * ------------------------------------------------------------------------ */
echo "--- 6. Shipping address completeness (from member row) ---\n";
$shipFields = ['address_line1', 'city', 'state', 'postal_code', 'country'];
$shipMissing = [];
foreach ($shipFields as $f) {
    $val = trim((string) ($member[$f] ?? ''));
    $status = $val !== '' ? 'OK     ' : 'MISSING';
    echo "  {$status} {$f}: " . ($val !== '' ? $val : '(empty)') . "\n";
    if ($val === '') {
        $shipMissing[] = $f;
    }
}
$memberHasShipping = $member && count($shipMissing) < count($shipFields)
    && trim((string) ($member['address_line1'] ?? '')) !== ''
    && trim((string) ($member['city'] ?? '')) !== ''
    && trim((string) ($member['state'] ?? '')) !== ''
    && trim((string) ($member['postal_code'] ?? '')) !== '';
echo "Has full shipping address: " . ($memberHasShipping ? 'yes' : 'NO (missing: ' . implode(', ', $shipMissing) . ')') . "\n\n";

/* --------------------------------------------------------------------------
 * 7) Gate checklist — the actual pass/fail mirrors what the public pages
 *    and the JS validator on /checkout enforce.
 * ------------------------------------------------------------------------ */
echo "--- 7. Checkout gate checklist ---\n";

$mark = static fn(bool $pass): string => $pass ? '[PASS]' : '[FAIL]';

$gates = [];

$gates[] = [
    'pass'  => true, // we resolved a user above or we'd have exited
    'label' => 'users row exists',
    'note'  => "id={$user['id']}",
];

$gates[] = [
    'pass'  => (int) ($user['is_active'] ?? 0) === 1,
    'label' => 'users.is_active = 1',
    'note'  => 'disabled accounts cannot log in',
];

$gates[] = [
    'pass'  => !empty($user['member_id']),
    'label' => 'users.member_id set',
    'note'  => '/store/checkout.php: "Store checkout is available to members only"',
];

$gates[] = [
    'pass'  => $member && strtoupper((string) ($member['status'] ?? '')) === 'ACTIVE',
    'label' => 'members.status = ACTIVE',
    'note'  => 'status=' . ($member['status'] ?? '(no member)'),
];

$gates[] = [
    'pass'  => $checkoutEnabled,
    'label' => 'Stripe checkout_enabled',
    'note'  => 'payments.stripe.checkout_enabled flag',
];

$gates[] = [
    'pass'  => $allowGuest || !empty($user['member_id']),
    'label' => 'allow_guest_checkout OR user is member',
    'note'  => 'allow_guest_checkout=' . ($allowGuest ? 'true' : 'false'),
];

$gates[] = [
    'pass'  => $openCart !== null,
    'label' => 'Has open store_carts row',
    'note'  => $openCart ? "cart_id={$openCart['id']}" : 'will auto-create on visit',
];

$gates[] = [
    'pass'  => !empty($items),
    'label' => 'Cart has at least one item',
    'note'  => 'items=' . count($items),
];

$gates[] = [
    'pass'  => !$requiresShipping || $memberHasShipping,
    'label' => 'Shipping address present (if required)',
    'note'  => $requiresShipping
        ? ($memberHasShipping ? 'has address' : 'missing: ' . implode(', ', $shipMissing))
        : '(cart has no physical items)',
];

$gates[] = [
    'pass'  => $secret !== '',
    'label' => 'Stripe secret key present (active mode)',
    'note'  => 'mode=' . ($stripeActive['mode'] ?? '?'),
];

$gates[] = [
    'pass'  => true,
    'label' => 'Admin role does NOT block store (informational)',
    'note'  => $isAdmin ? 'user has admin role — store still works, but /checkout shows extra link to store settings' : 'not admin',
];

foreach ($gates as $g) {
    echo "  " . $mark($g['pass']) . " " . $g['label'] . " — " . $g['note'] . "\n";
}

$failed = array_filter($gates, static fn($g) => !$g['pass']);
echo "\n";
echo "Failed gates: " . count($failed) . " / " . count($gates) . "\n";
if ($failed) {
    echo "Likely blockers:\n";
    foreach ($failed as $g) {
        echo "  * " . $g['label'] . " — " . $g['note'] . "\n";
    }
}
echo "\n";

/* --------------------------------------------------------------------------
 * 8) Recent orders by this user (both tables).
 * ------------------------------------------------------------------------ */
echo "--- 8a. Recent `orders` rows (last 5) ---\n";
try {
    $stmt = $pdo->prepare('SELECT id, order_number, order_type, status, payment_status, total, stripe_session_id, created_at FROM orders WHERE user_id = :uid ORDER BY id DESC LIMIT 5');
    $stmt->execute([':uid' => $user['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "(none)\n";
    } else {
        foreach ($rows as $r) {
            $sess = $r['stripe_session_id'] ? substr($r['stripe_session_id'], 0, 16) . '...' : '(null)';
            echo "  id={$r['id']} order_number=" . ($r['order_number'] ?: '(null)')
                . " type={$r['order_type']} status={$r['status']} pay={$r['payment_status']}"
                . " total={$r['total']} session={$sess} created={$r['created_at']}\n";
        }
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

echo "--- 8b. Recent `store_orders` rows (last 5) ---\n";
try {
    $stmt = $pdo->prepare('SELECT id, order_number, status, total, stripe_session_id, created_at FROM store_orders WHERE user_id = :uid ORDER BY id DESC LIMIT 5');
    $stmt->execute([':uid' => $user['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "(none)\n";
    } else {
        foreach ($rows as $r) {
            $sess = $r['stripe_session_id'] ? substr($r['stripe_session_id'], 0, 16) . '...' : '(null)';
            echo "  id={$r['id']} order_number={$r['order_number']} status={$r['status']}"
                . " total={$r['total']} session={$sess} created={$r['created_at']}\n";
        }
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

/* --------------------------------------------------------------------------
 * 9) Helpful next-step URLs. We DO NOT impersonate or hit these for them.
 * ------------------------------------------------------------------------ */
echo "--- 9. Next steps (do not impersonate; ask the user) ---\n";
echo "Ask the user (while logged in as themselves) to visit these and tell you exactly what they see:\n";
echo "  /store              — store landing; can they see products and 'Add to cart'?\n";
echo "  /store/cart         — cart page; does it list items and a 'Proceed to checkout' button?\n";
echo "  /checkout           — checkout form; what's the visible alert / disabled state?\n";
echo "  /member/index.php   — member profile; if shipping fields are blank ask them to fill them in.\n";
echo "\n";
echo "Admin-side spots to compare against:\n";
echo "  /admin/members/edit.php?id=" . ($user['member_id'] ?: '<none>') . "\n";
echo "  /admin/diagnose-stripe-order.php?order=<order_number>  (for any recent failed order)\n";
echo "\n=== End ===\n";
