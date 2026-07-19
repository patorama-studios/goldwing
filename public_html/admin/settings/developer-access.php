<?php
/**
 * Developer Access — handover lockout control panel.
 *
 * Lets the club webmaster grant the developer's admin account a timed access
 * window (default 7 days), revoke it early, and switch the whole lockout on
 * or off. While the lockout is ON and no window is active, the developer
 * account cannot sign in and any live session is ended on its next request
 * (see DeveloperAccessService + the bootstrap gate).
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\DeveloperAccessService;

require_permission('admin.settings.general.manage');

$user = current_user();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/settings/developer-access.php');
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $cfg = DeveloperAccessService::config();
        $isDevAccount = strtolower(trim((string) ($user['email'] ?? ''))) === $cfg['email'];
        $action = (string) ($_POST['do'] ?? '');
        if ($isDevAccount && $cfg['enabled']) {
            // Governance: once handed over, the developer cannot self-serve access.
            $error = 'The developer account cannot change its own access. A club webmaster must do this.';
        } elseif ($action === 'grant') {
            $days = (int) ($_POST['days'] ?? DeveloperAccessService::DEFAULT_DAYS);
            $expires = DeveloperAccessService::grant((int) $user['id'], $days);
            $message = 'Developer access granted until ' . date('l j F Y, g:ia', strtotime($expires)) . '. The developer has been emailed.';
        } elseif ($action === 'revoke') {
            DeveloperAccessService::revoke((int) $user['id']);
            $message = 'Developer access revoked. Any live developer session ends on its next page load.';
        } elseif ($action === 'enable_lockout') {
            DeveloperAccessService::setLockoutEnabled((int) $user['id'], true);
            $message = 'Handover lockout is now ON. The developer account can only sign in during a granted window.';
        } elseif ($action === 'disable_lockout') {
            DeveloperAccessService::setLockoutEnabled((int) $user['id'], false);
            $message = 'Handover lockout is now OFF. The developer account has normal, permanent access.';
        } elseif ($action === 'save_email') {
            $email = trim((string) ($_POST['dev_email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address for the developer account.';
            } else {
                DeveloperAccessService::setEmail((int) $user['id'], $email);
                $message = 'Developer account email updated.';
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$cfg = DeveloperAccessService::config();
$windowActive = DeveloperAccessService::windowActive();
$history = DeveloperAccessService::history();
$expiresTs = $cfg['expires_at'] !== '' ? strtotime($cfg['expires_at']) : false;

if (!$cfg['enabled']) {
    $pill = ['bg-amber-50 text-amber-700', 'bg-amber-500', 'Lockout off — developer has full access'];
} elseif ($windowActive) {
    $pill = ['bg-green-50 text-green-700', 'bg-green-500', 'Access window open until ' . date('D j M, g:ia', (int) $expiresTs)];
} else {
    $pill = ['bg-red-50 text-red-700', 'bg-red-500', 'Locked — developer cannot sign in'];
}

$actionLabels = [
    'security.dev_access_granted' => 'Access granted',
    'security.dev_access_revoked' => 'Access revoked',
    'security.dev_access_lockout_enabled' => 'Lockout switched ON',
    'security.dev_access_lockout_disabled' => 'Lockout switched OFF',
    'security.dev_access_login_denied' => 'Blocked login attempt',
    'security.dev_access_session_ended' => 'Live session ended',
    'security.dev_access_email_changed' => 'Developer email changed',
];

$pageTitle = 'Developer Access';
$activePage = 'settings-developer-access';

require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Developer Access'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($message): ?>
        <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($error) ?></div>
      <?php endif; ?>

      <section class="space-y-6">
        <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
          <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
              <h1 class="font-display text-2xl font-bold text-gray-900">Developer Access</h1>
              <nav class="mt-1 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="/admin/" class="hover:text-slate-700">Admin</a>
                <span class="text-slate-300">/</span>
                <a href="/admin/settings/" class="hover:text-slate-700">Settings</a>
                <span class="text-slate-300">/</span>
                <span class="font-semibold text-gray-900 border-b-2 border-primary pb-0.5">Developer Access</span>
              </nav>
            </div>
            <div class="inline-flex items-center gap-2 rounded-full <?= $pill[0] ?> px-3 py-1.5 text-xs font-semibold whitespace-nowrap">
              <span class="h-2 w-2 rounded-full <?= $pill[1] ?>"></span>
              <span><?= e($pill[2]) ?></span>
            </div>
          </div>
        </div>

        <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-3">
          <div class="flex items-start gap-3">
            <span class="material-icons-outlined text-slate-500">vpn_key</span>
            <div class="text-sm text-slate-600 space-y-2">
              <h2 class="font-display text-lg font-bold text-gray-900">How this works</h2>
              <p>The website was built and is maintained by an outside developer. After handover, the developer's admin login
                 (<code class="bg-gray-100 px-1.5 py-0.5 rounded"><?= e($cfg['email']) ?></code>) is <strong>locked by default</strong>.
                 When you need the developer to do work, grant an access window below — one week is the usual choice.
                 When the window ends (or you revoke it), the developer is locked out again automatically and any
                 session they still have open is ended.</p>
              <p>Every grant, revoke and blocked login attempt is recorded in the history below and in the
                 <a href="/admin/audit/" class="text-amber-700 hover:underline">audit hub</a>. Granting access emails the developer automatically.</p>
            </div>
          </div>
        </div>

        <?php if (!$cfg['enabled']): ?>
          <div class="bg-amber-50 rounded-2xl border border-amber-200 p-6 space-y-4">
            <div class="flex items-start gap-3">
              <span class="material-icons-outlined text-amber-600">warning_amber</span>
              <div class="text-sm text-amber-900 space-y-2">
                <h2 class="font-display text-lg font-bold">Handover lockout is currently OFF</h2>
                <p>The developer account has normal, permanent admin access. This is the correct state <strong>before</strong> handover.
                   On handover day, switch the lockout on — from then the developer can only sign in during a window you grant.</p>
                <p class="text-xs">If you enable this while signed in as the developer account with no access window granted, you will be signed out immediately.</p>
              </div>
            </div>
            <form method="post" onsubmit="return confirm('Switch handover lockout ON? The developer account will immediately require a granted access window to sign in.');">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="do" value="enable_lockout">
              <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white px-4 py-2.5 text-sm font-semibold">
                <span class="material-icons-outlined text-base">lock</span> Enable handover lockout
              </button>
            </form>
          </div>
        <?php else: ?>
          <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
            <div class="flex items-start gap-3">
              <span class="material-icons-outlined text-slate-500">schedule</span>
              <div>
                <h2 class="font-display text-lg font-bold text-gray-900">Access window</h2>
                <p class="text-sm text-slate-500">
                  <?php if ($windowActive): ?>
                    The developer currently has access until <strong><?= e(date('l j F Y, g:ia', (int) $expiresTs)) ?></strong>.
                    Granting again extends the window from now; revoking ends it immediately.
                  <?php else: ?>
                    The developer account is locked. Grant a window when you need work done.
                  <?php endif; ?>
                </p>
              </div>
            </div>
            <div class="flex flex-wrap items-end gap-3">
              <form method="post" class="flex items-end gap-3">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="do" value="grant">
                <div>
                  <label for="days" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Window length</label>
                  <select id="days" name="days" class="mt-2 block rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
                    <option value="1">1 day</option>
                    <option value="3">3 days</option>
                    <option value="7" selected>1 week (recommended)</option>
                    <option value="14">2 weeks</option>
                    <option value="30">30 days</option>
                  </select>
                </div>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-secondary hover:bg-emerald-700 text-white px-4 py-2.5 text-sm font-semibold">
                  <span class="material-icons-outlined text-base">lock_open</span>
                  <?= $windowActive ? 'Extend access' : 'Grant access' ?>
                </button>
              </form>
              <?php if ($windowActive): ?>
                <form method="post" onsubmit="return confirm('Revoke developer access now? Their session ends on their next page load.');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="do" value="revoke">
                  <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 text-sm font-semibold">
                    <span class="material-icons-outlined text-base">lock</span> Revoke access now
                  </button>
                </form>
              <?php endif; ?>
            </div>
            <form method="post" class="pt-2 border-t border-gray-100" onsubmit="return confirm('Switch handover lockout OFF? The developer account returns to normal, permanent access. Only do this on the developer\'s advice.');">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="do" value="disable_lockout">
              <button type="submit" class="text-xs text-slate-400 hover:text-red-600 underline">Switch handover lockout off (not recommended)</button>
            </form>
          </div>
        <?php endif; ?>

        <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
          <div class="flex items-start gap-3">
            <span class="material-icons-outlined text-slate-500">badge</span>
            <div>
              <h2 class="font-display text-lg font-bold text-gray-900">Gated developer account</h2>
              <p class="text-sm text-slate-500">The admin login covered by the lockout. Change this only when the club appoints a new developer or agency.</p>
            </div>
          </div>
          <form method="post" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="do" value="save_email">
            <div class="grow max-w-md">
              <label for="dev_email" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Developer email</label>
              <input id="dev_email" type="email" name="dev_email" value="<?= e($cfg['email']) ?>"
                     class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono">
            </div>
            <button type="submit" class="rounded-lg border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2.5 text-sm font-semibold">Save</button>
          </form>
        </div>

        <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4">
          <div class="flex items-start gap-3">
            <span class="material-icons-outlined text-slate-500">history</span>
            <div>
              <h2 class="font-display text-lg font-bold text-gray-900">Recent activity</h2>
              <p class="text-sm text-slate-500">Latest developer-access events (also in the audit hub).</p>
            </div>
          </div>
          <?php if (!$history): ?>
            <p class="text-sm text-slate-400">No developer-access events recorded yet.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-gray-200">
                    <th class="px-3 py-2">When</th>
                    <th class="px-3 py-2">Event</th>
                    <th class="px-3 py-2">By</th>
                    <th class="px-3 py-2">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $row): ?>
                    <?php
                      $meta = json_decode((string) ($row['metadata'] ?? ''), true) ?: [];
                      $detail = '';
                      if (!empty($meta['expires_at'])) {
                          $detail = 'until ' . date('j M Y, g:ia', (int) strtotime((string) $meta['expires_at']));
                      } elseif (!empty($meta['email'])) {
                          $detail = (string) $meta['email'];
                      }
                    ?>
                    <tr class="border-b border-gray-100 align-top">
                      <td class="px-3 py-2 whitespace-nowrap text-slate-500"><?= e(date('j M Y, g:ia', (int) strtotime((string) $row['created_at']))) ?></td>
                      <td class="px-3 py-2 font-medium text-gray-900"><?= e($actionLabels[$row['action']] ?? $row['action']) ?></td>
                      <td class="px-3 py-2 text-slate-600"><?= e($row['actor_name'] ?? 'System') ?></td>
                      <td class="px-3 py-2 text-slate-500"><?= e($detail) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
