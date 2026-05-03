<?php
/**
 * One-time migration runner for admin use.
 * Visit this URL while logged in as admin to apply pending DB migrations.
 * Safe to run multiple times — each migration checks before applying.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\SettingsService;

// Require admin login
$user = current_user();
if (!$user || empty($user['id'])) {
    http_response_code(403);
    die('Not authorised. Please log in as admin first.');
}
if (!function_exists('current_admin_can') || !current_admin_can('admin.settings.general.manage', $user)) {
    http_response_code(403);
    die('Not authorised. Admin settings permission required.');
}

$results = [];

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 001 — Fix member_set_password notification template
// Replaces the old bare-bones "Set your password" content with the
// full welcome email copy.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_001_welcome_email_template';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 001 — Welcome email template', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    $catalog = SettingsService::getGlobal('notifications.catalog', []);
    if (!is_array($catalog)) {
        $catalog = [];
    }

    $catalog['member_set_password'] = array_merge(
        $catalog['member_set_password'] ?? [],
        [
            'subject' => "Welcome to the Australian Goldwing Association \xe2\x80\x94 let\xe2\x80\x99s get you set up!",
            'body'    => "<p>G'day {{first_name}},</p><p>Welcome to the Australian Goldwing Association\xe2\x80\x99s member portal \xe2\x80\x94 we\xe2\x80\x99re thrilled to have you on board with us!</p><p>Before you can log in for the first time, you\xe2\x80\x99ll need to create your password. Press the button below to get started:</p><p><a href=\"{{reset_link}}\" style=\"display:inline-block;padding:12px 28px;background:#0055ff;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;\">Set Your Password</a></p><p style=\"color:#6b7280;font-size:13px;\">Press this button and follow the instructions.</p>",
        ]
    );

    SettingsService::setGlobal((int) $user['id'], 'notifications.catalog', $catalog);
    SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);

    $results[] = ['label' => 'Migration 001 — Welcome email template', 'status' => 'applied', 'note' => 'Subject and body updated in notifications.catalog.'];
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 002 — Notification Hub schema
// Adds approval workflow to notices + calendar_events, adds feedback_message
// to existing approval tables, seeds the webmaster role.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_002_notification_hub';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 002 — Notification hub schema', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    $pdo = db();
    $applied = [];
    $errors = [];

    $tryExec = function (string $sql, string $label) use ($pdo, &$applied, &$errors) {
        try {
            $pdo->exec($sql);
            $applied[] = $label;
        } catch (Throwable $e) {
            // Ignore "duplicate column" / "duplicate key" errors so the migration is idempotent
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate') !== false || stripos($msg, 'exists') !== false) {
                $applied[] = $label . ' (already present)';
            } else {
                $errors[] = $label . ': ' . $msg;
            }
        }
    };

    // notices: approval columns
    $tryExec(
        "ALTER TABLE notices ADD COLUMN status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_pinned",
        'notices.status'
    );
    $tryExec(
        "ALTER TABLE notices ADD COLUMN feedback_message TEXT NULL AFTER status",
        'notices.feedback_message'
    );
    $tryExec(
        "ALTER TABLE notices ADD COLUMN reviewed_by INT NULL AFTER feedback_message",
        'notices.reviewed_by'
    );
    $tryExec(
        "ALTER TABLE notices ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by",
        'notices.reviewed_at'
    );
    $tryExec(
        "ALTER TABLE notices ADD INDEX idx_notices_status (status)",
        'notices status index'
    );

    // calendar_events: extend status + add review columns
    $tryExec(
        "ALTER TABLE calendar_events MODIFY COLUMN status ENUM('draft','pending','approved','rejected','published','cancelled') NOT NULL DEFAULT 'pending'",
        'calendar_events.status enum'
    );
    $tryExec(
        "ALTER TABLE calendar_events ADD COLUMN feedback_message TEXT NULL",
        'calendar_events.feedback_message'
    );
    $tryExec(
        "ALTER TABLE calendar_events ADD COLUMN reviewed_by INT NULL",
        'calendar_events.reviewed_by'
    );
    $tryExec(
        "ALTER TABLE calendar_events ADD COLUMN reviewed_at DATETIME NULL",
        'calendar_events.reviewed_at'
    );
    // Note: existing 'published' rows are intentionally left alone.
    // Calendar render code filters WHERE status = 'published', so the
    // notification hub treats 'published' as the approved/live state.

    // existing approval tables: add feedback_message
    $tryExec("ALTER TABLE fallen_wings ADD COLUMN feedback_message TEXT NULL", 'fallen_wings.feedback_message');
    $tryExec("ALTER TABLE chapter_change_requests ADD COLUMN feedback_message TEXT NULL", 'chapter_change_requests.feedback_message');
    $tryExec("ALTER TABLE member_of_year_nominations ADD COLUMN feedback_message TEXT NULL", 'member_of_year_nominations.feedback_message');

    // Seed request hub permissions for admin (webmaster role removed by migration 003)
    $permissionSeeds = [
        'admin' => ['admin.requests.view', 'admin.requests.action'],
    ];
    try {
        $permStmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :permission_key, 1, NOW(), NOW() FROM roles r WHERE r.name = :role_name
             ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
        );
        $seededCount = 0;
        foreach ($permissionSeeds as $roleName => $keys) {
            foreach ($keys as $permKey) {
                $permStmt->execute(['permission_key' => $permKey, 'role_name' => $roleName]);
                $seededCount++;
            }
        }
        $applied[] = $seededCount . ' role-permission rows seeded';
    } catch (Throwable $e) {
        $errors[] = 'role permission seeds: ' . $e->getMessage();
    }

    if ($errors) {
        $results[] = ['label' => 'Migration 002 — Notification hub schema', 'status' => 'skipped', 'note' => 'Errors: ' . implode('; ', $errors)];
    } else {
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 002 — Notification hub schema', 'status' => 'applied', 'note' => count($applied) . ' steps: ' . implode(', ', $applied)];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 003 — Simplify roles to Webmaster / Quartermaster / Area Rep / Member
// Removes committee, treasurer, membership_admin, store_admin, content_admin,
// and the webmaster role seeded in migration 002 (replaced by admin = Webmaster).
// Updates descriptions for renamed roles. Re-seeds permissions for remaining
// admin roles. Safe to run multiple times.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_003_simplify_roles';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 003 — Simplify roles', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();

        // Update descriptions for the three renamed roles
        $pdo->exec("UPDATE roles SET description = 'Webmaster - full system access', updated_at = NOW() WHERE name = 'admin'");
        $pdo->exec("UPDATE roles SET description = 'Quartermaster - store and order management', updated_at = NOW() WHERE name = 'store_manager'");
        $pdo->exec("UPDATE roles SET description = 'Area Representative', updated_at = NOW() WHERE name = 'chapter_leader'");

        // Remove deprecated roles — cascades to user_roles and role_permissions
        $pdo->exec("DELETE FROM roles WHERE name IN ('committee', 'treasurer', 'membership_admin', 'store_admin', 'content_admin', 'webmaster')");

        // Clean up any stale page_role_access rows for removed roles
        $pdo->exec("DELETE FROM page_role_access WHERE role IN ('committee', 'treasurer', 'membership_admin', 'store_admin', 'content_admin', 'webmaster')");

        // Re-seed Quartermaster (store_manager) permissions — full store access, no member access
        $quartermasterPerms = [
            'admin.store.view',
            'admin.products.manage',
            'admin.orders.view',
            'admin.orders.fulfil',
            'admin.orders.refund_cancel',
            'admin.order_fulfilment.manage',
        ];
        $pdo->exec(
            "DELETE rp FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'store_manager'
               AND rp.permission_key NOT IN (
                 'admin.store.view','admin.products.manage','admin.orders.view',
                 'admin.orders.fulfil','admin.orders.refund_cancel','admin.order_fulfilment.manage'
               )"
        );
        $qmStmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :perm, 1, NOW(), NOW() FROM roles r WHERE r.name = :role
             ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
        );
        foreach ($quartermasterPerms as $perm) {
            $qmStmt->execute(['perm' => $perm, 'role' => 'store_manager']);
        }

        // Re-seed Area Rep (chapter_leader) permissions
        $areaRepPerms = ['admin.dashboard.view', 'admin.members.view', 'admin.members.edit'];
        $pdo->exec(
            "DELETE rp FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'chapter_leader'
               AND rp.permission_key NOT IN ('admin.dashboard.view','admin.members.view','admin.members.edit')"
        );
        $arStmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :perm, 1, NOW(), NOW() FROM roles r WHERE r.name = :role
             ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
        );
        foreach ($areaRepPerms as $perm) {
            $arStmt->execute(['perm' => $perm, 'role' => 'chapter_leader']);
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 003 — Simplify roles', 'status' => 'applied', 'note' => 'Roles simplified to: Webmaster (admin) / Quartermaster / Area Rep / Member. Removed deprecated roles.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 003 — Simplify roles', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Add future migrations above this line in the same pattern.
// ─────────────────────────────────────────────────────────────────────────────

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Migration Runner — Admin</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    body { font-family: system-ui, sans-serif; background: #f8fafc; padding: 2rem; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; max-width: 640px; margin: 0 auto; }
    h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 1rem; }
    .row { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; }
    .row:last-child { border-bottom: none; }
    .badge { padding: 2px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
    .applied { background: #dcfce7; color: #166534; }
    .skipped { background: #f1f5f9; color: #64748b; }
    .error   { background: #fee2e2; color: #991b1b; }
    .label { flex: 1; font-size: 0.875rem; font-weight: 500; }
    .note { font-size: 0.75rem; color: #94a3b8; }
    .back { display: inline-block; margin-top: 1.25rem; font-size: 0.875rem; color: #3b82f6; text-decoration: none; }
  </style>
</head>
<body>
  <div class="card">
    <h1>🔧 Migration Runner</h1>
    <?php foreach ($results as $r): ?>
      <div class="row">
        <span class="badge <?= $r['status'] ?>"><?= htmlspecialchars($r['status']) ?></span>
        <div>
          <div class="label"><?= htmlspecialchars($r['label']) ?></div>
          <div class="note"><?= htmlspecialchars($r['note']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <a class="back" href="/admin/">← Back to admin</a>
  </div>
</body>
</html>
