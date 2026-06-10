<?php
/**
 * One-time migration runner for admin use.
 * Visit this URL while logged in as admin to apply pending DB migrations.
 * Safe to run multiple times — each migration checks before applying.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\SettingsService;

// Clear OPcache so freshly-deployed service files are picked up immediately.
if (function_exists('opcache_reset')) { opcache_reset(); }

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
// MIGRATION 005 — Ensure member role columns exist (is_area_rep, is_committee, committee_role)
// Migration 003 used the same settings key as the earlier roles-simplification
// migration on some servers, so these columns may have been skipped. This
// migration uses a distinct key and is idempotent — safe to run multiple times.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_005_member_role_columns';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 005 — Member role columns (ensure)', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    $pdo = db();
    $applied = [];
    $errors  = [];
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

    $tryAddCol("ALTER TABLE members ADD COLUMN is_area_rep TINYINT(1) NOT NULL DEFAULT 0", 'is_area_rep');
    $tryAddCol("ALTER TABLE members ADD COLUMN is_committee TINYINT(1) NOT NULL DEFAULT 0", 'is_committee');
    $tryAddCol("ALTER TABLE members ADD COLUMN committee_role VARCHAR(150) NULL", 'committee_role');

    if ($errors) {
        $results[] = ['label' => 'Migration 005 — Member role columns (ensure)', 'status' => 'error', 'note' => implode('; ', $errors)];
    } else {
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 005 — Member role columns (ensure)', 'status' => 'applied', 'note' => implode(', ', $applied)];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 006 — Simplify roles to Webmaster / Quartermaster / Area Rep / Member
// Removes committee, treasurer, membership_admin, store_admin, content_admin,
// super_admin, and the separate webmaster role (admin = Webmaster going forward).
// Updates descriptions for renamed roles. Re-seeds permissions for remaining
// admin roles. Safe to run multiple times.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_006_simplify_roles';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 006 — Simplify roles', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();

        // Update descriptions for the renamed roles
        $pdo->exec("UPDATE roles SET description = 'Webmaster - full system access', updated_at = NOW() WHERE name = 'admin'");
        $pdo->exec("UPDATE roles SET description = 'Quartermaster - store and order management', updated_at = NOW() WHERE name = 'store_manager'");
        $pdo->exec("UPDATE roles SET description = 'Area Representative', updated_at = NOW() WHERE name = 'area_rep'");

        // Remove deprecated roles — ON DELETE CASCADE handles user_roles and role_permissions
        $pdo->exec("DELETE FROM roles WHERE name IN ('committee', 'treasurer', 'membership_admin', 'store_admin', 'content_admin', 'webmaster', 'super_admin')");

        // Clean up stale page_role_access rows for removed roles
        $pdo->exec("DELETE FROM page_role_access WHERE role IN ('committee', 'treasurer', 'membership_admin', 'store_admin', 'content_admin', 'webmaster', 'super_admin')");

        // Re-seed Quartermaster (store_manager) — full store access, no member access
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
        foreach (['admin.store.view','admin.products.manage','admin.orders.view','admin.orders.fulfil','admin.orders.refund_cancel','admin.order_fulfilment.manage'] as $perm) {
            $qmStmt->execute(['perm' => $perm, 'role' => 'store_manager']);
        }

        // Re-seed Area Rep
        $pdo->exec(
            "DELETE rp FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'area_rep'
               AND rp.permission_key NOT IN ('admin.dashboard.view','admin.members.view','admin.members.edit')"
        );
        $arStmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :perm, 1, NOW(), NOW() FROM roles r WHERE r.name = :role
             ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
        );
        foreach (['admin.dashboard.view','admin.members.view','admin.members.edit'] as $perm) {
            $arStmt->execute(['perm' => $perm, 'role' => 'area_rep']);
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 006 — Simplify roles', 'status' => 'applied', 'note' => 'Roles simplified to: Webmaster (admin) / Quartermaster / Area Rep / Member. Deprecated roles removed.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 006 — Simplify roles', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// MIGRATION 007 — Ticket messages + archived status
// Adds ticket_messages conversation table and extends status ENUMs/values
// to support the 'archived' state on all request types.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_007_ticket_messages';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 007 — Ticket messages', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();

        // Create ticket_messages table (conversation threads for any request type)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_type VARCHAR(50) NOT NULL,
            request_id INT NOT NULL,
            sender_type ENUM('member','admin') NOT NULL,
            user_id INT NULL,
            sender_name VARCHAR(150) NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tm_request (request_type, request_id),
            INDEX idx_tm_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Extend beta_feedback status ENUM to include 'archived'
        try {
            $pdo->exec("ALTER TABLE beta_feedback MODIFY COLUMN status
                ENUM('open','in_progress','resolved','wont_fix','archived') NOT NULL DEFAULT 'open'");
        } catch (Throwable $e2) { /* already extended */ }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 007 — Ticket messages', 'status' => 'applied',
            'note' => 'ticket_messages table created; beta_feedback status ENUM extended with archived.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 007 — Ticket messages', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 008 — Branded welcome email body (AGA design)
// Updates the member_set_password template in notifications.catalog to use the
// new AGA-branded inner body content (gold button, feature grid, 48-hour link).
// The EmailService wrapper (gold bar, logo header, footer) is now in PHP code;
// this migration only updates the inner body stored in the DB.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_008_branded_welcome_email';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 008 — Branded welcome email body', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    $catalog = SettingsService::getGlobal('notifications.catalog', []);
    if (!is_array($catalog)) {
        $catalog = [];
    }

    $brandedBody = '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#1c1a17;line-height:1.3;">G\'day {{first_name}},</p>'
        . '<p style="margin:0 0 24px;font-size:16px;color:#5a5a55;line-height:1.6;">Welcome to the Australian Goldwing Association\'s member portal \xe2\x80\x94 we\'re thrilled to have you on board with us!</p>'
        . '<hr style="border:none;border-top:1px solid #e8e3d7;margin:0 0 24px;">'
        . '<p style="margin:0 0 8px;font-size:15px;font-weight:600;color:#1c1a17;">One quick step before you can log in:</p>'
        . '<p style="margin:0 0 28px;font-size:15px;color:#5a5a55;line-height:1.6;">You\'ll need to create your password. Press the button below to get started \xe2\x80\x94 it only takes a moment.</p>'
        . '<table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;"><tr><td style="background:#9e9140;border-radius:8px;"><a href="{{reset_link}}" style="display:inline-block;padding:14px 36px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;letter-spacing:0.02em;">Set Your Password &rarr;</a></td></tr></table>'
        . '<p style="margin:0 0 24px;font-size:13px;color:#9a9a94;line-height:1.5;">This link is valid for 48 hours. If you have any trouble, reply to this email or contact us at <a href="mailto:webmaster@goldwing.org.au" style="color:#9e9140;text-decoration:none;">webmaster@goldwing.org.au</a></p>'
        . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f1e8;border-radius:10px;overflow:hidden;">'
        . '<tr><td style="padding:20px 24px 8px;"><p style="margin:0 0 16px;font-size:13px;font-weight:700;color:#9e9140;text-transform:uppercase;letter-spacing:0.1em;">Once you\'re in, you can</p></td></tr>'
        . '<tr><td style="padding:0 24px 20px;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
        . '<tr><td width="50%" style="padding:4px 8px 4px 0;font-size:13px;color:#5a5a55;vertical-align:top;">\xf0\x9f\x8f\x8d\xef\xb8\x8f Manage your bikes</td><td width="50%" style="padding:4px 0 4px 8px;font-size:13px;color:#5a5a55;vertical-align:top;">\xf0\x9f\x93\x96 Read Wings Magazine</td></tr>'
        . '<tr><td width="50%" style="padding:4px 8px 4px 0;font-size:13px;color:#5a5a55;vertical-align:top;">\xf0\x9f\x92\xb3 Manage your membership</td><td width="50%" style="padding:4px 0 4px 8px;font-size:13px;color:#5a5a55;vertical-align:top;">\xf0\x9f\x9b\x92 Shop the members store</td></tr>'
        . '<tr><td width="50%" style="padding:4px 8px 4px 0;font-size:13px;color:#5a5a55;vertical-align:top;">\xf0\x9f\x93\x85 Browse upcoming events</td><td width="50%" style="padding:4px 0 4px 8px;font-size:13px;color:#5a5a55;vertical-align:top;">\xf0\x9f\x93\xa2 Post on the notice board</td></tr>'
        . '</table></td></tr></table>';

    $catalog['member_set_password'] = array_merge(
        $catalog['member_set_password'] ?? [],
        [
            'subject' => "Welcome to the Australian Goldwing Association \xe2\x80\x94 let\xe2\x80\x99s get you set up!",
            'body'    => $brandedBody,
        ]
    );

    SettingsService::setGlobal((int) $user['id'], 'notifications.catalog', $catalog);
    SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);

    $results[] = ['label' => 'Migration 008 — Branded welcome email body', 'status' => 'applied', 'note' => 'member_set_password body updated to AGA-branded design.'];
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 009 — Add is_historic column to members table
// Tracks whether a member's motorcycle qualifies as a historic vehicle (25+ years).
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_009_member_historic_flag';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 009 — Member historic flag', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    $pdo = db();
    $applied = [];
    $errors  = [];
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

    $tryAddCol("ALTER TABLE members ADD COLUMN is_historic TINYINT(1) NOT NULL DEFAULT 0", 'is_historic');
    $tryAddCol("ALTER TABLE members ADD INDEX idx_members_historic (is_historic)", 'idx_members_historic');

    if ($errors) {
        $results[] = ['label' => 'Migration 009 — Member historic flag', 'status' => 'error', 'note' => implode('; ', $errors)];
    } else {
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 009 — Member historic flag', 'status' => 'applied', 'note' => implode(', ', $applied)];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 010 — Add missing chapters
// Inserts Holiday Coast, South Coast NSW, Southern Districts, and NFC chapters
// that appear in the import CSV but weren't seeded in the initial schema.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_010b_add_missing_chapters';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 010 — Add missing chapters', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        // Discover which columns the chapters table actually has
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM chapters") as $row) {
            $cols[] = $row['Field'];
        }
        $hasState = in_array('state', $cols, true);
        $hasTimes = in_array('created_at', $cols, true);

        $sql = 'INSERT INTO chapters (name' . ($hasState ? ', state' : '') . ($hasTimes ? ', created_at, updated_at' : '') . ')'
             . ' VALUES (:name' . ($hasState ? ', :state' : '') . ($hasTimes ? ', NOW(), NOW()' : '') . ')'
             . ' ON DUPLICATE KEY UPDATE name = name';
        $stmt = $pdo->prepare($sql);

        $chapters = [
            ['name' => 'Holiday Coast',      'state' => 'NSW'],
            ['name' => 'South Coast NSW',    'state' => 'NSW'],
            ['name' => 'Southern Districts', 'state' => 'NSW'],
            ['name' => 'NFC',                'state' => 'NSW'],
        ];
        $inserted = 0;
        foreach ($chapters as $ch) {
            $params = ['name' => $ch['name']];
            if ($hasState) { $params['state'] = $ch['state']; }
            $stmt->execute($params);
            $inserted++;
        }
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 010 — Add missing chapters', 'status' => 'applied', 'note' => $inserted . ' chapters upserted.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 010 — Add missing chapters', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 011 — Rename chapter_leader role to area_rep
// Updates the role name and display label to match the application code.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_011_rename_chapter_leader_to_area_rep';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 011 — Rename chapter_leader → area_rep', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $pdo->exec("UPDATE roles SET name = 'area_rep', description = 'Area Representative', updated_at = NOW() WHERE name = 'chapter_leader'");
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 011 — Rename chapter_leader → area_rep', 'status' => 'applied', 'note' => 'Role renamed from chapter_leader to area_rep.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 011 — Rename chapter_leader → area_rep', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 012 — Add missing member_bikes columns (image_url, rego)
// Older databases were created before these columns were added to schema.sql,
// so admin-side saves were silently dropping bike photos and rego values.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_012_member_bikes_columns';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 012 — member_bikes image_url + rego', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];
        $existing = $pdo->query("SHOW COLUMNS FROM member_bikes")->fetchAll(PDO::FETCH_COLUMN, 0);
        // Add rego first so image_url's AFTER clause is valid.
        if (!in_array('rego', $existing, true)) {
            $pdo->exec("ALTER TABLE member_bikes ADD COLUMN rego VARCHAR(50) NULL AFTER year");
            $applied[] = 'rego added';
        } else {
            $applied[] = 'rego already present';
        }
        $existing = $pdo->query("SHOW COLUMNS FROM member_bikes")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('image_url', $existing, true)) {
            $pdo->exec("ALTER TABLE member_bikes ADD COLUMN image_url VARCHAR(255) NULL AFTER rego");
            $applied[] = 'image_url added';
        } else {
            $applied[] = 'image_url already present';
        }
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 012 — member_bikes image_url + rego', 'status' => 'applied', 'note' => implode(', ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 012 — member_bikes image_url + rego', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 013 — Add missing fallen_wings attachment columns (image_url, pdf_url)
// Submission/edit handlers in public_html/member/index.php and
// public_html/admin/index.php INSERT/UPDATE these columns, but no earlier
// migration created them, so saving a new Fallen Wings entry threw a PDO
// error and the page rendered a PHP warning screen.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_013_fallen_wings_attachments';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 013 — fallen_wings image_url + pdf_url', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];
        $existing = $pdo->query("SHOW COLUMNS FROM fallen_wings")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('image_url', $existing, true)) {
            $pdo->exec("ALTER TABLE fallen_wings ADD COLUMN image_url VARCHAR(255) NULL AFTER tribute");
            $applied[] = 'image_url added';
        } else {
            $applied[] = 'image_url already present';
        }
        $existing = $pdo->query("SHOW COLUMNS FROM fallen_wings")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('pdf_url', $existing, true)) {
            $pdo->exec("ALTER TABLE fallen_wings ADD COLUMN pdf_url VARCHAR(255) NULL AFTER image_url");
            $applied[] = 'pdf_url added';
        } else {
            $applied[] = 'pdf_url already present';
        }
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 013 — fallen_wings image_url + pdf_url', 'status' => 'applied', 'note' => implode(', ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 013 — fallen_wings image_url + pdf_url', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 014 — Add members.avatar_url so legacy members without a linked
// users row can still have a profile photo. Avatars previously lived only
// in settings_user (keyed by user_id). The display code prefers
// members.avatar_url first and falls back to settings_user so existing
// avatars continue to work.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_014_members_avatar_url';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 014 — members.avatar_url', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $existing = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('avatar_url', $existing, true)) {
            // notes column may or may not exist; try AFTER notes first, fall
            // back to a plain ADD if that AFTER target is missing.
            try {
                $pdo->exec("ALTER TABLE members ADD COLUMN avatar_url VARCHAR(512) NULL AFTER notes");
            } catch (Throwable $inner) {
                $pdo->exec("ALTER TABLE members ADD COLUMN avatar_url VARCHAR(512) NULL");
            }
            $note = 'avatar_url added';
        } else {
            $note = 'avatar_url already present';
        }
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 014 — members.avatar_url', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 014 — members.avatar_url', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 015 — Committee & Leadership roles
//
// Replaces the free-text members.committee_role + boolean is_committee/is_area_rep
// flags with a proper catalog + many-to-many assignment system, so the public
// Committee and Chapter Reps pages can render dynamically from admin-controlled
// assignments.
//
// Creates:
//   committee_roles               — catalog of roles (national + chapter-rep)
//   member_committee_assignments  — who holds which role
//
// Seeds the 9 national roles + 13 chapter rep roles using the role-based emails
// already in use (aga.president@…, ar.sydney@… etc), with phone numbers from
// the current public pages.
//
// Then fuzzy-matches existing member records by name and creates assignments.
// Any roster name that doesn't match a member becomes a lightweight stub
// member record (no user_id, status='ACTIVE') so the cards still render.
//
// Finally, swaps the hand-edited HTML on the committee + chapter-* pages for
// dynamic [committee] / [chapter-reps state="…"] shortcodes.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_015_committee_roles';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 015 — Committee & leadership roles', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        // ── 1. Schema ────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS committee_roles (
              id INT AUTO_INCREMENT PRIMARY KEY,
              slug VARCHAR(80) NOT NULL UNIQUE,
              name VARCHAR(120) NOT NULL,
              category ENUM('national','chapter') NOT NULL,
              chapter_id INT NULL,
              email VARCHAR(150) NULL,
              phone VARCHAR(40) NULL,
              sort_order INT NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_cr_category (category),
              INDEX idx_cr_chapter (chapter_id),
              CONSTRAINT fk_committee_roles_chapter FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'committee_roles table ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_committee_assignments (
              id INT AUTO_INCREMENT PRIMARY KEY,
              member_id INT NOT NULL,
              role_id INT NOT NULL,
              since DATE NULL,
              notes VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_mca_pair (member_id, role_id),
              INDEX idx_mca_role (role_id),
              CONSTRAINT fk_mca_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
              CONSTRAINT fk_mca_role FOREIGN KEY (role_id) REFERENCES committee_roles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'member_committee_assignments table ready';

        // ── 2. Role catalog seed ─────────────────────────────────────────────
        // National roles — sort_order picks display order on cards.
        $nationalRoles = [
            ['national_president',          'National President',          10, 'aga.president@goldwing.org.au',      '0429 324 426'],
            ['national_vice_president',     'National Vice President',     20, 'aga.vicepresident@goldwing.org.au',  null],
            ['national_secretary',          'National Secretary',          30, 'aga.secretary@goldwing.org.au',      null],
            ['national_treasurer',          'National Treasurer',          40, 'aga.treasurer@goldwing.org.au',      '0412 662 448'],
            ['national_promotions_officer', 'National Promotions Officer', 50, 'aga.promotions@goldwing.org.au',     '0449 150 530'],
            ['national_quartermaster',      'Quartermaster',               55, 'aga.quartermaster@goldwing.org.au', null],
            ['committee_member_1',          'Committee Member',            60, 'aga.committee1@goldwing.org.au',     '0412 226 886'],
            ['committee_member_2',          'Committee Member',            61, 'aga.committee2@goldwing.org.au',     null],
            ['committee_member_3',          'Committee Member',            62, 'aga.committee3@goldwing.org.au',     null],
            ['public_officer',              'Public Officer',              70, 'aga.publicofficer@goldwing.org.au',  '0455 380 162'],
        ];
        $insertRole = $pdo->prepare("
            INSERT INTO committee_roles (slug, name, category, chapter_id, email, phone, sort_order, is_active)
            VALUES (:slug, :name, 'national', NULL, :email, :phone, :sort, 1)
            ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email),
              phone = VALUES(phone), sort_order = VALUES(sort_order), is_active = 1
        ");
        foreach ($nationalRoles as [$slug, $name, $sort, $email, $phone]) {
            $insertRole->execute([':slug' => $slug, ':name' => $name, ':email' => $email, ':phone' => $phone, ':sort' => $sort]);
        }
        $applied[] = 'national roles seeded (' . count($nationalRoles) . ')';

        // Chapter rep roles — one per chapter that has an area rep. We match
        // the chapter row by LIKE 'X%' so 'Central Coast' matches both
        // 'Central Coast Chapter' and 'Central Coast'.
        $chapterRoles = [
            // [slug,                       display name,                chapter LIKE,           sort, email,                            phone]
            ['ar_central_coast',     'Central Coast Area Rep',     'Central Coast%',     100, 'ar.centralcoast@goldwing.org.au',     '0455 380 162'],
            ['ar_central_west',      'Central West Area Rep',      'Central West%',      110, 'ar.centralwest@goldwing.org.au',      '0402 075 741'],
            ['ar_coffs_coast',       'Coffs Coast Area Rep',       'Coffs Coast%',       120, 'ar.coffscoast@goldwing.org.au',       '0400 409 681'],
            ['ar_new_england',       'New England Area Rep',       'New England%',       130, 'ar.newengland@goldwing.org.au',       '02 6772 2706'],
            ['ar_north_west',        'North West Area Rep',        'North West%',        140, 'ar.northwest@goldwing.org.au',        '02 6743 1725'],
            ['ar_riverina',          'Riverina Area Rep',          'Riverina%',          150, 'ar.riverina@goldwing.org.au',         '0428 622 777'],
            ['ar_sydney',            'Sydney Area Rep',            'Sydney%',            160, 'ar.sydney@goldwing.org.au',           '0449 150 530'],
            ['ar_brisbane',          'Brisbane Area Rep',          'Brisbane%',          200, 'ar.brisbane@goldwing.org.au',         '0410 256 667'],
            ['ar_fraser_coast',      'Fraser Coast Area Rep',      'Fraser Coast%',      210, 'ar.frasercoast@goldwing.org.au',      '0400 112 012'],
            ['ar_perth',             'Perth Area Rep',             'Perth%',             300, 'ar.perth@goldwing.org.au',            '0417 987 742'],
            ['ar_west_coast_wings',  'West Coast Wings Area Rep',  'West Coast Wings%',  310, 'ar.westcoastwings@goldwing.org.au',   '0407 447 159'],
            ['ar_south_australian',  'South Australian Area Rep',  'South Australian%',  400, 'ar.southaustralian@goldwing.org.au',  '0421 357 116'],
            ['ar_tasmania',          'Tasmania Area Rep',          'Tasmania%',          500, 'ar.tasmania@goldwing.org.au',         '0429 351 615'],
        ];
        $insertChRole = $pdo->prepare("
            INSERT INTO committee_roles (slug, name, category, chapter_id, email, phone, sort_order, is_active)
            VALUES (:slug, :name, 'chapter', :chapter_id, :email, :phone, :sort, 1)
            ON DUPLICATE KEY UPDATE name = VALUES(name), chapter_id = VALUES(chapter_id),
              email = VALUES(email), phone = VALUES(phone), sort_order = VALUES(sort_order), is_active = 1
        ");
        $findChapter = $pdo->prepare("SELECT id FROM chapters WHERE name LIKE :like ORDER BY name LIMIT 1");
        foreach ($chapterRoles as [$slug, $name, $like, $sort, $email, $phone]) {
            $findChapter->execute([':like' => $like]);
            $cid = $findChapter->fetchColumn();
            $insertChRole->execute([
                ':slug' => $slug, ':name' => $name, ':chapter_id' => $cid ?: null,
                ':email' => $email, ':phone' => $phone, ':sort' => $sort,
            ]);
        }
        $applied[] = 'chapter rep roles seeded (' . count($chapterRoles) . ')';

        // ── 3. Match roster names to members; create stubs for the rest ──────
        // Map: role slug → ['First Last', maybe chapter_id for stub fallback]
        // Stubs use chapter_id from the role itself so they land in the right
        // chapter. National roles seed a stub with no chapter.
        $roster = [
            ['national_president',          'Lewis',   'Furner',    null],
            ['national_vice_president',     'Paul',    '',          null],
            ['national_secretary',          'Vanessa', 'Lindley',   null],
            ['national_treasurer',          'Robyn',   'Furner',    null],
            ['national_promotions_officer', 'Wayne',   'Gannon',    null],
            ['committee_member_1',          'Les',     'Sorensen',  null],
            ['committee_member_2',          'Ian',     'Kennedy',   null],
            ['committee_member_3',          'Werner',  'Voss',      null],
            ['public_officer',              'Mal',     'Allen',     null],
            ['ar_central_coast',            'Mal',     'Allen',     null],
            ['ar_central_west',             'Dorothy', 'Springett', null],
            ['ar_coffs_coast',              'Brian',   'Platts',    null],
            ['ar_new_england',              'Allan',   'Piddington',null],
            ['ar_north_west',               'Stephen', 'Ward',      null],
            ['ar_riverina',                 'Kevin',   'Lindley',   null],
            ['ar_sydney',                   'Wayne',   'Gannon',    null],
            ['ar_brisbane',                 'Greg',    'Naylor',    null],
            ['ar_fraser_coast',             'Robert',  'Watson',    null],
            ['ar_perth',                    'David',   'Goodchild', null],
            ['ar_west_coast_wings',         'Gary',    'Cubbage',   null],
            ['ar_south_australian',         'Colin',   'Underhill', null],
            ['ar_tasmania',                 'Dennis',  'Davis',     null],
        ];

        $findMember = $pdo->prepare("
            SELECT id, chapter_id FROM members
            WHERE LOWER(first_name) = LOWER(:fn) AND LOWER(last_name) = LOWER(:ln)
            ORDER BY (status = 'ACTIVE') DESC, id ASC LIMIT 1
        ");
        $findRoleBySlug = $pdo->prepare("SELECT id, chapter_id FROM committee_roles WHERE slug = :slug LIMIT 1");
        $insertStub     = $pdo->prepare("
            INSERT INTO members (member_type, status, member_number_base, member_number_suffix,
                                 chapter_id, first_name, last_name, email, phone, created_at)
            VALUES ('ASSOCIATE', 'ACTIVE', :base, 0, :chapter_id, :fn, :ln, :email, NULL, NOW())
        ");
        $upsertAssign = $pdo->prepare("
            INSERT INTO member_committee_assignments (member_id, role_id, since)
            VALUES (:member_id, :role_id, CURDATE())
            ON DUPLICATE KEY UPDATE since = COALESCE(since, VALUES(since))
        ");

        // Allocate stub member numbers starting from MAX+1 in a dedicated band
        // (we use a high base so they don't collide with real member numbers).
        $maxBase = (int) $pdo->query("SELECT COALESCE(MAX(member_number_base), 9000) FROM members")->fetchColumn();
        $nextStubBase = max($maxBase, 9000) + 1;

        $matched = 0; $stubbed = 0;
        foreach ($roster as [$slug, $fn, $ln, $_]) {
            $findRoleBySlug->execute([':slug' => $slug]);
            $role = $findRoleBySlug->fetch(PDO::FETCH_ASSOC);
            if (!$role) { continue; }

            $memberId = null;
            if ($ln !== '') {
                $findMember->execute([':fn' => $fn, ':ln' => $ln]);
                $row = $findMember->fetch(PDO::FETCH_ASSOC);
                if ($row) { $memberId = (int) $row['id']; $matched++; }
            }
            if ($memberId === null) {
                // Stub. Synthesize a unique placeholder email so the NOT NULL
                // email column is satisfied; admin can fix it later.
                $stubEmail = strtolower($fn . '.' . ($ln !== '' ? $ln : 'pending') . '+stub@goldwing.org.au');
                $insertStub->execute([
                    ':base' => $nextStubBase,
                    ':chapter_id' => $role['chapter_id'],
                    ':fn' => $fn,
                    ':ln' => $ln !== '' ? $ln : 'TBC',
                    ':email' => $stubEmail,
                ]);
                $memberId = (int) $pdo->lastInsertId();
                $nextStubBase++;
                $stubbed++;
            }
            $upsertAssign->execute([':member_id' => $memberId, ':role_id' => (int) $role['id']]);
        }
        $applied[] = "roster linked (matched: $matched, stubbed: $stubbed)";

        // ── 4. Swap PageBuilder page content for dynamic shortcodes ──────────
        // Keeps intro copy + hero, replaces the hand-rolled grids with
        // [committee] / [chapter-reps state="…"]. Idempotent because the
        // migration flag guards re-runs.
        $pageUpdates = [
            'committee'                  => '<p>Riding with a powerful motorcycle comes a skilled crew ready to navigate the roads here at the Australian Goldwing Association.</p>[committee]',
            'chapters-representatives'   => '<p>Local chapters are the heart of the association. Each state has representatives who coordinate rides and support members.</p>[chapter-reps]',
            'chapters-nsw'               => '<p>Local chapters across New South Wales coordinate rides and support members throughout the region.</p>[chapter-reps state="ACT & New South Wales"]',
            'chapters-qld'               => '<p>Queensland chapters connect riders across the state with regular rides, events, and support.</p>[chapter-reps state="Queensland"]',
            'chapters-wa'                => '<p>Western Australia chapters coordinate local rides and keep members connected across the state.</p>[chapter-reps state="Western Australia"]',
            'chapters-sa'                => '<p>South Australian riders connect through a dedicated chapter and local events.</p>[chapter-reps state="South Australia"]',
            'chapters-tas'               => '<p>Tasmanian members stay connected through local rides and chapter support.</p>[chapter-reps state="Tasmania"]',
        ];
        // Note: this server runs PDO with ATTR_EMULATE_PREPARES=false, so the
        // same value cannot be bound to two placeholders with the same name.
        // We bind html_content and live_html separately to satisfy MySQL's
        // native prepared-statement parser.
        $updatePage = $pdo->prepare("
            UPDATE pages SET html_content = :body_html, live_html = :body_live, updated_at = NOW()
            WHERE slug = :slug
        ");
        $pageCount = 0;
        foreach ($pageUpdates as $slug => $body) {
            $updatePage->execute([':body_html' => $body, ':body_live' => $body, ':slug' => $slug]);
            if ($updatePage->rowCount() > 0) { $pageCount++; }
        }
        $applied[] = "$pageCount page(s) wired to shortcodes";

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = [
            'label' => 'Migration 015 — Committee & leadership roles',
            'status' => 'applied',
            'note' => implode(' · ', $applied),
        ];
    } catch (Throwable $e) {
        $results[] = [
            'label' => 'Migration 015 — Committee & leadership roles',
            'status' => 'error',
            'note' => $e->getMessage(),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 016 — Committee roles cleanup
//
// Two follow-ups to Migration 015:
//
// 1) Duplicate stubs. The roster includes "Paul" (Vice President) with no
//    last name, so the matcher in 015 always skipped fuzzy-matching for him
//    and went straight to stub creation. If 015 was attempted twice (which
//    it was on this server because the page UPDATE step initially failed
//    with HY093), each attempt created a fresh Paul-TBC stub, each assigned
//    to the same Vice President role. The (member_id, role_id) UNIQUE
//    constraint blocks the same member being assigned twice, but NOT two
//    different members holding the same role — so the public grid shows
//    two cards for the VP slot.
//
//    Fix: for any role with >1 assignment, keep the earliest, drop the rest,
//    and delete any stub member rows that are no longer referenced.
//
// 2) Page content. Re-applies the [committee] / [chapter-reps] shortcodes to
//    the 7 affected PageBuilder pages. Migration 015's page UPDATE block ran
//    after the SQL was fixed, but this is a safety net in case any page was
//    missed and so admins can re-run if they hand-edited the body and want
//    to revert to the dynamic version.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_016_committee_cleanup';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 016 — Committee cleanup', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        // ── 1. Drop duplicate assignments per role ───────────────────────────
        // For each role with multiple assignments, keep only the earliest by
        // assignment id, delete the rest.
        $dupes = $pdo->query("
            SELECT role_id, COUNT(*) AS n
            FROM member_committee_assignments
            GROUP BY role_id
            HAVING n > 1
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $removedAssignments = 0;
        foreach ($dupes as $row) {
            $roleId = (int) $row['role_id'];
            // Get all assignment ids for this role, keep the lowest
            $ids = $pdo->prepare("
                SELECT id FROM member_committee_assignments
                WHERE role_id = :rid
                ORDER BY id ASC
            ");
            $ids->execute([':rid' => $roleId]);
            $allIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            array_shift($allIds); // drop the earliest from the deletion list
            if ($allIds) {
                $ph = implode(',', array_fill(0, count($allIds), '?'));
                $del = $pdo->prepare("DELETE FROM member_committee_assignments WHERE id IN ($ph)");
                $del->execute($allIds);
                $removedAssignments += count($allIds);
            }
        }
        $applied[] = "$removedAssignments duplicate assignment(s) removed";

        // ── 2. Delete orphaned stub members ──────────────────────────────────
        // Stubs are identifiable by the synthesized "+stub@goldwing.org.au"
        // email pattern. After step 1, some stubs no longer have any
        // assignments — these can be removed safely.
        $orphanStubs = $pdo->query("
            SELECT m.id FROM members m
            LEFT JOIN member_committee_assignments a ON a.member_id = m.id
            WHERE m.email LIKE '%+stub@goldwing.org.au'
              AND m.user_id IS NULL
              AND a.id IS NULL
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $deletedStubs = 0;
        if ($orphanStubs) {
            $ph = implode(',', array_fill(0, count($orphanStubs), '?'));
            $del = $pdo->prepare("DELETE FROM members WHERE id IN ($ph)");
            $del->execute(array_map('intval', $orphanStubs));
            $deletedStubs = $del->rowCount();
        }
        $applied[] = "$deletedStubs orphaned stub member(s) removed";

        // ── 3. Re-apply shortcode page content ───────────────────────────────
        $pageUpdates = [
            'committee'                  => '<p>Riding with a powerful motorcycle comes a skilled crew ready to navigate the roads here at the Australian Goldwing Association.</p>[committee]',
            'chapters-representatives'   => '<p>Local chapters are the heart of the association. Each state has representatives who coordinate rides and support members.</p>[chapter-reps]',
            'chapters-nsw'               => '<p>Local chapters across New South Wales coordinate rides and support members throughout the region.</p>[chapter-reps state="ACT & New South Wales"]',
            'chapters-qld'               => '<p>Queensland chapters connect riders across the state with regular rides, events, and support.</p>[chapter-reps state="Queensland"]',
            'chapters-wa'                => '<p>Western Australia chapters coordinate local rides and keep members connected across the state.</p>[chapter-reps state="Western Australia"]',
            'chapters-sa'                => '<p>South Australian riders connect through a dedicated chapter and local events.</p>[chapter-reps state="South Australia"]',
            'chapters-tas'               => '<p>Tasmanian members stay connected through local rides and chapter support.</p>[chapter-reps state="Tasmania"]',
        ];
        $updatePage = $pdo->prepare("
            UPDATE pages SET html_content = :body_html, live_html = :body_live, updated_at = NOW()
            WHERE slug = :slug
        ");
        $pageCount = 0;
        foreach ($pageUpdates as $slug => $body) {
            $updatePage->execute([':body_html' => $body, ':body_live' => $body, ':slug' => $slug]);
            if ($updatePage->rowCount() > 0) { $pageCount++; }
        }
        $applied[] = "$pageCount page(s) re-wired to shortcodes";

        // ── 4. Quick diagnostic — surface current page content state ─────────
        $cmtPage = $pdo->query("SELECT LEFT(COALESCE(live_html, html_content, ''), 80) AS preview FROM pages WHERE slug = 'committee'")->fetchColumn();
        $applied[] = "committee page preview: " . (string) $cmtPage;

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = [
            'label' => 'Migration 016 — Committee cleanup',
            'status' => 'applied',
            'note' => implode(' · ', $applied),
        ];
    } catch (Throwable $e) {
        $results[] = [
            'label' => 'Migration 016 — Committee cleanup',
            'status' => 'error',
            'note' => $e->getMessage(),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 017 — Tours system tables
//
// Adds the three tables used by the Driver.js guided-tour system:
//   - tour_completions       per-user tour completion (powers the "Done" badge
//                            on the member help panel)
//   - tour_test_runs         linter + admin Tour Validator results, used by the
//                            admin sidebar "Tours need attention" badge and by
//                            failure-email routing
//   - tour_file_dependencies optional reverse lookup populated from
//                            config/tour-manifest.json (currently unused by the
//                            runtime — the manifest JSON is consulted directly
//                            — but kept for future SQL-side impact queries)
//
// Safe to re-run: every CREATE uses IF NOT EXISTS.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_017_tours_system';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 017 — Tours system tables', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tour_completions (
              user_id      INT          NOT NULL,
              tour_slug    VARCHAR(96)  NOT NULL,
              completed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (user_id, tour_slug),
              INDEX idx_tour_completions_slug (tour_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $applied[] = 'tour_completions ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tour_test_runs (
              id              INT AUTO_INCREMENT PRIMARY KEY,
              tour_slug       VARCHAR(96)  NOT NULL,
              run_kind        ENUM('linter','validator','playwright') NOT NULL,
              run_as_role     VARCHAR(32)  NULL,
              tested_by       INT          NULL,
              status          ENUM('pass','fail','partial') NOT NULL,
              details_json    LONGTEXT     NULL,
              created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_tour_test_runs_slug (tour_slug),
              INDEX idx_tour_test_runs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $applied[] = 'tour_test_runs ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tour_file_dependencies (
              tour_slug   VARCHAR(96)  NOT NULL,
              file_path   VARCHAR(255) NOT NULL,
              PRIMARY KEY (tour_slug, file_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $applied[] = 'tour_file_dependencies ready';

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 017 — Tours system tables', 'status' => 'applied', 'note' => implode(', ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 017 — Tours system tables', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 018 — Tour steps table (Option A: wording editor)
//
// Moves Driver.js tour step CONTENT (title, description, popover side/align)
// out of JS files and into the database so admins can edit tour wording from
// /admin/help/edit.php without a code deploy.
//
// The manifest (config/tour-manifest.json) still owns structural metadata —
// slug, audience, page_url, page_match, selectors — because changing those
// usually requires editing the target page too.
//
// Schema notes:
//   - step_index orders steps (0-based)
//   - draft_* columns hold pending edits; admin clicks "Publish" to copy them
//     into the live title/description/side/align
//   - has_draft is a quick flag so the editor can show "unsaved changes"
//   - updated_by + activity_log tracking ties edits back to a user
//
// Seeds the existing member-update-contact tour from its current JS file so
// the tour keeps working immediately after the engine refactor.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_018_tour_steps';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 018 — Tour steps table', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tour_steps (
              id INT AUTO_INCREMENT PRIMARY KEY,
              tour_slug VARCHAR(96) NOT NULL,
              step_index INT NOT NULL,
              element_selector VARCHAR(255) NOT NULL,
              title VARCHAR(255) NOT NULL,
              description TEXT NOT NULL,
              side VARCHAR(16) NOT NULL DEFAULT 'bottom',
              align VARCHAR(16) NOT NULL DEFAULT 'start',
              draft_title VARCHAR(255) NULL,
              draft_description TEXT NULL,
              draft_side VARCHAR(16) NULL,
              draft_align VARCHAR(16) NULL,
              draft_element_selector VARCHAR(255) NULL,
              has_draft TINYINT(1) NOT NULL DEFAULT 0,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              updated_by INT NULL,
              published_at DATETIME NULL,
              UNIQUE KEY uniq_slug_index (tour_slug, step_index),
              INDEX idx_slug (tour_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $applied[] = 'tour_steps ready';

        // Seed member-update-contact (existing tour) so it keeps working.
        $seedStmt = $pdo->prepare(
            "INSERT IGNORE INTO tour_steps
                (tour_slug, step_index, element_selector, title, description, side, align, published_at)
             VALUES
                (:slug, :idx, :sel, :title, :desc, :side, :align, NOW())"
        );
        $memberContactSteps = [
            [0, '[data-tour=\"profile-form\"]', 'Updating your details', "We'll walk through changing your email or phone number. Click <strong>Next</strong> when you're ready.", 'top', 'start'],
            [1, '[data-tour=\"profile-email\"]', 'Your email address', "This is where the club sends your magazine and notices. Click in the box and type your new email if you'd like to change it.", 'bottom', 'start'],
            [2, '[data-tour=\"profile-phone\"]', 'Your phone number', "Click in the box and type your new phone number. Don't worry about spaces — type it any way you like.", 'bottom', 'start'],
            [3, '[data-tour=\"profile-save\"]', 'Save your changes', "When you're happy, click the gold <strong>Save changes</strong> button. You'll see a green message at the top to confirm it worked.<br><br><strong>That's it — you did it!</strong>", 'left', 'start'],
        ];
        foreach ($memberContactSteps as $row) {
            [$idx, $sel, $title, $desc, $side, $align] = $row;
            $seedStmt->execute([
                'slug'  => 'member-update-contact',
                'idx'   => $idx,
                'sel'   => $sel,
                'title' => $title,
                'desc'  => $desc,
                'side'  => $side,
                'align' => $align,
            ]);
        }
        $applied[] = 'member-update-contact seeded (4 steps)';

        // Also seed all the bulk-authored tours from the vendored JSON fixture
        // (config/tour-steps-seed.json). INSERT IGNORE means re-runs are safe —
        // already-seeded tours are skipped. To edit wording, use the admin
        // editor at /admin/help/edit.php after this migration runs.
        $seedPath = __DIR__ . '/../../config/tour-steps-seed.json';
        if (is_file($seedPath)) {
            $seedJson = @file_get_contents($seedPath);
            $seedData = $seedJson ? json_decode($seedJson, true) : null;
            $bulkSeeded = 0;
            if (is_array($seedData)) {
                foreach ($seedData as $slug => $steps) {
                    if ($slug === 'member-update-contact') continue;
                    if (!is_array($steps)) continue;
                    foreach ($steps as $i => $step) {
                        $popover = is_array($step['popover'] ?? null) ? $step['popover'] : [];
                        $seedStmt->execute([
                            'slug'  => (string) $slug,
                            'idx'   => (int) $i,
                            'sel'   => (string) ($step['element'] ?? ''),
                            'title' => (string) ($popover['title'] ?? ''),
                            'desc'  => (string) ($popover['description'] ?? ''),
                            'side'  => (string) ($popover['side'] ?? 'bottom'),
                            'align' => (string) ($popover['align'] ?? 'start'),
                        ]);
                        $bulkSeeded++;
                    }
                }
            }
            $applied[] = "$bulkSeeded steps seeded from tour-steps-seed.json";
        } else {
            $applied[] = 'tour-steps-seed.json not found — skip bulk seed';
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 018 — Tour steps table', 'status' => 'applied', 'note' => implode(', ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 018 — Tour steps table', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 017 — Committee privacy flag
//
// Adds members.committee_private. When 1, the public Committee + Chapter Rep
// cards (and member-area equivalents) show first name only and omit the role
// phone for that member. Role title, chapter, and role-based email still
// render — they identify the position, not the person.
//
// Member can toggle their own flag from Personal Settings; admins can toggle
// it from the member profile's Committee & Leadership Role section.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_017_committee_private';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 017 — Committee privacy flag', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $existing = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('committee_private', $existing, true)) {
            $pdo->exec("ALTER TABLE members ADD COLUMN committee_private TINYINT(1) NOT NULL DEFAULT 0");
            $note = 'committee_private column added';
        } else {
            $note = 'committee_private column already present';
        }
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 017 — Committee privacy flag', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 017 — Committee privacy flag', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 019 — AGM Registration system schema
//
// Creates the six tables backing the AGM module:
//   agm_events, agm_products, agm_form_fields,
//   agm_registrations, agm_registration_items, agm_registration_motorcycles
//
// Also ensures the 'agm' row exists in payment_channels (so the secondary
// Stripe account has an invoice counter + webhook health tracking), adds a
// matching settings_payments row, and seeds the agm_manager role.
//
// All CREATEs are IF NOT EXISTS and all INSERTs are ON DUPLICATE KEY UPDATE
// / INSERT IGNORE, so this migration is safe to re-run.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_019_agm_schema';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 019 — AGM schema', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agm_events (
              id INT AUTO_INCREMENT PRIMARY KEY,
              year INT NOT NULL,
              slug VARCHAR(100) NOT NULL,
              title VARCHAR(200) NOT NULL,
              subtitle VARCHAR(200) NULL,
              hosting_chapter VARCHAR(150) NULL,
              venue_name VARCHAR(200) NULL,
              venue_address VARCHAR(255) NULL,
              venue_phone VARCHAR(40) NULL,
              start_date DATE NULL,
              end_date DATE NULL,
              registration_opens_at DATETIME NULL,
              registration_closes_at DATETIME NULL,
              late_fee_starts_at DATETIME NULL,
              description_html MEDIUMTEXT NULL,
              cover_image_path VARCHAR(255) NULL,
              contact_name VARCHAR(150) NULL,
              contact_phone VARCHAR(40) NULL,
              contact_email VARCHAR(150) NULL,
              bank_transfer_instructions MEDIUMTEXT NULL,
              allow_bank_transfer TINYINT(1) NOT NULL DEFAULT 1,
              allow_stripe TINYINT(1) NOT NULL DEFAULT 1,
              status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
              is_current TINYINT(1) NOT NULL DEFAULT 0,
              stripe_account_key VARCHAR(40) NOT NULL DEFAULT 'agm',
              created_by_user_id INT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NULL,
              UNIQUE KEY uniq_agm_events_year_slug (year, slug),
              INDEX idx_agm_events_year (year),
              INDEX idx_agm_events_status (status),
              INDEX idx_agm_events_is_current (is_current)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'agm_events ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agm_products (
              id INT AUTO_INCREMENT PRIMARY KEY,
              agm_event_id INT NOT NULL,
              category ENUM('registration','merchandise','meal','custom') NOT NULL DEFAULT 'custom',
              name VARCHAR(200) NOT NULL,
              description TEXT NULL,
              early_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              late_price DECIMAL(10,2) NULL,
              member_only TINYINT(1) NOT NULL DEFAULT 0,
              non_member_only TINYINT(1) NOT NULL DEFAULT 0,
              requires_choice TINYINT(1) NOT NULL DEFAULT 0,
              choices_json TEXT NULL,
              quantity_limit INT NULL,
              per_registration_limit INT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NULL,
              INDEX idx_agm_products_event (agm_event_id, category, sort_order),
              CONSTRAINT fk_agm_products_event FOREIGN KEY (agm_event_id) REFERENCES agm_events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'agm_products ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agm_form_fields (
              id INT AUTO_INCREMENT PRIMARY KEY,
              agm_event_id INT NOT NULL,
              field_key VARCHAR(80) NOT NULL,
              label VARCHAR(200) NOT NULL,
              helper_text VARCHAR(255) NULL,
              field_group ENUM('personal','bike','emergency','other') NOT NULL DEFAULT 'other',
              field_type ENUM('text','number','checkbox','select','textarea') NOT NULL DEFAULT 'text',
              options_json TEXT NULL,
              is_required TINYINT(1) NOT NULL DEFAULT 0,
              sort_order INT NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NULL,
              UNIQUE KEY uniq_agm_form_field_key (agm_event_id, field_key),
              INDEX idx_agm_form_fields_event (agm_event_id, sort_order),
              CONSTRAINT fk_agm_form_fields_event FOREIGN KEY (agm_event_id) REFERENCES agm_events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'agm_form_fields ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agm_registrations (
              id INT AUTO_INCREMENT PRIMARY KEY,
              agm_event_id INT NOT NULL,
              registration_number VARCHAR(40) NOT NULL,
              member_id INT NULL,
              user_id INT NULL,
              submitted_by_user_id INT NULL,
              attendee1_name VARCHAR(150) NOT NULL,
              attendee1_member_number VARCHAR(40) NULL,
              attendee1_is_over_65 TINYINT(1) NOT NULL DEFAULT 0,
              attendee2_name VARCHAR(150) NULL,
              attendee2_member_number VARCHAR(40) NULL,
              attendee2_is_over_65 TINYINT(1) NOT NULL DEFAULT 0,
              children_text TEXT NULL,
              contact_phone_1 VARCHAR(40) NULL,
              contact_phone_2 VARCHAR(40) NULL,
              address VARCHAR(255) NULL,
              postcode VARCHAR(20) NULL,
              email VARCHAR(150) NOT NULL,
              chapter VARCHAR(120) NULL,
              emergency_1_name VARCHAR(150) NULL,
              emergency_1_phone VARCHAR(40) NULL,
              emergency_2_name VARCHAR(150) NULL,
              emergency_2_phone VARCHAR(40) NULL,
              dietary_requirements TEXT NULL,
              custom_fields_json TEXT NULL,
              pricing_tier ENUM('early','late') NOT NULL DEFAULT 'early',
              subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              payment_method ENUM('stripe','bank_transfer','manual','comp') NOT NULL DEFAULT 'stripe',
              payment_status ENUM('pending','awaiting_bank_transfer','paid','refunded','cancelled') NOT NULL DEFAULT 'pending',
              stripe_session_id VARCHAR(120) NULL,
              stripe_payment_intent_id VARCHAR(120) NULL,
              paid_at DATETIME NULL,
              refunded_at DATETIME NULL,
              cancelled_at DATETIME NULL,
              admin_notes TEXT NULL,
              ip_address VARCHAR(64) NULL,
              user_agent VARCHAR(255) NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NULL,
              UNIQUE KEY uniq_agm_registration_number (registration_number),
              INDEX idx_agm_registrations_event (agm_event_id, payment_status, created_at),
              INDEX idx_agm_registrations_member (member_id),
              INDEX idx_agm_registrations_email (email),
              INDEX idx_agm_registrations_stripe_session (stripe_session_id),
              INDEX idx_agm_registrations_stripe_intent (stripe_payment_intent_id),
              CONSTRAINT fk_agm_registrations_event FOREIGN KEY (agm_event_id) REFERENCES agm_events(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'agm_registrations ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agm_registration_items (
              id INT AUTO_INCREMENT PRIMARY KEY,
              agm_registration_id INT NOT NULL,
              agm_product_id INT NULL,
              category VARCHAR(40) NOT NULL,
              name_snapshot VARCHAR(200) NOT NULL,
              choice_label_snapshot VARCHAR(200) NULL,
              unit_price DECIMAL(10,2) NOT NULL,
              pricing_tier_snapshot ENUM('early','late') NOT NULL DEFAULT 'early',
              quantity INT NOT NULL DEFAULT 1,
              line_total DECIMAL(10,2) NOT NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_agm_registration_items_reg (agm_registration_id),
              CONSTRAINT fk_agm_items_reg FOREIGN KEY (agm_registration_id) REFERENCES agm_registrations(id) ON DELETE CASCADE,
              CONSTRAINT fk_agm_items_product FOREIGN KEY (agm_product_id) REFERENCES agm_products(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'agm_registration_items ready';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS agm_registration_motorcycles (
              id INT AUTO_INCREMENT PRIMARY KEY,
              agm_registration_id INT NOT NULL,
              position TINYINT NOT NULL DEFAULT 1,
              owner_name VARCHAR(150) NULL,
              make VARCHAR(80) NULL,
              model VARCHAR(80) NULL,
              year_built SMALLINT NULL,
              registration_plate VARCHAR(40) NULL,
              is_trike TINYINT(1) NOT NULL DEFAULT 0,
              is_sidecar TINYINT(1) NOT NULL DEFAULT 0,
              has_trailer TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL,
              INDEX idx_agm_motos_reg (agm_registration_id, position),
              CONSTRAINT fk_agm_motos_reg FOREIGN KEY (agm_registration_id) REFERENCES agm_registrations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $applied[] = 'agm_registration_motorcycles ready';

        // Ensure the 'agm' payment channel exists (idempotent — seeded by
        // payments_module.sql on fresh installs, but older databases may not
        // have it yet).
        $pdo->exec("
            INSERT INTO payment_channels (code, label, is_active, created_at)
            VALUES ('agm', 'AGM', 0, NOW())
            ON DUPLICATE KEY UPDATE label = VALUES(label)
        ");
        $applied[] = 'agm payment channel ensured';

        $pdo->exec("
            INSERT INTO settings_payments (channel_id, mode, invoice_prefix, created_at)
            SELECT pc.id, 'test', 'AGM', NOW()
            FROM payment_channels pc
            WHERE pc.code = 'agm'
            ON DUPLICATE KEY UPDATE invoice_prefix = VALUES(invoice_prefix)
        ");
        $applied[] = 'agm settings_payments row ensured';

        // Seed the agm_manager role so the AGM coordinator can be granted
        // access without seeing membership or store finances. Permissions for
        // this role are registered in includes/admin_permissions.php.
        $pdo->exec("INSERT IGNORE INTO roles (name) VALUES ('agm_manager')");
        $applied[] = 'agm_manager role ensured';

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 019 — AGM schema', 'status' => 'applied', 'note' => implode(', ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 019 — AGM schema', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 020 — Seed Perth AGM 2026 event + product catalogue
//
// Source: 2026 AGM Rego Form v5.pdf — Friday 1 May – Sunday 3 May 2026 at
// Discovery Park Caversham, hosted by Perth Chapter. Registration closes
// 16 March 2026; late pricing kicks in 17 March 2026.
//
// Event is inserted in 'draft' status; publish it from
// /admin/agm/?tab=event when ready.
//
// Idempotent in two ways:
//   - the agm_events row uses ON DUPLICATE KEY UPDATE on (year, slug)
//   - the agm_products rows only seed when the event has zero products yet,
//     so a re-run won't blow away admin edits made after the first seed.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_020_seed_perth_2026';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 020 — Perth AGM 2026 seed', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $eventYear = 2026;
        $eventSlug = 'perth-2026';
        $bankInstructions = "Account Name: Australian GoldWing Association Inc.\n"
            . "Financial Institution: Bendigo Bank\n"
            . "BSB: 633-000\n"
            . "Account: 158 060 657\n"
            . "Reference: Surname and Member #\n\n"
            . "Postal address for posted registrations: 10 Thaxter Rd, Landsdale WA 6065";

        $upsertEvent = $pdo->prepare("
            INSERT INTO agm_events (
              year, slug, title, subtitle, hosting_chapter,
              venue_name, venue_address, venue_phone,
              start_date, end_date,
              registration_opens_at, registration_closes_at, late_fee_starts_at,
              contact_name, contact_phone, contact_email,
              bank_transfer_instructions, allow_bank_transfer, allow_stripe,
              status, is_current, stripe_account_key, created_at
            ) VALUES (
              :year, :slug, :title, :subtitle, :hosting_chapter,
              :venue_name, :venue_address, :venue_phone,
              :start_date, :end_date,
              NULL, :registration_closes_at, :late_fee_starts_at,
              :contact_name, :contact_phone, :contact_email,
              :bank_transfer_instructions, 1, 1,
              'draft', 0, 'agm', NOW()
            )
            ON DUPLICATE KEY UPDATE
              title = VALUES(title),
              subtitle = VALUES(subtitle),
              hosting_chapter = VALUES(hosting_chapter),
              venue_name = VALUES(venue_name),
              venue_address = VALUES(venue_address),
              venue_phone = VALUES(venue_phone),
              start_date = VALUES(start_date),
              end_date = VALUES(end_date),
              registration_closes_at = VALUES(registration_closes_at),
              late_fee_starts_at = VALUES(late_fee_starts_at),
              contact_name = VALUES(contact_name),
              contact_phone = VALUES(contact_phone),
              contact_email = VALUES(contact_email),
              bank_transfer_instructions = VALUES(bank_transfer_instructions),
              updated_at = NOW()
        ");
        $upsertEvent->execute([
            'year' => $eventYear,
            'slug' => $eventSlug,
            'title' => 'Perth AGM 2026',
            'subtitle' => 'Friday 1st May to Sunday 3rd May 2026',
            'hosting_chapter' => 'Perth Chapter',
            'venue_name' => 'Discovery Park Caversham',
            'venue_address' => '91 Benara Rd, Caversham WA',
            'venue_phone' => '08 9279 6700',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-03',
            'registration_closes_at' => '2026-03-16 23:59:00',
            'late_fee_starts_at' => '2026-03-17 00:00:00',
            'contact_name' => 'David Goodchild',
            'contact_phone' => '0417 987 742',
            'contact_email' => 'arnoldschraven@yahoo.com',
            'bank_transfer_instructions' => $bankInstructions,
        ]);
        $applied[] = 'event upserted';

        $eventIdStmt = $pdo->prepare("SELECT id FROM agm_events WHERE year = :year AND slug = :slug LIMIT 1");
        $eventIdStmt->execute(['year' => $eventYear, 'slug' => $eventSlug]);
        $eventId = (int) $eventIdStmt->fetchColumn();
        if ($eventId <= 0) {
            throw new RuntimeException('Could not resolve Perth 2026 event id.');
        }

        // Only seed products on the first run for this event — preserves
        // admin edits if migration is re-run later.
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM agm_products WHERE agm_event_id = :id");
        $countStmt->execute(['id' => $eventId]);
        $existingProductCount = (int) $countStmt->fetchColumn();

        if ($existingProductCount > 0) {
            $applied[] = "products already present (skipped seed; $existingProductCount rows kept)";
        } else {
            $fridayDinnerChoices = json_encode([
                'Lasagna',
                'Spaghetti Bolognese',
                'Tortellini Carbonara',
                'Italian Meatballs',
                'Vegetarian pasta bake',
            ]);
            $products = [
                // [category, name, description, early, late, member_only, non_member_only, requires_choice, choices_json, sort_order]
                ['registration', 'Member — Full registration', 'AGM dinner, patch & badge', 74.00, 89.00, 1, 0, 0, null, 10],
                ['registration', 'Non-member — Full registration', 'AGM dinner, patch & badge', 89.00, 104.00, 0, 1, 0, null, 20],
                ['registration', 'Member — Registration only', 'Patch & badge (no dinner)', 45.00, 60.00, 1, 0, 0, null, 30],
                ['registration', 'Non-member — Registration only', 'Patch & badge (no dinner)', 55.00, 70.00, 0, 1, 0, null, 40],
                ['merchandise', 'Cloth patch', null, 4.00, null, 0, 0, 0, null, 110],
                ['merchandise', 'Metal badge', null, 4.00, null, 0, 0, 0, null, 120],
                ['meal', 'Thursday night — sausage sanga & salad roll', 'Subsidised by Perth Chapter', 10.00, null, 0, 0, 0, null, 210],
                ['meal', 'Friday breakfast — bacon & egg roll', null, 12.00, null, 0, 0, 0, null, 220],
                ['meal', 'Friday dinner', 'Choose a main. Comes with dinner rolls & 3 salads.', 24.00, null, 0, 0, 1, $fridayDinnerChoices, 230],
                ['meal', 'Saturday breakfast — bacon & egg roll', null, 12.00, null, 0, 0, 0, null, 240],
                ['meal', 'Sunday breakfast — bacon & egg roll', null, 12.00, null, 0, 0, 0, null, 250],
            ];
            $insertProduct = $pdo->prepare("
                INSERT INTO agm_products
                    (agm_event_id, category, name, description, early_price, late_price,
                     member_only, non_member_only, requires_choice, choices_json,
                     per_registration_limit, sort_order, is_active, created_at)
                VALUES
                    (:event_id, :category, :name, :description, :early_price, :late_price,
                     :member_only, :non_member_only, :requires_choice, :choices_json,
                     :per_registration_limit, :sort_order, 1, NOW())
            ");
            foreach ($products as $p) {
                [$category, $name, $description, $early, $late, $memberOnly, $nonMemberOnly, $requiresChoice, $choicesJson, $sortOrder] = $p;
                $perRegLimit = $category === 'registration' ? 2 : null;
                $insertProduct->execute([
                    'event_id' => $eventId,
                    'category' => $category,
                    'name' => $name,
                    'description' => $description,
                    'early_price' => $early,
                    'late_price' => $late,
                    'member_only' => $memberOnly,
                    'non_member_only' => $nonMemberOnly,
                    'requires_choice' => $requiresChoice,
                    'choices_json' => $choicesJson,
                    'per_registration_limit' => $perRegLimit,
                    'sort_order' => $sortOrder,
                ]);
            }
            $applied[] = count($products) . ' products seeded';
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 020 — Perth AGM 2026 seed', 'status' => 'applied', 'note' => implode(', ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 020 — Perth AGM 2026 seed', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 021 — Grant AGM permissions to admin + agm_manager roles
//
// Migration 019 created the agm_manager role and migration 020 seeded the
// Perth 2026 event, but the actual permission keys (admin.agm.view,
// admin.agm.manage, admin.agm.settings, admin.agm.refund) were never
// inserted into role_permissions. Without those rows, current_admin_can()
// returns false for everyone — so the AGM sidebar entry is filtered out
// for every user (including the admin role).
//
// This migration seeds the rows:
//   admin       → all four AGM permissions (Webmaster sees everything)
//   agm_manager → all four AGM permissions (the role's whole purpose)
//
// Idempotent via ON DUPLICATE KEY UPDATE.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_021_agm_role_permissions';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 021 — AGM role permissions', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $grants = [
            'admin'       => ['admin.agm.view', 'admin.agm.manage', 'admin.agm.settings', 'admin.agm.refund'],
            'agm_manager' => ['admin.agm.view', 'admin.agm.manage', 'admin.agm.settings', 'admin.agm.refund'],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :perm, 1, NOW(), NOW() FROM roles r WHERE r.name = :role
             ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
        );
        $seeded = 0;
        $skippedRoles = [];
        foreach ($grants as $roleName => $perms) {
            // Confirm the role actually exists; agm_manager is created by 019
            // but if 019 was skipped on this server we'd silently no-op.
            $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
            $roleStmt->execute(['name' => $roleName]);
            if (!$roleStmt->fetchColumn()) {
                $skippedRoles[] = $roleName;
                continue;
            }
            foreach ($perms as $perm) {
                $stmt->execute(['perm' => $perm, 'role' => $roleName]);
                $seeded++;
            }
        }
        $note = $seeded . ' role-permission row(s) seeded';
        if ($skippedRoles) {
            $note .= '; role(s) missing: ' . implode(', ', $skippedRoles);
        }
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 021 — AGM role permissions', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 021 — AGM role permissions', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 018 — Sync chapter rep roles to chapters table
//
// Migration 015 seeded a fixed set of 13 chapter rep roles by name LIKE
// matching. Any chapter added since (or before-015 chapters that weren't on
// the original public list) had no rep role and so didn't show up in the
// admin role-picker.
//
// CommitteeService::syncChapterRoles() now keeps the catalog tied to the
// chapters table — each active chapter gets a "<Chapter> Area Rep" role
// with a sensible default email (ar.<slug>@goldwing.org.au), names follow
// chapter renames, and roles for deactivated chapters get marked inactive.
//
// This migration runs that sync once to backfill, and the same method is
// called automatically every time the chapter form is saved.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_018_sync_chapter_roles';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 018 — Sync chapter rep roles', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $stats = \App\Services\CommitteeService::syncChapterRoles();
        $note = sprintf(
            'added: %d, updated: %d, deactivated: %d',
            (int) $stats['added'],
            (int) $stats['updated'],
            (int) $stats['deactivated']
        );
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 018 — Sync chapter rep roles', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 018 — Sync chapter rep roles', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 019 — Sync users.name with members display name
//
// users.name is set once at login from the users table and is read by the
// admin sidebar ("Welcome <name>"), the user chip at the bottom of the
// sidebar, the topbar, and the "Recent Logins" panel. If an admin edits
// members.first_name / last_name, that field used to never get synced —
// they'd see the old name in the sidebar forever.
//
// Going forward MemberRepository::update() syncs users.name automatically
// whenever a name field changes. This migration runs a one-shot backfill so
// any drift that already accumulated (e.g. the Pat Lindley case) gets fixed
// without needing to re-save every profile.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_019_sync_users_name';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 019 — Sync users.name with members', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $stmt = $pdo->query("
            UPDATE users u
            JOIN members m ON m.user_id = u.id
            SET u.name = TRIM(CONCAT(COALESCE(m.first_name,''), ' ', COALESCE(m.last_name,'')))
            WHERE TRIM(CONCAT(COALESCE(m.first_name,''), ' ', COALESCE(m.last_name,''))) <> ''
              AND TRIM(CONCAT(COALESCE(m.first_name,''), ' ', COALESCE(m.last_name,''))) <> u.name
        ");
        $count = $stmt->rowCount();
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 019 — Sync users.name with members', 'status' => 'applied', 'note' => "$count user(s) re-synced from member display name"];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 019 — Sync users.name with members', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 022 — AGM Awards system (categories + winners + photos)
// Creates the trophy catalog, annual winners join table, and the per-winner
// photo gallery. Seeds the 16 trophy categories from the AGM master list,
// including the 5 memorial trophy names. Idempotent — re-running after seed
// is a no-op because the seed INSERT is gated on an empty award_categories.
//
// Feature toggle lives separately in settings_global (awards.feature_status).
// Default is "coming_soon" so the member-facing page renders a teaser until
// data is in place; admin flips to "live" via the Awards admin landing.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_022_awards_system';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 022 — AGM Awards system', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $pdo->exec("CREATE TABLE IF NOT EXISTS award_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sort_order INT NOT NULL DEFAULT 0,
            name VARCHAR(180) NOT NULL,
            group_label VARCHAR(120) NULL,
            memorial_trophy_name VARCHAR(180) NULL,
            description VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_award_categories_sort (sort_order),
            INDEX idx_award_categories_group (group_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $applied[] = 'award_categories table ready';

        $pdo->exec("CREATE TABLE IF NOT EXISTS award_winners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            year SMALLINT NOT NULL,
            member_id INT NULL,
            member_name_override VARCHAR(200) NULL,
            bike_description VARCHAR(255) NULL,
            notes VARCHAR(500) NULL,
            awarded_at DATE NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_award_winner_category_year (category_id, year),
            INDEX idx_award_winners_year (year),
            INDEX idx_award_winners_member (member_id),
            CONSTRAINT fk_award_winners_category FOREIGN KEY (category_id) REFERENCES award_categories(id) ON DELETE CASCADE,
            CONSTRAINT fk_award_winners_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $applied[] = 'award_winners table ready';

        $pdo->exec("CREATE TABLE IF NOT EXISTS award_winner_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            winner_id INT NOT NULL,
            media_path VARCHAR(512) NOT NULL,
            caption VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_award_winner_photos_winner (winner_id),
            CONSTRAINT fk_award_winner_photos_winner FOREIGN KEY (winner_id) REFERENCES award_winners(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $applied[] = 'award_winner_photos table ready';

        // Seed 16 categories ONLY if the table is empty so admin reorders survive
        // re-runs. apostrophe in "Greg O'Loughlin" needs SQL-style doubling.
        $countCats = (int) $pdo->query('SELECT COUNT(*) FROM award_categories')->fetchColumn();
        if ($countCats === 0) {
            $seeds = [
                [ 10, 'Best Original Classic Goldwing GL1000, GL1100, GL1200', 'Best Original Goldwing', null],
                [ 20, 'Best Original GL1500',                                  'Best Original Goldwing', null],
                [ 30, 'Best Original GL1800',                                  'Best Original Goldwing', null],
                [ 35, 'Best Original GL1800 Gen 3 (2018+)',                    'Best Original Goldwing', null],
                [ 40, 'Best Original F6B',                                     'Best Original Goldwing', null],
                [ 50, 'Best Custom Classic Goldwing GL1000, GL1100, GL1200',   'Best Custom Goldwing',   null],
                [ 60, 'Best Custom Goldwing GL1500',                           'Best Custom Goldwing',   null],
                [ 70, 'Best Custom Goldwing GL1800',                           'Best Custom Goldwing',   null],
                [ 75, 'Best Custom Goldwing GL1800 Gen 3 (2018+)',             'Best Custom Goldwing',   null],
                [ 80, 'Best Custom F6B',                                       'Best Custom Goldwing',   null],
                [ 90, 'Best Goldwing and Trailer',                             null,                     'Burden Memorial Trophy'],
                [100, 'Best Goldwing Trike',                                   null,                     null],
                [110, 'Best Goldwing and Sidecar',                             null,                     'Harry Ward Memorial Trophy'],
                [120, 'Best non-Goldwing',                                     null,                     null],
                [130, 'Longest Distance Travelled by an AGA Member over 65',   null,                     'Harry Gates Memorial Trophy'],
                [140, 'Longest Distance Travelled by an AGA Member',           null,                     null],
                [150, 'Longest Distance Pillion',                              null,                     'Shirley Ward Trophy'],
                [160, 'Peoples Choice Award',                                  null,                     "Greg O'Loughlin Memorial Trophy"],
                [170, 'Member of the Year',                                    null,                     null],
            ];
            $insertCat = $pdo->prepare('INSERT INTO award_categories (sort_order, name, group_label, memorial_trophy_name, is_active) VALUES (:sort_order, :name, :group_label, :memorial, 1)');
            foreach ($seeds as [$sort, $name, $group, $memorial]) {
                $insertCat->execute([
                    'sort_order' => $sort,
                    'name'       => $name,
                    'group_label'=> $group,
                    'memorial'   => $memorial,
                ]);
            }
            $applied[] = count($seeds) . ' trophy categories seeded';
        } else {
            $applied[] = "$countCats existing categories left untouched";
        }

        // Default feature toggle to coming_soon if not set yet.
        $existingStatus = SettingsService::getGlobal('awards.feature_status', null);
        if ($existingStatus === null) {
            SettingsService::setGlobal((int) $user['id'], 'awards.feature_status', 'coming_soon');
            $applied[] = 'feature toggle defaulted to coming_soon';
        } else {
            $applied[] = "feature toggle already set ($existingStatus)";
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 022 — AGM Awards system', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 022 — AGM Awards system', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 023 — Awards role permissions + Member of the Year category
//
// Migration 022 added admin.awards.view and admin.awards.manage to the
// permission registry, but role_permissions wasn't seeded — same blind
// spot as the AGM migration 021. Without those rows the AGM Awards
// sidebar entry is filtered out for everyone, including Webmaster.
//
// Also adds the existing Member of the Year recognition as award
// category #17 so it appears on the trophy wall alongside the AGM
// trophies. Idempotent — skips if a category named "Member of the
// Year" already exists.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_023_awards_perms_and_moty';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 023 — Awards perms + Member of the Year', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        // 1. Grant awards permissions to admin role.
        $grants = [
            'admin' => ['admin.awards.view', 'admin.awards.manage'],
        ];
        $grant = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_key, allowed, created_at, updated_at)
             SELECT r.id, :perm, 1, NOW(), NOW() FROM roles r WHERE r.name = :role
             ON DUPLICATE KEY UPDATE allowed = 1, updated_at = NOW()'
        );
        $seeded = 0;
        foreach ($grants as $roleName => $perms) {
            $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
            $roleStmt->execute(['name' => $roleName]);
            if (!$roleStmt->fetchColumn()) {
                continue;
            }
            foreach ($perms as $perm) {
                $grant->execute(['perm' => $perm, 'role' => $roleName]);
                $seeded++;
            }
        }
        $applied[] = "$seeded role-permission row(s) granted";

        // 2. Add Member of the Year as award category if not present.
        $existsStmt = $pdo->prepare("SELECT id FROM award_categories WHERE name = 'Member of the Year' LIMIT 1");
        $existsStmt->execute();
        if ($existsStmt->fetchColumn()) {
            $applied[] = 'Member of the Year category already present';
        } else {
            $insertCat = $pdo->prepare('INSERT INTO award_categories (sort_order, name, group_label, memorial_trophy_name, description, is_active) VALUES (:sort_order, :name, NULL, NULL, :description, 1)');
            $insertCat->execute([
                'sort_order' => 170,
                'name'       => 'Member of the Year',
                'description'=> 'Annual recognition for the member who has best embodied the spirit of the AGA over the past year. Nominated by fellow members.',
            ]);
            $applied[] = 'Member of the Year category seeded (sort 170)';
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 023 — Awards perms + Member of the Year', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 023 — Awards perms + Member of the Year', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 024 — Un-invert exclude_electronic on legacy-imported members
//
// scripts/data/generate_import_csvs.py wrote the source spreadsheet's
// "eDirectory" column (True = "include me in the eDirectory") straight into
// our `exclude_electronic` column without inverting it. So every legacy row
// has its electronic-directory opt-out flipped:
//
//   eDirectory=True  → exclude_electronic=1  (wrongly hidden — should show)
//   eDirectory=False → exclude_electronic=0  (wrongly visible — should hide)
//
// This is why the online directory shows ~40 out of 242 active members.
//
// Fix: flip exclude_electronic (and the parallel new column
// directory_pref_f_exclude_electronic_directory, if present) for every
// member. Cap the flip to members imported before this migration date —
// any rows created later were entered through the correct UI and are
// already correct.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_024_fix_inverted_exclude_electronic';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 024 — Fix inverted exclude_electronic', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        // Helper: does a column exist on members?
        // SHOW COLUMNS does not accept native-protocol placeholders, so
        // we inline the (whitelisted, hardcoded) column name directly.
        $hasCol = static function (string $col) use ($pdo): bool {
            $safe = preg_replace('/[^a-z0-9_]/i', '', $col);
            $s = $pdo->query("SHOW COLUMNS FROM members LIKE '$safe'");
            return $s && (bool) $s->fetch();
        };

        // Only flip rows that existed before this migration was written
        // (any later rows came through the corrected UI / import).
        // Hardcoded cutoff — no user input — so exec() with the literal
        // is safe and avoids the server's ATTR_EMULATE_PREPARES=false
        // prepared-statement parser quirks around compound expressions.
        $cutoff = "'2026-06-07 23:59:59'";

        if ($hasCol('exclude_electronic')) {
            $affected = $pdo->exec(
                "UPDATE members
                 SET exclude_electronic = CASE WHEN COALESCE(exclude_electronic, 0) = 1 THEN 0 ELSE 1 END,
                     updated_at = NOW()
                 WHERE created_at <= $cutoff"
            );
            $applied[] = (int) $affected . ' row(s) flipped on `exclude_electronic`';
        } else {
            $applied[] = '`exclude_electronic` column not present — skipped';
        }

        if ($hasCol('directory_pref_f_exclude_electronic_directory')) {
            $affected = $pdo->exec(
                "UPDATE members
                 SET directory_pref_f_exclude_electronic_directory = CASE WHEN COALESCE(directory_pref_f_exclude_electronic_directory, 0) = 1 THEN 0 ELSE 1 END,
                     updated_at = NOW()
                 WHERE created_at <= $cutoff"
            );
            $applied[] = (int) $affected . ' row(s) flipped on `directory_pref_f_exclude_electronic_directory`';
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 024 — Fix inverted exclude_electronic', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 024 — Fix inverted exclude_electronic', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 025 — Chapter abbreviation
// Adds an optional `abbreviation` column to the `chapters` table so that
// admins can supply a short code (e.g. FCC) that is rendered in front of
// each chapter name as "(FCC) Fraser Coast Chapter". Idempotent.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_025_chapter_abbreviation';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 025 — Chapter abbreviation', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $hasAbbreviation = (bool) $pdo->query("SHOW COLUMNS FROM chapters LIKE 'abbreviation'")->fetchColumn();
        if ($hasAbbreviation) {
            $applied[] = '`abbreviation` column already present';
        } else {
            try {
                $pdo->exec("ALTER TABLE chapters ADD COLUMN abbreviation VARCHAR(16) NULL AFTER name");
                $applied[] = '`abbreviation` column added';
            } catch (Throwable $e) {
                // Tolerate duplicate-column races so the migration is idempotent.
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
                $applied[] = '`abbreviation` column already present (caught duplicate)';
            }
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 025 — Chapter abbreviation', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 025 — Chapter abbreviation', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 026 — Profile change requests + members.join_date
// Adds an optional `join_date` column to `members` (separate from
// `created_at`, which is the row-insert timestamp and wrong for migrated
// members) plus a `member_profile_change_requests` table so members can
// request edits to selected fields and admins approve via the
// notification hub. Idempotent.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_026_profile_change_requests';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 026 — Profile change requests', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        // 1. Add members.join_date if missing.
        $hasJoinDate = (bool) $pdo->query("SHOW COLUMNS FROM members LIKE 'join_date'")->fetchColumn();
        if ($hasJoinDate) {
            $applied[] = '`members.join_date` already present';
        } else {
            try {
                $pdo->exec("ALTER TABLE members ADD COLUMN join_date DATE NULL AFTER created_at");
                $applied[] = '`members.join_date` column added';
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
                $applied[] = '`members.join_date` already present (caught duplicate)';
            }
        }

        // 2. Create member_profile_change_requests if missing.
        $hasTable = (bool) $pdo->query("SHOW TABLES LIKE 'member_profile_change_requests'")->fetchColumn();
        if ($hasTable) {
            $applied[] = '`member_profile_change_requests` table already present';
        } else {
            $pdo->exec(
                "CREATE TABLE member_profile_change_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    member_id INT NOT NULL,
                    field_name VARCHAR(64) NOT NULL,
                    current_value TEXT NULL,
                    requested_value TEXT NULL,
                    status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
                    rejection_reason TEXT NULL,
                    feedback_message TEXT NULL,
                    requested_at DATETIME NOT NULL,
                    approved_by INT NULL,
                    approved_at DATETIME NULL,
                    INDEX idx_mpcr_status_member (status, member_id),
                    INDEX idx_mpcr_requested_at (requested_at),
                    FOREIGN KEY (member_id) REFERENCES members(id),
                    FOREIGN KEY (approved_by) REFERENCES users(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $applied[] = '`member_profile_change_requests` table created';
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 026 — Profile change requests', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 026 — Profile change requests', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 027 — Stripe error logging table
// Adds `stripe_errors` so silent Stripe API failures and webhook silent-skips
// land somewhere queryable. Written by App\Services\StripeErrorLogger; read
// by admin diagnostics and manual SQL. See:
//   database/migrations/2026_06_07_stripe_errors.sql
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_027_stripe_errors';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 027 — Stripe error logging table', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_errors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            occurred_at DATETIME NOT NULL,
            source VARCHAR(80) NOT NULL,
            operation VARCHAR(80) NULL,
            stripe_account VARCHAR(40) NULL,
            error_code VARCHAR(80) NULL,
            error_message TEXT NULL,
            context_json MEDIUMTEXT NULL,
            related_order_id INT NULL,
            related_store_order_id INT NULL,
            related_stripe_session_id VARCHAR(120) NULL,
            related_stripe_pi_id VARCHAR(120) NULL,
            webhook_event_id INT NULL,
            INDEX idx_stripe_errors_occurred (occurred_at),
            INDEX idx_stripe_errors_source (source),
            INDEX idx_stripe_errors_order (related_order_id),
            INDEX idx_stripe_errors_store_order (related_store_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 027 — Stripe error logging table', 'status' => 'applied', 'note' => 'stripe_errors table created.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 027 — Stripe error logging table', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 028 — Backfill orders.order_number for legacy store orders
//
// OrderService::createOrder() historically omitted `order_number` from its
// INSERT, so every store-checkout `orders` row had NULL order_number even
// though the matching `store_orders` row carries the GW-… number. The
// create-path is fixed in the same commit as this migration.
//
// Backfill pulls the GW number out of `shipping_address_json.store_order_number`
// (the convention written at api/index.php:623, 1575 and store/checkout.php:236)
// and writes it into `orders.order_number`. Guarded against the UNIQUE
// constraint via a LEFT JOIN anti-pattern, so a retried checkout that produced
// two `orders` rows for one store_order doesn't blow up the UPDATE — the
// second row is left NULL for manual review.
//
// Idempotent: rerunning is a no-op (WHERE filters on order_number IS NULL).
// Affects only order_type = 'store' — membership rows use a separate path.
// See: database/migrations/2026_06_07_orders_order_number_backfill.sql
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_028_orders_order_number_backfill';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 028 — Backfill orders.order_number', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();

        // Count candidates before so the run note is meaningful.
        $candidates = (int) $pdo->query("
            SELECT COUNT(*) FROM orders
            WHERE order_type = 'store'
              AND order_number IS NULL
              AND shipping_address_json IS NOT NULL
        ")->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE orders o
            LEFT JOIN orders dup
              ON dup.order_number = JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.store_order_number'))
            SET o.order_number = JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.store_order_number'))
            WHERE o.order_type = 'store'
              AND o.order_number IS NULL
              AND o.shipping_address_json IS NOT NULL
              AND JSON_EXTRACT(o.shipping_address_json, '$.store_order_number') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.store_order_number')) <> ''
              AND dup.id IS NULL
        ");
        $stmt->execute();
        $updated = $stmt->rowCount();

        $remainingNull = (int) $pdo->query("
            SELECT COUNT(*) FROM orders
            WHERE order_type = 'store' AND order_number IS NULL
        ")->fetchColumn();

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $note = "{$updated} of {$candidates} store rows backfilled";
        if ($remainingNull > 0) {
            $note .= "; {$remainingNull} still NULL (no shipping_address_json, missing key, or UNIQUE collision — inspect manually)";
        }
        $results[] = ['label' => 'Migration 028 — Backfill orders.order_number', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 028 — Backfill orders.order_number', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 029 — Voided columns for orders + store_orders
//
// Adds voided_at / voided_by_user_id / voided_reason to both order tables so
// admins can soft-delete (void) orders from the UI. Voided orders are hidden
// from default admin lists; hard delete remains available as a separate action.
// See: database/migrations/2026_06_07_orders_voided.sql
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_029_orders_voided';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 029 — Voided columns on orders', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $addColumnIfMissing = function (string $table, string $column, string $ddl) use ($pdo, &$applied) {
            $exists = (bool) $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetchColumn();
            if ($exists) {
                $applied[] = "`{$table}.{$column}` already present";
                return;
            }
            try {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
                $applied[] = "`{$table}.{$column}` added";
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
                $applied[] = "`{$table}.{$column}` already present (caught duplicate)";
            }
        };

        $addIndexIfMissing = function (string $table, string $index, string $ddl) use ($pdo, &$applied) {
            $exists = (bool) $pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = " . $pdo->quote($index))->fetchColumn();
            if ($exists) {
                $applied[] = "`{$table}.{$index}` index already present";
                return;
            }
            try {
                $pdo->exec("ALTER TABLE {$table} ADD INDEX {$index} {$ddl}");
                $applied[] = "`{$table}.{$index}` index added";
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'exists') === false) {
                    throw $e;
                }
                $applied[] = "`{$table}.{$index}` index already present (caught duplicate)";
            }
        };

        foreach (['orders', 'store_orders'] as $table) {
            $addColumnIfMissing($table, 'voided_at', 'DATETIME NULL');
            $addColumnIfMissing($table, 'voided_by_user_id', 'INT NULL');
            $addColumnIfMissing($table, 'voided_reason', 'VARCHAR(255) NULL');
        }
        $addIndexIfMissing('orders', 'idx_orders_voided_at', '(voided_at)');
        $addIndexIfMissing('store_orders', 'idx_store_orders_voided_at', '(voided_at)');

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 029 — Voided columns on orders', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 029 — Voided columns on orders', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 030 — Add Quartermaster to committee_roles catalog
//
// Migration 015 seeds the national committee roles, but it's guarded by a
// migrationKey so editing its seed array won't add the new role on databases
// where 015 has already run. This migration inserts (or reactivates) the
// Quartermaster row directly. Idempotent on the slug.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_030_committee_quartermaster';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 030 — Quartermaster committee role', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO committee_roles (slug, name, category, chapter_id, email, phone, sort_order, is_active)
            VALUES ('national_quartermaster', 'Quartermaster', 'national', NULL, 'aga.quartermaster@goldwing.org.au', NULL, 55, 1)
            ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email),
              sort_order = VALUES(sort_order), is_active = 1
        ");
        $stmt->execute();
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 030 — Quartermaster committee role', 'status' => 'applied', 'note' => 'Quartermaster role added to committee_roles catalog.'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 030 — Quartermaster committee role', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 031 — Stripe product + invoice sync for store
//
// Adds `store_products.stripe_product_id` so each store product can be mirrored
// in Stripe's product catalog, and `store_orders.stripe_invoice_id` so we can
// link a finalized Stripe Invoice back to our order row from the
// `invoice.paid` webhook. Both columns are nullable VARCHAR(120) with indexes.
// See: database/migrations/2026_06_08_store_stripe_sync.sql
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_031_store_stripe_sync';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 031 — Stripe product + invoice sync (store)', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $addColumnIfMissing = function (string $table, string $column, string $ddl) use ($pdo, &$applied) {
            $exists = (bool) $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetchColumn();
            if ($exists) {
                $applied[] = "`{$table}.{$column}` already present";
                return;
            }
            try {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
                $applied[] = "`{$table}.{$column}` added";
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
                $applied[] = "`{$table}.{$column}` already present (caught duplicate)";
            }
        };

        $addIndexIfMissing = function (string $table, string $index, string $ddl) use ($pdo, &$applied) {
            $exists = (bool) $pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = " . $pdo->quote($index))->fetchColumn();
            if ($exists) {
                $applied[] = "`{$table}.{$index}` index already present";
                return;
            }
            try {
                $pdo->exec("ALTER TABLE {$table} ADD INDEX {$index} {$ddl}");
                $applied[] = "`{$table}.{$index}` index added";
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'exists') === false) {
                    throw $e;
                }
                $applied[] = "`{$table}.{$index}` index already present (caught duplicate)";
            }
        };

        $addColumnIfMissing('store_products', 'stripe_product_id', 'VARCHAR(120) NULL');
        $addIndexIfMissing('store_products', 'idx_store_products_stripe', '(stripe_product_id)');
        $addColumnIfMissing('store_orders', 'stripe_invoice_id', 'VARCHAR(120) NULL');
        $addIndexIfMissing('store_orders', 'idx_store_orders_stripe_invoice', '(stripe_invoice_id)');

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 031 — Stripe product + invoice sync (store)', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 031 — Stripe product + invoice sync (store)', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 032 — One-off renewal + last-payment backfill from legacy xlsx
//
// Source: scripts/data/migration_renewal_dates.csv (158 rows, derived from
// "Current AGA Members.xlsx" with columns Member #, Surname/First (M+A),
// Date Joined, Date Renewed, Expiry Date, Life Member).
//
// For each row:
//   • Match the main member by (member_number_base = X, suffix = 0);
//     fall back to first_name+last_name (case-insensitive). Same for the
//     associate when the xlsx row carries an A-side name pair.
//
//   • Renewal date  →  membership_periods.end_date on the latest period.
//       – LIFE members: skipped (renewal is N/A in the UI).
//       – No period at all → INSERT one (start = Date Joined, else 1y before
//         expiry; end = xlsx Expiry Date; status='ACTIVE').
//       – Period exists but end_date is NULL/0000 → UPDATE end_date.
//       – Period exists with an end_date → LEAVE ALONE (no clobber).
//
//   • Last payment  →  orders.paid_at on the latest order_type='membership'.
//       – No membership orders → INSERT a stub order (payment_status='accepted',
//         total=0, internal_notes flags it as a backfill). The admin "Last
//         payment" field reads from orders.paid_at, which is why we INSERT
//         rather than just stamping the period.
//       – Has any membership order → LEAVE ALONE.
//
//   • The associate uses date_renewed_a if present, else date_renewed_m.
//
// Once-off, idempotent (migration key guards re-runs). To re-trigger, clear the
// `migrations.migration_032_backfill_renewal_payment` global setting.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_032_backfill_renewal_payment';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 032 — Renewal + last-payment backfill', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $csvPath = __DIR__ . '/../../scripts/data/migration_renewal_dates.csv';
        if (!is_file($csvPath)) {
            throw new RuntimeException('CSV not found at ' . $csvPath);
        }

        // Stub orders need a payment_channels row. Pick the lowest id.
        $channelId = (int) ($pdo->query('SELECT MIN(id) FROM payment_channels')->fetchColumn() ?: 0);
        if ($channelId <= 0) {
            throw new RuntimeException('No payment_channels rows — cannot create stub orders.');
        }

        $findByNumber = $pdo->prepare(
            'SELECT id, member_type, member_number_suffix FROM members
             WHERE member_number_base = :base
             ORDER BY member_number_suffix ASC, id ASC'
        );
        $findByName = $pdo->prepare(
            "SELECT id, member_type FROM members
             WHERE LOWER(first_name) = LOWER(:fn) AND LOWER(last_name) = LOWER(:ln)
             ORDER BY (status = 'ACTIVE') DESC, id ASC LIMIT 1"
        );
        $findLatestPeriod = $pdo->prepare(
            'SELECT id, end_date FROM membership_periods
             WHERE member_id = :mid
             ORDER BY start_date DESC, id DESC LIMIT 1'
        );
        $insertPeriod = $pdo->prepare(
            "INSERT INTO membership_periods (member_id, term, start_date, end_date, status, created_at)
             VALUES (:mid, '1Y', :start, :end, 'ACTIVE', NOW())"
        );
        $updatePeriodEnd = $pdo->prepare(
            'UPDATE membership_periods SET end_date = :end WHERE id = :id'
        );
        $countMembershipOrders = $pdo->prepare(
            "SELECT COUNT(*) FROM orders WHERE member_id = :mid AND order_type = 'membership'"
        );
        $insertOrder = $pdo->prepare(
            "INSERT INTO orders
              (order_number, member_id, status, payment_status, fulfillment_status,
               order_type, channel_id, subtotal, total, paid_at, created_at, internal_notes)
             VALUES
              (:order_number, :mid, 'paid', 'accepted', 'active',
               'membership', :channel, 0, 0, :paid_at, :created_at,
               'Backfilled from legacy xlsx (migration_032)')"
        );

        $sub1YearBefore = static function (?string $iso): ?string {
            if (!$iso) return null;
            try {
                $d = new DateTime($iso);
                $d->modify('-1 year');
                return $d->format('Y-m-d');
            } catch (Throwable $e) {
                return null;
            }
        };

        $stats = [
            'rows' => 0,
            'main_by_number' => 0, 'main_by_name' => 0, 'main_unmatched' => 0,
            'assoc_by_number' => 0, 'assoc_by_name' => 0, 'assoc_unmatched' => 0,
            'periods_inserted' => 0, 'periods_filled' => 0, 'periods_kept' => 0, 'periods_life_skip' => 0,
            'orders_inserted' => 0, 'orders_kept' => 0, 'orders_no_date' => 0,
        ];
        $unmatched = [];

        // Apply one member's portion of a CSV row. Mutates $stats / $unmatched.
        $applyMember = function (
            ?array $member, ?string $renewedDate, ?string $joinedDate,
            ?string $matchedBy, string $tag, int $memberNumber,
            ?string $expiry, bool $rowLife
        ) use (
            &$stats, &$unmatched,
            $findLatestPeriod, $insertPeriod, $updatePeriodEnd,
            $countMembershipOrders, $insertOrder,
            $channelId, $sub1YearBefore
        ): void {
            if (!$member) {
                $stats[$tag . '_unmatched']++;
                $unmatched[] = '#' . $memberNumber . '/' . $tag;
                return;
            }
            $stats[$tag . '_by_' . ($matchedBy === 'number' ? 'number' : 'name')]++;
            $mid = (int) $member['id'];
            $memberIsLife = strtoupper((string) ($member['member_type'] ?? '')) === 'LIFE' || $rowLife;

            // 1) Renewal date → membership_periods.end_date
            if ($memberIsLife) {
                $stats['periods_life_skip']++;
            } elseif ($expiry) {
                $findLatestPeriod->execute([':mid' => $mid]);
                $period = $findLatestPeriod->fetch(PDO::FETCH_ASSOC);
                if (!$period) {
                    $startDate = $joinedDate ?: $sub1YearBefore($expiry) ?: date('Y-m-d');
                    $insertPeriod->execute([':mid' => $mid, ':start' => $startDate, ':end' => $expiry]);
                    $stats['periods_inserted']++;
                } elseif (empty($period['end_date']) || $period['end_date'] === '0000-00-00') {
                    $updatePeriodEnd->execute([':id' => (int) $period['id'], ':end' => $expiry]);
                    $stats['periods_filled']++;
                } else {
                    $stats['periods_kept']++;
                }
            }

            // 2) Last payment date → stub membership order if member has none
            if (!$renewedDate) {
                $stats['orders_no_date']++;
                return;
            }
            $countMembershipOrders->execute([':mid' => $mid]);
            if ((int) $countMembershipOrders->fetchColumn() > 0) {
                $stats['orders_kept']++;
                return;
            }
            $paidAt = $renewedDate . ' 00:00:00';
            $insertOrder->execute([
                ':order_number' => 'BACKFILL-' . $mid,
                ':mid'          => $mid,
                ':channel'      => $channelId,
                ':paid_at'      => $paidAt,
                ':created_at'   => $paidAt,
            ]);
            $stats['orders_inserted']++;
        };

        $fh = fopen($csvPath, 'r');
        if (!$fh) throw new RuntimeException('Cannot open CSV at ' . $csvPath);
        $header = fgetcsv($fh);
        if (!$header) throw new RuntimeException('CSV header missing');
        $col = array_flip($header);
        foreach (['member_number','surname_m','first_name_m','surname_a','first_name_a',
                  'date_joined_m','date_renewed_m','expiry_date',
                  'date_joined_a','date_renewed_a','life_member'] as $required) {
            if (!isset($col[$required])) {
                fclose($fh);
                throw new RuntimeException('CSV missing required column: ' . $required);
            }
        }

        while (($row = fgetcsv($fh)) !== false) {
            if (!$row || count($row) < count($header)) continue;
            $stats['rows']++;

            $memberNumber = (int) $row[$col['member_number']];
            $expiry   = $row[$col['expiry_date']]    !== '' ? $row[$col['expiry_date']]    : null;
            $rowLife  = (bool) (int) $row[$col['life_member']];
            $renewedM = $row[$col['date_renewed_m']] !== '' ? $row[$col['date_renewed_m']] : null;
            $renewedA = $row[$col['date_renewed_a']] !== '' ? $row[$col['date_renewed_a']] : $renewedM;
            $joinedM  = $row[$col['date_joined_m']]  !== '' ? $row[$col['date_joined_m']]  : null;
            $joinedA  = $row[$col['date_joined_a']]  !== '' ? $row[$col['date_joined_a']]  : $joinedM;
            $surnameM = trim((string) $row[$col['surname_m']]);
            $firstM   = trim((string) $row[$col['first_name_m']]);
            $surnameA = trim((string) $row[$col['surname_a']]);
            $firstA   = trim((string) $row[$col['first_name_a']]);

            // Member# lookup: collect main (suffix=0) and first associate (suffix>0)
            $findByNumber->execute([':base' => $memberNumber]);
            $bynum = $findByNumber->fetchAll(PDO::FETCH_ASSOC);
            $main = null; $assoc = null;
            foreach ($bynum as $r) {
                if ((int) $r['member_number_suffix'] === 0 && $main === null) {
                    $main = $r;
                } elseif ((int) $r['member_number_suffix'] > 0 && $assoc === null) {
                    $assoc = $r;
                }
            }

            $mainBy = $main ? 'number' : null;
            if (!$main && $firstM !== '' && $surnameM !== '') {
                $findByName->execute([':fn' => $firstM, ':ln' => $surnameM]);
                $hit = $findByName->fetch(PDO::FETCH_ASSOC);
                if ($hit) { $main = $hit; $mainBy = 'name'; }
            }

            $assocBy = $assoc ? 'number' : null;
            if (!$assoc && $firstA !== '' && $surnameA !== '') {
                $findByName->execute([':fn' => $firstA, ':ln' => $surnameA]);
                $hit = $findByName->fetch(PDO::FETCH_ASSOC);
                if ($hit) { $assoc = $hit; $assocBy = 'name'; }
            }

            $applyMember($main,  $renewedM, $joinedM, $mainBy,  'main',  $memberNumber, $expiry, $rowLife);
            if ($firstA !== '' && $surnameA !== '') {
                $applyMember($assoc, $renewedA, $joinedA, $assocBy, 'assoc', $memberNumber, $expiry, $rowLife);
            }
        }
        fclose($fh);

        $note = sprintf(
            'rows=%d · main #/name/miss=%d/%d/%d · assoc #/name/miss=%d/%d/%d · periods new/filled/kept=%d/%d/%d · life-skip=%d · orders new/kept/no-date=%d/%d/%d',
            $stats['rows'],
            $stats['main_by_number'], $stats['main_by_name'], $stats['main_unmatched'],
            $stats['assoc_by_number'], $stats['assoc_by_name'], $stats['assoc_unmatched'],
            $stats['periods_inserted'], $stats['periods_filled'], $stats['periods_kept'],
            $stats['periods_life_skip'],
            $stats['orders_inserted'], $stats['orders_kept'], $stats['orders_no_date']
        );
        if ($unmatched) {
            $note .= ' · unmatched: ' . implode(', ', array_slice($unmatched, 0, 8));
            if (count($unmatched) > 8) $note .= ' (+' . (count($unmatched) - 8) . ' more)';
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 032 — Renewal + last-payment backfill', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 032 — Renewal + last-payment backfill', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 033 — Reset notification bodies for payment + receipt templates
//
// The "membership_order_created" and "store_order_confirmation" templates
// had thin default bodies. NotificationService::definitions() now ships
// nicer defaults (big "Pay Now" button matching the welcome email + a
// proper itemised receipt structure). This migration rewrites the
// notifications.catalog row in settings_global with the saved `body`
// override stripped for those two templates, so the new code defaults
// take effect on production. Every other catalog field (subject,
// from_name, recipient_mode, custom_recipients, enabled flag, etc.) is
// preserved exactly as-is.
//
// We do the JSON manipulation in PHP rather than relying on JSON_REMOVE
// in MySQL so this works on the older MySQL/MariaDB builds that ship
// with some shared-hosting tiers.
//
// Safe to re-run — re-saving the same body-stripped JSON is a no-op.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_033_reset_payment_receipt_bodies';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 033 — Reset payment + receipt email bodies', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $catalog = SettingsService::getGlobal('notifications.catalog', []);
        if (!is_array($catalog)) {
            $catalog = [];
        }
        $stripped = [];
        foreach (['membership_order_created', 'store_order_confirmation'] as $key) {
            if (isset($catalog[$key]) && is_array($catalog[$key]) && array_key_exists('body', $catalog[$key])) {
                unset($catalog[$key]['body']);
                $stripped[] = $key;
            }
        }
        SettingsService::setGlobal((int) $user['id'], 'notifications.catalog', $catalog);
        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $note = $stripped
            ? 'Cleared saved body override on: ' . implode(', ', $stripped) . '. New defaults from NotificationService now apply.'
            : 'No saved body overrides found — defaults already in use.';
        $results[] = [
            'label' => 'Migration 033 — Reset payment + receipt email bodies',
            'status' => 'applied',
            'note' => $note,
        ];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 033 — Reset payment + receipt email bodies', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 034 — Track refund amounts on the membership `refunds` table
//
// The membership refunds table was full-refund-only: one row per refund,
// no amount column. We now support partial refunds (RefundService::
// processMembershipRefund) and need per-row amount + status tracking so
// the "refundable" calculation on the order page works correctly and
// multiple partial refunds can stack.
//
// Adds two nullable columns:
//   • amount_cents INT NULL — the cents refunded by this row.
//   • status VARCHAR(20) NULL — 'processed' (default for new rows);
//     reserved for future 'pending' / 'failed' states.
//
// Pre-existing rows stay NULL. RefundService falls back to "full refund
// of the order total" semantics for any historical rows that have no
// amount_cents.
//
// Idempotent.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_034_refunds_amount_cents';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 034 — Membership refund amounts', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();
        $applied = [];

        $addColumnIfMissing = function (string $table, string $column, string $ddl) use ($pdo, &$applied) {
            $exists = (bool) $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column))->fetchColumn();
            if ($exists) {
                $applied[] = "`{$table}.{$column}` already present";
                return;
            }
            try {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
                $applied[] = "`{$table}.{$column}` added";
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
                $applied[] = "`{$table}.{$column}` already present (caught duplicate)";
            }
        };

        $addColumnIfMissing('refunds', 'amount_cents', 'INT NULL AFTER reason');
        $addColumnIfMissing('refunds', 'status', "VARCHAR(20) NULL AFTER amount_cents");

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 034 — Membership refund amounts', 'status' => 'applied', 'note' => implode(' · ', $applied)];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 034 — Membership refund amounts', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 033 — Backfill members.is_historic from legacy xlsx Historic flags
//
// The legacy "Current AGA Members.xlsx" carries a Historic(M) / Historic(A)
// boolean per row. The original CSV import dropped this field (everyone came
// in with is_historic = 0). This migration sets is_historic = 1 for the 8 main
// + 12 associate members the xlsx flags as True. Per-member-number lists are
// embedded directly because the dataset is tiny and stable; no CSV needed.
//
// Match strategy: member_number_base = X AND member_number_suffix = 0 for the
// main, AND member_number_suffix > 0 for the associate. Idempotent (UPDATE
// re-runs are no-ops; the migration key also guards re-runs).
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_033_backfill_is_historic';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 033 — Historic-rego backfill', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();

        // Source-of-truth lists extracted from the xlsx (Historic(M) / Historic(A) = True).
        $mainNumbers  = [790, 795, 863, 949, 1312, 1457, 1560, 1707];
        $assocNumbers = [148, 795, 863, 949, 1352, 1372, 1376, 1457, 1569, 1601, 1687, 1698];

        $updateMain = $pdo->prepare(
            'UPDATE members SET is_historic = 1, updated_at = NOW()
             WHERE member_number_base = :base AND member_number_suffix = 0'
        );
        $updateAssoc = $pdo->prepare(
            'UPDATE members SET is_historic = 1, updated_at = NOW()
             WHERE member_number_base = :base AND member_number_suffix > 0'
        );

        $mainHits = 0; $mainMisses = []; $assocHits = 0; $assocMisses = [];
        foreach ($mainNumbers as $n) {
            $updateMain->execute([':base' => $n]);
            if ($updateMain->rowCount() > 0) { $mainHits++; } else { $mainMisses[] = $n; }
        }
        foreach ($assocNumbers as $n) {
            $updateAssoc->execute([':base' => $n]);
            if ($updateAssoc->rowCount() > 0) { $assocHits++; } else { $assocMisses[] = $n; }
        }

        $note = sprintf(
            'main flagged: %d/%d · assoc flagged: %d/%d',
            $mainHits, count($mainNumbers), $assocHits, count($assocNumbers)
        );
        if ($mainMisses || $assocMisses) {
            $note .= ' · misses:';
            if ($mainMisses)  { $note .= ' main=' . implode(',', $mainMisses); }
            if ($assocMisses) { $note .= ' assoc=' . implode(',', $assocMisses); }
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $results[] = ['label' => 'Migration 033 — Historic-rego backfill', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 033 — Historic-rego backfill', 'status' => 'error', 'note' => $e->getMessage()];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MIGRATION 024 — Historical trophy winners import (2005–2026)
//
// Loads the 130 winners scraped from the Wings magazine archive
// (scripts/data/wings_trophy_winners.csv) plus 16 winners from the 2026
// Bunbury AGM (transcribed from the trophy-winners slide). For each row we:
//
//   1. Decide the final category — most rows map 1:1 to the seeded list,
//      but 2024 + 2026 split GL1800 into "up to 2017" and "Gen 3 (2018+)"
//      sub-categories. Those need two extra category rows so the unique
//      (category_id, year) constraint doesn't reject the second winner.
//
//   2. Fuzzy-match member_name against members.first_name + last_name
//      (LOWER, exact match). If we find a unique match, set member_id; if
//      multiple ACTIVE matches, pick the first; if none, fall back to
//      member_name_override so the historical record is preserved either
//      way.
//
//   3. Insert with ON DUPLICATE KEY UPDATE so re-running this migration
//      after a server replay is safe — already-imported rows are touched
//      (updated_at bumps) but not duplicated.
//
// Skip heuristics: names containing "1st " / "2nd ", names < 3 chars, or
// names that don't look like a person ("the", "male'", body-text fragments).
// The CSV's source_pdf + source_page columns are kept in the notes field
// so admins can find the original magazine page if a row needs editing.
// ─────────────────────────────────────────────────────────────────────────────
$migrationKey = 'migration_024_historical_winners_import';
$alreadyRun   = SettingsService::getGlobal('migrations.' . $migrationKey, false);

if ($alreadyRun) {
    $results[] = ['label' => 'Migration 024 — Historical winners import', 'status' => 'skipped', 'note' => 'Already applied.'];
} else {
    try {
        $pdo = db();

        // ── 1. Add the 2 Gen 3 sub-categories ─────────────────────────────
        $genCats = [
            ['Best Original GL1800 Gen 3 (2018+)',        35,  null],
            ['Best Custom Goldwing GL1800 Gen 3 (2018+)', 75,  null],
        ];
        $existsCat = $pdo->prepare('SELECT id FROM award_categories WHERE name = :name LIMIT 1');
        $insertCat = $pdo->prepare('INSERT INTO award_categories (sort_order, name, group_label, memorial_trophy_name, is_active) VALUES (:sort_order, :name, :group_label, NULL, 1)');
        $genAdded = 0;
        foreach ($genCats as [$name, $sort, $group]) {
            $existsCat->execute(['name' => $name]);
            if ($existsCat->fetchColumn()) {
                continue;
            }
            $insertCat->execute([
                'sort_order'  => $sort,
                'name'        => $name,
                'group_label' => 'Best Original Goldwing', // routes them under the right group on the wall
            ]);
            $genAdded++;
        }
        // Fix the Custom Gen 3 group label to "Best Custom Goldwing" after insert
        // (the loop above puts both under Original because both share the prefix).
        $pdo->exec("UPDATE award_categories SET group_label = 'Best Custom Goldwing' WHERE name = 'Best Custom Goldwing GL1800 Gen 3 (2018+)'");

        // ── 2. Build a name → member_id lookup for fuzzy matching ─────────
        // We index by lower-case "first last" so each insert is a single
        // O(1) hash lookup instead of an N+1 query against the members
        // table. Active members win ties.
        $nameLookup = [];
        $memberRows = $pdo->query("
            SELECT id, first_name, last_name, status
            FROM members
            ORDER BY (status = 'ACTIVE') DESC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($memberRows as $mr) {
            $key = strtolower(trim(($mr['first_name'] ?? '') . ' ' . ($mr['last_name'] ?? '')));
            if ($key === '' || isset($nameLookup[$key])) {
                continue;
            }
            $nameLookup[$key] = (int) $mr['id'];
        }

        // ── 3. Get the category lookup ────────────────────────────────────
        $catLookup = [];
        foreach ($pdo->query('SELECT id, name FROM award_categories')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cr) {
            $catLookup[$cr['name']] = (int) $cr['id'];
        }
        $resolveCat = function (string $name) use ($catLookup): ?int {
            return $catLookup[$name] ?? null;
        };

        // Categories used by the importer — make sure they all resolved.
        $required = [
            'Best Original Classic Goldwing GL1000, GL1100, GL1200',
            'Best Original GL1500',
            'Best Original GL1800',
            'Best Original GL1800 Gen 3 (2018+)',
            'Best Original F6B',
            'Best Custom Classic Goldwing GL1000, GL1100, GL1200',
            'Best Custom Goldwing GL1500',
            'Best Custom Goldwing GL1800',
            'Best Custom Goldwing GL1800 Gen 3 (2018+)',
            'Best Custom F6B',
            'Best Goldwing and Trailer',
            'Best Goldwing Trike',
            'Best Goldwing and Sidecar',
            'Best non-Goldwing',
            'Longest Distance Travelled by an AGA Member over 65',
            'Longest Distance Travelled by an AGA Member',
            'Longest Distance Pillion',
            'Peoples Choice Award',
            'Member of the Year',
        ];
        $missing = [];
        foreach ($required as $r) {
            if (!isset($catLookup[$r])) {
                $missing[] = $r;
            }
        }
        if ($missing) {
            throw new RuntimeException('Award categories not seeded: ' . implode(', ', $missing));
        }

        // ── 4. Helpers ────────────────────────────────────────────────────
        // Heuristics for filtering out the handful of garbage rows the
        // PDF scraper couldn't fully clean up.
        $isLikelyName = function (string $name): bool {
            $n = trim($name);
            if (mb_strlen($n) < 3) return false;
            if (preg_match('/^\d/', $n)) return false;                  // starts with number
            if (stripos($n, '1st ') !== false || stripos($n, '2nd ') !== false) return false;
            $junk = ['the', 'male', 'female', 'rider', 'pillion', 'award', 'and', 'trophy', 'a g'];
            if (in_array(strtolower($n), $junk, true)) return false;
            if (!preg_match('/^[A-Z]/', $n)) return false;              // names start capital
            // Need at least one space (first + last) OR be a known single-word
            // last-name-only edge case — we just require the space here.
            if (strpos($n, ' ') === false) return false;
            return true;
        };

        $genThreeFromRaw = function (string $rawLine): bool {
            // Detects the "2018+" / "Gen 3" sub-category marker that the
            // scraper saw in the raw line but couldn't route on its own.
            return (bool) preg_match('/2018\+|gen\s*3/i', $rawLine);
        };

        // Insert/upsert prepared statement — ON DUPLICATE so re-runs are safe.
        $insertWinner = $pdo->prepare("
            INSERT INTO award_winners
                (category_id, year, member_id, member_name_override, bike_description, notes, created_by_user_id)
            VALUES
                (:category_id, :year, :member_id, :override, :bike, :notes, :actor)
            ON DUPLICATE KEY UPDATE
                member_id = COALESCE(VALUES(member_id), member_id),
                member_name_override = COALESCE(VALUES(member_name_override), member_name_override),
                bike_description = COALESCE(NULLIF(VALUES(bike_description), ''), bike_description),
                notes = COALESCE(NULLIF(VALUES(notes), ''), notes),
                updated_at = NOW()
        ");

        // ── 5. Process CSV ────────────────────────────────────────────────
        $csvPath = __DIR__ . '/../../scripts/data/wings_trophy_winners.csv';
        $stats = ['csv_total' => 0, 'csv_imported' => 0, 'csv_matched' => 0, 'csv_override' => 0, 'csv_skipped' => 0];
        $skipped = [];

        if (!is_file($csvPath)) {
            throw new RuntimeException('CSV not found at expected path: ' . $csvPath);
        }
        $fh = fopen($csvPath, 'r');
        if (!$fh) {
            throw new RuntimeException('Could not open CSV: ' . $csvPath);
        }
        $header = fgetcsv($fh);
        // Map column names → indices so we don't hard-code positions.
        $col = array_flip($header);

        while (($row = fgetcsv($fh)) !== false) {
            $stats['csv_total']++;
            $year     = (int) ($row[$col['year']] ?? 0);
            $catName  = trim((string) ($row[$col['category_name']] ?? ''));
            $name     = trim((string) ($row[$col['member_name']] ?? ''));
            $bike     = trim((string) ($row[$col['bike_description']] ?? ''));
            $notes    = trim((string) ($row[$col['notes']] ?? ''));
            $sourcePdf  = trim((string) ($row[$col['source_pdf']] ?? ''));
            $sourcePage = trim((string) ($row[$col['source_page']] ?? ''));
            $rawLine  = trim((string) ($row[$col['raw_line']] ?? ''));

            if ($year < 1990 || $year > 2030 || $catName === '' || $name === '') {
                $stats['csv_skipped']++; $skipped[] = "$year $catName: missing field";
                continue;
            }
            if (!$isLikelyName($name)) {
                $stats['csv_skipped']++; $skipped[] = "$year $catName: not a name ($name)";
                continue;
            }

            // Re-route GL1800 winners to Gen 3 sub-categories when the raw
            // line had a "2018+" or "Gen 3" marker.
            $effectiveCat = $catName;
            if (in_array($catName, ['Best Original GL1800', 'Best Custom Goldwing GL1800'], true) && $genThreeFromRaw($rawLine)) {
                $effectiveCat = $catName . ' Gen 3 (2018+)';
                $effectiveCat = ($catName === 'Best Original GL1800')
                    ? 'Best Original GL1800 Gen 3 (2018+)'
                    : 'Best Custom Goldwing GL1800 Gen 3 (2018+)';
            }

            $catId = $resolveCat($effectiveCat);
            if (!$catId) {
                $stats['csv_skipped']++; $skipped[] = "$year $catName: unknown category";
                continue;
            }

            $lookupKey = strtolower($name);
            $memberId = $nameLookup[$lookupKey] ?? null;

            // Annotate notes with source so an admin can find the original page.
            $sourceTag = $sourcePdf !== '' ? 'src: ' . basename($sourcePdf) . ($sourcePage !== '' ? ' p' . $sourcePage : '') : '';
            $finalNotes = trim($notes . ($notes !== '' && $sourceTag !== '' ? ' · ' : '') . $sourceTag);

            $insertWinner->execute([
                'category_id' => $catId,
                'year'        => $year,
                'member_id'   => $memberId,
                'override'    => $memberId ? null : $name,
                'bike'        => $bike !== '' ? $bike : null,
                'notes'       => $finalNotes !== '' ? $finalNotes : null,
                'actor'       => (int) $user['id'],
            ]);
            $stats['csv_imported']++;
            $memberId ? $stats['csv_matched']++ : $stats['csv_override']++;
        }
        fclose($fh);

        // ── 6. Inline 2026 winners from the AGM trophy slide ──────────────
        // Year is 2026 because the screenshot heading says "2026 AGM".
        // Rows with "-" mean no winner — those are skipped intentionally.
        $winners2026 = [
            // [category_name,                                            member_name,       bike_description,         notes]
            ['Best Original Classic Goldwing GL1000, GL1100, GL1200',     'Peter Wilkinson', 'GL1000',                 ''],
            ['Best Original GL1800',                                      'Julie Collins',   '',                       ''],
            ['Best Original GL1800 Gen 3 (2018+)',                        'Rob Watson',      '',                       ''],
            ['Best Custom Goldwing GL1800',                               'Greg Naylor',     '',                       ''],
            ['Best Custom Goldwing GL1800 Gen 3 (2018+)',                 'Ian Kennedy',     '',                       ''],
            ['Best Goldwing and Sidecar',                                 'Les Sorenson',    'GL1500',                 ''],
            ['Best Goldwing and Trailer',                                 'Mark Johannesen', 'GL1800D + Shadow',       ''],
            ['Best Goldwing Trike',                                       'David Goodchild', 'GL1800 CSC',             ''],
            ['Best non-Goldwing',                                         'Marty Vesperman', 'Honda ST1300 2003',      ''],
            ['Longest Distance Travelled by an AGA Member',               'Stephen Veitch',  'GL1800',                 '4362 km'],
            ['Longest Distance Travelled by an AGA Member over 65',       'Rob Watson',      'GL1800D',                '4380 km'],
            ['Longest Distance Pillion',                                  'Gail Jones',      'GL1800 Trike',           '4263 km'],
            ['Member of the Year',                                        'Colin Strong',    '',                       ''],
            ['Peoples Choice Award',                                      'Peter Wilkinson', 'GL1000',                 ''],
        ];
        $stats2026 = ['imported' => 0, 'matched' => 0, 'override' => 0];
        foreach ($winners2026 as [$cn, $nm, $bd, $nt]) {
            $cid = $resolveCat($cn);
            if (!$cid) { continue; }
            $key = strtolower($nm);
            $mid = $nameLookup[$key] ?? null;
            $insertWinner->execute([
                'category_id' => $cid,
                'year'        => 2026,
                'member_id'   => $mid,
                'override'    => $mid ? null : $nm,
                'bike'        => $bd !== '' ? $bd : null,
                'notes'       => $nt !== '' ? $nt : null,
                'actor'       => (int) $user['id'],
            ]);
            $stats2026['imported']++;
            $mid ? $stats2026['matched']++ : $stats2026['override']++;
        }

        SettingsService::setGlobal((int) $user['id'], 'migrations.' . $migrationKey, true);
        $note = sprintf(
            'gen3 cats added: %d · CSV: %d/%d imported (matched %d, override %d, skipped %d) · 2026: %d imported (matched %d, override %d)',
            $genAdded,
            $stats['csv_imported'], $stats['csv_total'],
            $stats['csv_matched'], $stats['csv_override'], $stats['csv_skipped'],
            $stats2026['imported'], $stats2026['matched'], $stats2026['override']
        );
        $results[] = ['label' => 'Migration 024 — Historical winners import', 'status' => 'applied', 'note' => $note];
    } catch (Throwable $e) {
        $results[] = ['label' => 'Migration 024 — Historical winners import', 'status' => 'error', 'note' => $e->getMessage()];
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
