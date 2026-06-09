<?php
/**
 * Admin debug + repair tool for the store_cart_items / store_carts state of
 * any user. Built to chase down the "checkout shows 18 ghost rows" bug.
 *
 * Usage:
 *   /admin/debug-cart.php                      — dump your own cart state
 *   /admin/debug-cart.php?user=123             — dump that user's cart state
 *   /admin/debug-cart.php?email=foo@bar.com    — same, by email
 *   /admin/debug-cart.php?...&consolidate=1    — force-consolidate open carts
 *   /admin/debug-cart.php?...&purge=1          — force-delete ghost rows
 *   /admin/debug-cart.php?...&consolidate=1&purge=1   — do both
 */

require_once __DIR__ . '/../../app/bootstrap.php';

require_permission('admin.settings.general.manage');

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

// Resolve which user we're inspecting.
$userId = (int) ($_GET['user'] ?? 0);
$email = trim((string) ($_GET['email'] ?? ''));

if ($userId === 0 && $email !== '') {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
    $stmt->execute(['e' => $email]);
    $userId = (int) ($stmt->fetchColumn() ?: 0);
}

if ($userId === 0) {
    $cu = current_user();
    $userId = (int) ($cu['id'] ?? 0);
}

if ($userId <= 0) {
    echo "No user resolved. Pass ?user=<id> or ?email=<email>, or log in.\n";
    exit;
}

$stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch();
$email = $userRow['email'] ?? '(unknown)';
$displayName = $userRow['name'] ?? '(unknown)';

echo "=== Cart debug — {$displayName} <{$email}> (user_id={$userId}) ===\n";
echo "Time: " . date('c') . "\n";
echo "Mode: dump\n";
echo "\n";

// Snapshot: every store_carts row for this user (any status).
$stmt = $pdo->prepare('SELECT * FROM store_carts WHERE user_id = :u ORDER BY id DESC');
$stmt->execute(['u' => $userId]);
$allCarts = $stmt->fetchAll();
$openCarts = array_filter($allCarts, function ($c) { return ($c['status'] ?? '') === 'open'; });

echo "Total carts ever:    " . count($allCarts) . "\n";
echo "Currently 'open':    " . count($openCarts) . "\n";
echo "\n";

foreach ($allCarts as $cart) {
    $cartId = (int) $cart['id'];
    $status = (string) ($cart['status'] ?? '');
    $created = (string) ($cart['created_at'] ?? '');
    $updated = (string) ($cart['updated_at'] ?? '');

    $itemStmt = $pdo->prepare('SELECT id, product_id, variant_id, quantity, unit_price, title_snapshot, created_at FROM store_cart_items WHERE cart_id = :c ORDER BY id ASC');
    $itemStmt->execute(['c' => $cartId]);
    $rows = $itemStmt->fetchAll();

    $ghostCount = 0;
    $realCount = 0;
    foreach ($rows as $r) {
        $qty = (int) ($r['quantity'] ?? 0);
        $title = trim((string) ($r['title_snapshot'] ?? ''));
        $price = (float) ($r['unit_price'] ?? 0);
        if ($qty <= 0 || $title === '' || $price <= 0) {
            $ghostCount++;
        } else {
            $realCount++;
        }
    }

    $marker = '   ';
    if ($status === 'open') {
        $marker = ($ghostCount > 0) ? ' ⚠ ' : ' ★ ';
    }

    echo "{$marker}Cart #{$cartId}  status={$status}  created={$created}  updated={$updated}\n";
    echo "    items: " . count($rows) . " total — real={$realCount}, ghost={$ghostCount}\n";

    foreach ($rows as $r) {
        $qty = (int) ($r['quantity'] ?? 0);
        $title = (string) ($r['title_snapshot'] ?? '');
        $price = (float) ($r['unit_price'] ?? 0);
        $pid = (int) ($r['product_id'] ?? 0);
        $vid = (int) ($r['variant_id'] ?? 0);
        $tag = ($qty <= 0 || trim($title) === '' || $price <= 0) ? '[GHOST]' : '[real ]';
        echo sprintf(
            "        %s row=%d  product=%d  variant=%d  qty=%d  price=%.2f  title=%s\n",
            $tag,
            (int) $r['id'],
            $pid,
            $vid,
            $qty,
            $price,
            ($title === '' ? '∅' : json_encode($title))
        );
    }
    echo "\n";
}

// What does store_get_open_cart actually return for this user RIGHT NOW?
echo "store_get_open_cart({$userId}) returns:\n";
try {
    $returned = store_get_open_cart($userId);
    echo "    cart_id={$returned['id']}, status={$returned['status']}, discount_code=" . ($returned['discount_code'] ?? 'NULL') . "\n";
} catch (\Throwable $e) {
    echo "    ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// Optional repair actions.
$didSomething = false;

if (isset($_GET['consolidate']) && $_GET['consolidate'] === '1') {
    echo "--- CONSOLIDATE: merging duplicate open carts ---\n";
    $stmt = $pdo->prepare('SELECT id FROM store_carts WHERE user_id = :u AND status = "open" ORDER BY id DESC');
    $stmt->execute(['u' => $userId]);
    $openIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($openIds) <= 1) {
        echo "Nothing to do — only " . count($openIds) . " open cart(s).\n";
    } else {
        $canonical = $openIds[0];
        echo "Canonical (newest) = #{$canonical}\n";
        foreach (array_slice($openIds, 1) as $dup) {
            // Move real items into canonical.
            $move = $pdo->prepare('UPDATE store_cart_items SET cart_id = :c WHERE cart_id = :d AND quantity > 0 AND title_snapshot IS NOT NULL AND title_snapshot != \'\'');
            $move->execute(['c' => $canonical, 'd' => $dup]);
            $moved = $move->rowCount();
            // Purge whatever's left.
            $purge = $pdo->prepare('DELETE FROM store_cart_items WHERE cart_id = :d');
            $purge->execute(['d' => $dup]);
            $purged = $purge->rowCount();
            // Mark duplicate abandoned.
            $close = $pdo->prepare('UPDATE store_carts SET status = "abandoned", updated_at = NOW() WHERE id = :d');
            $close->execute(['d' => $dup]);
            echo "  Cart #{$dup} → moved {$moved} real item(s) into #{$canonical}, deleted {$purged} ghost row(s), marked abandoned.\n";
        }
    }
    $didSomething = true;
    echo "\n";
}

if (isset($_GET['purge']) && $_GET['purge'] === '1') {
    echo "--- PURGE: deleting ghost rows from every cart for this user ---\n";
    $stmt = $pdo->prepare('SELECT id FROM store_carts WHERE user_id = :u');
    $stmt->execute(['u' => $userId]);
    $allIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $totalDeleted = 0;
    foreach ($allIds as $cid) {
        $del = $pdo->prepare('DELETE FROM store_cart_items WHERE cart_id = :c AND (quantity <= 0 OR unit_price <= 0 OR title_snapshot IS NULL OR title_snapshot = \'\')');
        $del->execute(['c' => $cid]);
        $n = $del->rowCount();
        if ($n > 0) {
            echo "  Cart #{$cid}: deleted {$n} ghost row(s)\n";
        }
        $totalDeleted += $n;
    }
    echo "Total ghost rows deleted: {$totalDeleted}\n";
    $didSomething = true;
    echo "\n";
}

// Run the SAME query checkout.php runs and dump the result.
echo "\n--- Running checkout-style items query against cart returned by store_get_open_cart ---\n";
try {
    $cart = store_get_open_cart($userId);
    $cartId = (int) $cart['id'];
    echo "Cart used: #{$cartId}\n";

    $sql = 'SELECT ci.*, p.type, p.event_name, p.track_inventory, p.stock_quantity, v.stock_quantity as variant_stock '
        . 'FROM store_cart_items ci '
        . 'JOIN store_products p ON p.id = ci.product_id '
        . 'LEFT JOIN store_product_variants v ON v.id = ci.variant_id '
        . 'WHERE ci.cart_id = :cart_id AND ci.quantity > 0 AND ci.title_snapshot IS NOT NULL AND ci.title_snapshot != \'\'';
    $s = $pdo->prepare($sql);
    $s->execute(['cart_id' => $cartId]);
    $rows = $s->fetchAll();
    echo "Filtered query returned: " . count($rows) . " row(s)\n";
    foreach ($rows as $r) {
        echo sprintf(
            "    qty=%d  price=%.2f  product=%d  variant=%d  title=%s\n",
            (int) $r['quantity'],
            (float) $r['unit_price'],
            (int) $r['product_id'],
            (int) ($r['variant_id'] ?? 0),
            json_encode($r['title_snapshot'])
        );
    }

    // Also run unfiltered to see if anything's hiding from the filter.
    $sql2 = 'SELECT ci.*, p.type FROM store_cart_items ci '
        . 'JOIN store_products p ON p.id = ci.product_id '
        . 'LEFT JOIN store_product_variants v ON v.id = ci.variant_id '
        . 'WHERE ci.cart_id = :cart_id';
    $s2 = $pdo->prepare($sql2);
    $s2->execute(['cart_id' => $cartId]);
    $rows2 = $s2->fetchAll();
    echo "Unfiltered same-JOIN query returned: " . count($rows2) . " row(s)\n";

    // Now fetch the live /checkout HTML and count <li> in the order summary aside.
    echo "\n--- Fetching /checkout/ and counting <li> in the aside ---\n";
    $cookies = '';
    foreach ($_COOKIE as $k => $v) {
        $cookies .= $k . '=' . $v . '; ';
    }
    $ch = curl_init('http://127.0.0.1/checkout/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Cookie: ' . trim($cookies)],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($html === false) {
        echo "curl error: {$err}\n";
    } else {
        $totalLi = substr_count($html, '<li');
        $asideStart = strpos($html, '<aside');
        $asideEnd = $asideStart !== false ? strpos($html, '</aside>', $asideStart) : false;
        $asideLi = 0;
        if ($asideStart !== false && $asideEnd !== false) {
            $asideChunk = substr($html, $asideStart, $asideEnd - $asideStart);
            $asideLi = substr_count($asideChunk, '<li');
        }
        echo "Response length: " . strlen($html) . " bytes\n";
        echo "Total <li in page: {$totalLi}\n";
        echo "<li inside first <aside>: {$asideLi}\n";
        $hasMarker = strpos($html, 'ci.title_snapshot') !== false;
        echo "Contains SQL string literal 'ci.title_snapshot' (should never): " . ($hasMarker ? 'YES' : 'no') . "\n";
        // Dump the first 600 chars inside the aside that contain the cart items
        if ($asideStart !== false) {
            $ulPos = strpos($html, '<ul class="divide-y', $asideStart);
            if ($ulPos !== false) {
                echo "\nFirst chunk of <ul class=\"divide-y\"...:\n";
                echo substr($html, $ulPos, 1200) . "\n";
            }
        }
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

if (!$didSomething) {
    echo "\nHint: add ?consolidate=1 to merge duplicate open carts, or ?purge=1 to delete ghost rows.\n";
}
