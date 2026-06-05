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
