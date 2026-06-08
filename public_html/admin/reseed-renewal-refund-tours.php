<?php
/**
 * One-off re-seed for two tours after the renewal + payments-dashboard redesign:
 *   - member-pay-fees   (replaced 4 steps with 8: now walks the renewal lightbox)
 *   - admin-action-refund   (new, 7 steps: walks the Payments dashboard refund flow)
 *
 * Why this exists: Migration 018 used INSERT IGNORE so it won't update existing
 * tours after the seed file is re-edited. This script wipes + re-inserts the two
 * affected slugs only, leaving the other 14 tours untouched.
 *
 * Gate: must be logged in as hi@patorama.com.au.
 *
 * Delete this file after running.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Database;

header('Content-Type: text/plain; charset=utf-8');

require_login();

$TARGET_EMAIL = 'hi@patorama.com.au';
$user = current_user();
$sessionEmail = strtolower((string) ($user['email'] ?? ''));
if ($sessionEmail !== $TARGET_EMAIL) {
    http_response_code(403);
    echo "Forbidden. Run while logged in as {$TARGET_EMAIL}.\n";
    echo "Current session: " . ($sessionEmail !== '' ? $sessionEmail : '(none)') . "\n";
    exit;
}

echo "=== Re-seed renewal + refund tours ===\n";
echo "Time: " . date('c') . "\n\n";

$pdo = Database::connection();

$seedPath = __DIR__ . '/../../config/tour-steps-seed.json';
if (!is_file($seedPath)) {
    echo "ERROR: seed file not found at $seedPath\n";
    exit;
}
$seedData = json_decode((string) file_get_contents($seedPath), true);
if (!is_array($seedData)) {
    echo "ERROR: seed file is not valid JSON\n";
    exit;
}

$slugs = ['member-pay-fees', 'admin-action-refund'];

$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM tour_steps WHERE tour_slug = :slug');
    $ins = $pdo->prepare(
        'INSERT INTO tour_steps (tour_slug, step_index, element_selector, title, description, side, align, has_draft)
         VALUES (:slug, :idx, :sel, :title, :desc, :side, :align, 0)'
    );

    foreach ($slugs as $slug) {
        if (!isset($seedData[$slug]) || !is_array($seedData[$slug])) {
            echo "  - $slug: NOT IN SEED FILE — skipped\n";
            continue;
        }
        $del->execute(['slug' => $slug]);
        $deleted = $del->rowCount();
        $inserted = 0;
        foreach ($seedData[$slug] as $i => $step) {
            $popover = is_array($step['popover'] ?? null) ? $step['popover'] : [];
            $ins->execute([
                'slug'  => $slug,
                'idx'   => (int) $i,
                'sel'   => (string) ($step['element'] ?? ''),
                'title' => (string) ($popover['title'] ?? ''),
                'desc'  => (string) ($popover['description'] ?? ''),
                'side'  => (string) ($popover['side'] ?? 'bottom'),
                'align' => (string) ($popover['align'] ?? 'start'),
            ]);
            $inserted++;
        }
        echo "  - $slug: deleted $deleted, inserted $inserted\n";
    }

    $pdo->commit();
    echo "\nDone. Delete this file when you're sure the tours look right.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit;
}
