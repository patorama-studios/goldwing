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
