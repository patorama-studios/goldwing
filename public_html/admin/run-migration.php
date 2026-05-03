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

    // webmaster role
    try {
        $stmt = $pdo->prepare("INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
            SELECT 'webmaster', 'webmaster', 'Webmaster (reviews and approves member submissions)', 1, 1, NOW(), NOW()
            WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'webmaster')");
        $stmt->execute();
        $applied[] = 'webmaster role seeded';
    } catch (Throwable $e) {
        $errors[] = 'webmaster role: ' . $e->getMessage();
    }

    // Seed role_permissions for new request hub permissions.
    // admin gets both, webmaster gets both, content_admin/committee get view only.
    $permissionSeeds = [
        'admin'         => ['admin.requests.view', 'admin.requests.action'],
        'webmaster'     => [
            'admin.dashboard.view',
            'admin.requests.view',
            'admin.requests.action',
            'admin.members.view',
            'admin.member_of_year.view',
            'admin.member_of_year.manage',
            'admin.events.manage',
            'admin.calendar.view',
            'admin.calendar.manage',
            'admin.pages.view',
            'admin.pages.edit',
            'admin.orders.view',
            'admin.payments.view',
            'admin.logs.view',
        ],
        'committee'     => ['admin.requests.view'],
        'content_admin' => ['admin.requests.view'],
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
// MIGRATION 003 — Add member role columns (is_area_rep, is_committee, committee_role)
// Adds three columns to the members table to track area reps and committee roles.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_003_member_roles';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 003 — Member role columns', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    // ADD COLUMN IF NOT EXISTS requires MySQL 8.0.29+. The live server is older,
    // so we run each ALTER separately and ignore "duplicate column" errors instead.
    $pdo = db();
    $applied = [];
    $errors = [];
    $tryAddCol = function (string $sql, string $label) use ($pdo, &$applied, &$errors) {
        try {
            $pdo->exec($sql);
            $applied[] = $label;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate') !== false) {
                $applied[] = $label . ' (already present)';
            } else {
                $errors[] = $label . ': ' . $msg;
            }
        }
    };

    // No AFTER clause — column order doesn't matter and the referenced
    // columns may not exist on every environment (e.g. older draft DB).
    $tryAddCol("ALTER TABLE members ADD COLUMN is_area_rep TINYINT(1) NOT NULL DEFAULT 0", 'is_area_rep');
    $tryAddCol("ALTER TABLE members ADD COLUMN is_committee TINYINT(1) NOT NULL DEFAULT 0", 'is_committee');
    $tryAddCol("ALTER TABLE members ADD COLUMN committee_role VARCHAR(150) NULL", 'committee_role');

    if ($errors) {
        $results[] = ['label' => 'Migration 003 — Member role columns', 'status' => 'error', 'note' => implode('; ', $errors)];
    } else {
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 003 — Member role columns', 'status' => 'applied', 'note' => implode(', ', $applied)];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 004 — Beta feedback ticketing table
// Adds a beta_feedback table so feedback widget submissions persist and can be
// surfaced + actioned in the notification hub like a simple ticket queue.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_004_beta_feedback';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 004 — Beta feedback table', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS beta_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            submitter_name VARCHAR(150) NULL,
            submitter_email VARCHAR(150) NULL,
            message TEXT NOT NULL,
            page_url VARCHAR(500) NULL,
            user_agent VARCHAR(500) NULL,
            status ENUM('open','in_progress','resolved','wont_fix') NOT NULL DEFAULT 'open',
            response TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_beta_feedback_status (status),
            INDEX idx_beta_feedback_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 004 — Beta feedback table', 'status' => 'applied', 'note' => 'beta_feedback table created.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 004 — Beta feedback table', 'status' => 'skipped', 'note' => 'Error: ' . $e->getMessage()];
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
