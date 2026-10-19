<?php
// Email the new Wings issue to members: review the list (every current member
// with an email, pre-ticked), untick anyone who shouldn't get it, add an
// optional note, send. The send runs in small AJAX batches
// (WingsSendService::BATCH_SIZE) so a few hundred emails never hit the host's
// request time limit, and it can be resumed if the tab is closed.
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AdminMemberAccess;
use App\Services\BaseUrlService;
use App\Services\Csrf;
use App\Services\EmailService;
use App\Services\SettingsService;
use App\Services\WingsSendService;

require_permission('admin.wings_magazine.manage');

$user = current_user();
$ready = WingsSendService::tablesReady();
// The email goes to the whole club, so a chapter-scoped admin can't send it.
$restricted = AdminMemberAccess::getChapterRestrictionId($user) !== null;
$baseUrlError = BaseUrlService::validationError();
$firstName = strtok(trim((string) ($user['name'] ?? '')), ' ') ?: 'Member';
$errors = [];

$json = static function (array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $ajax = in_array($action, ['batch', 'test'], true);
    $blocked = !Csrf::verify($_POST['csrf_token'] ?? '') ? 'Your session expired. Reload the page and try again.'
        : (!$ready ? 'Run Migration 056 first.' : ($restricted ? 'Chapter-restricted accounts can\'t email the whole club.' : null));

    if ($blocked !== null) {
        if ($ajax) {
            $json(['error' => $blocked], 403);
        }
        $errors[] = $blocked;
    } elseif ($action === 'batch') {
        set_time_limit(120);
        $result = WingsSendService::sendBatch((int) ($_POST['send_id'] ?? 0));
        $json($result, isset($result['error']) ? 404 : 200);
    } elseif ($action === 'test') {
        $issue = WingsSendService::issue((int) ($_POST['issue_id'] ?? 0));
        $to = trim((string) ($user['email'] ?? ''));
        if (!$issue || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $json(['error' => 'Your admin account has no valid email address to send the test to.'], 422);
        }
        $subject = '[Test] ' . (trim((string) ($_POST['subject'] ?? '')) ?: WingsSendService::defaultSubject($issue));
        // A test has no recipient row, so its buttons open the portal reader (login) instead of a personal link.
        $html = WingsSendService::renderEmail($issue, $subject, (string) ($_POST['message'] ?? ''), $firstName, [
            'read' => BaseUrlService::emailLink('/member/read_wings.php?id=' . (int) $issue['id']),
            'download' => BaseUrlService::emailLink('/member/download_wings.php?id=' . (int) $issue['id']),
            'cover' => WingsSendService::absoluteUrl((string) ($issue['cover_image_url'] ?? '')),
        ]);
        $ok = EmailService::send($to, $subject, $html, ['visibility' => 'admin', 'notification_key' => 'wings_issue_test']);
        $json($ok ? ['ok' => true, 'to' => $to] : ['error' => 'The test email could not be sent. Check Settings → Integrations → Email.'], $ok ? 200 : 500);
    } elseif ($action === 'save_limit') {
        $newLimit = filter_var($_POST['daily_limit'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if ($newLimit === false) {
            $errors[] = 'The daily limit must be a whole number (0 for no limit).';
        } else {
            SettingsService::setGlobal((int) $user['id'], 'wings.daily_send_limit', $newLimit);
            header('Location: /admin/wings-send.php?issue=' . (int) ($_POST['issue_id'] ?? 0));
            exit;
        }
    } elseif ($action === 'retry') {
        $sendId = (int) ($_POST['send_id'] ?? 0);
        WingsSendService::retryFailed($sendId);
        header('Location: /admin/wings-send.php?send=' . $sendId . '&go=1');
        exit;
    } elseif ($action === 'send') {
        $issue = WingsSendService::issue((int) ($_POST['issue_id'] ?? 0));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $include = (array) ($_POST['include'] ?? []);
        if (!$issue) {
            $errors[] = 'That issue no longer exists.';
        } elseif (empty($issue['cover_image_url']) || empty($issue['pdf_url'])) {
            $errors[] = 'Add a cover image and PDF to this issue before emailing it.';
        } elseif ($subject === '' || mb_strlen($subject) > 200) {
            $errors[] = 'Enter a subject line (200 characters max).';
        } elseif (!$include) {
            $errors[] = 'Tick at least one member to send to.';
        } elseif ($baseUrlError !== null) {
            $errors[] = $baseUrlError;
        } elseif (!hash_equals((string) ($_SESSION['wings_send_nonce'] ?? ''), (string) ($_POST['nonce'] ?? ''))) {
            // Stops a double-click or a resubmitted form from emailing everyone twice.
            $errors[] = 'This list was already sent. Check the report below before sending again.';
        } else {
            require_stepup('/admin/wings-send.php?issue=' . (int) $issue['id']);
            unset($_SESSION['wings_send_nonce']);
            $sendId = WingsSendService::createSend((int) $issue['id'], $subject, $message, $include, (int) $user['id']);
            header('Location: /admin/wings-send.php?send=' . $sendId . '&go=1');
            exit;
        }
    }
}

$send = ($ready && isset($_GET['send'])) ? WingsSendService::findSend((int) $_GET['send']) : null;
$requestedIssue = $send ? (int) $send['issue_id'] : ($_POST['issue_id'] ?? $_GET['issue'] ?? null);
$issue = $requestedIssue !== null ? WingsSendService::issue((int) $requestedIssue) : WingsSendService::latestIssue();
$allIssues = db()->query('SELECT id, title, published_at FROM wings_issues ORDER BY published_at DESC, id DESC')->fetchAll();

$pageTitle = 'Email Wings';
$activePage = 'wings';
require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<style>
  .wr-row:has(input:not(:checked)) { opacity: .45; }
  .wr-row:has(input:not(:checked)) .wr-name { text-decoration: line-through; }
</style>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Email Wings'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="font-display text-2xl font-bold text-gray-900"><?= $send ? 'Wings email report' : 'Email Wings to members' ?></h1>
          <p class="text-sm text-gray-500"><?= $send ? 'Who got this send, and who has opened it.' : 'Check the email, untick anyone who shouldn\'t get it, then send.' ?></p>
        </div>
        <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/index.php?page=wings">
          <span class="material-icons-outlined text-sm">arrow_back</span>
          Back to Wings
        </a>
      </div>

      <?php foreach ($errors as $error): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?= e($error) ?></div>
      <?php endforeach; ?>

      <?php if (!$ready): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
          Wings emails need two new database tables. Run <a class="font-semibold underline" href="/admin/run-migration.php">Migration 056</a> once, then come back here.
        </div>
      <?php elseif ($restricted): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
          The Wings email goes to the whole club, so it can only be sent from a full admin account.
        </div>
      <?php elseif (!$issue): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
          <?php if ($requestedIssue !== null): ?>
            That Wings issue no longer exists (it may have been deleted). <a class="font-semibold text-gray-900 underline" href="/admin/index.php?page=wings">Back to Wings</a>
          <?php else: ?>
            No Wings issues yet. <a class="font-semibold text-gray-900 underline" href="/admin/index.php?page=wings">Upload one</a> first.
          <?php endif; ?>
        </div>
      <?php elseif ($send): ?>
        <?php
        $counts = WingsSendService::counts((int) $send['id']);
        $recipients = WingsSendService::recipients((int) $send['id']);
        $statusPills = [
            'sent' => ['Sent', 'bg-emerald-50 text-emerald-700'],
            'queued' => ['Waiting', 'bg-gray-100 text-gray-600'],
            'failed' => ['Failed', 'bg-red-50 text-red-700'],
            'removed' => ['Removed', 'bg-gray-100 text-gray-400'],
            'opted_out' => ['Opted out', 'bg-amber-50 text-amber-700'],
        ];
        ?>
        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-6 space-y-5" id="send-progress"
          data-send-id="<?= (int) $send['id'] ?>" data-autostart="<?= !empty($_GET['go']) && $counts['queued'] > 0 ? '1' : '0' ?>">
          <div class="flex items-center gap-4">
            <?php if (!empty($issue['cover_image_url'])): ?>
              <img src="<?= e($issue['cover_image_url']) ?>" alt="" class="w-14 rounded-md shadow-sm">
            <?php endif; ?>
            <div class="min-w-0">
              <p class="text-[11px] font-bold uppercase tracking-widest text-primary-strong"><?= e(date('F Y', strtotime($issue['published_at']))) ?></p>
              <h2 class="font-display text-xl font-bold text-gray-900 truncate"><?= e($issue['title']) ?></h2>
              <p class="text-xs text-gray-500">“<?= e($send['subject']) ?>” · started <?= e(date('j M Y, g:ia', strtotime($send['created_at']))) ?></p>
            </div>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <?php foreach (['sent' => 'Sent', 'queued' => 'Still to send', 'failed' => 'Failed', 'opened' => 'Opened'] as $key => $label): ?>
              <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400"><?= $label ?></p>
                <p class="text-2xl font-bold text-gray-900 mt-1" data-count="<?= $key ?>"><?= (int) $counts[$key] ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <div>
            <div class="h-2.5 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full bg-primary-strong transition-all duration-500" data-progress-bar
                style="width: <?= $counts['total'] ? round(100 * ($counts['sent'] + $counts['failed']) / $counts['total']) : 100 ?>%"></div>
            </div>
            <p class="text-sm text-gray-600 mt-3" data-progress-text aria-live="polite">
              <?php if ($counts['queued'] > 0 && ($nextAt = WingsSendService::nextSendAt()) !== null): ?>
                Daily limit reached: <?= WingsSendService::sentLast24h() ?> Wings emails went out in the last 24 hours (limit <?= WingsSendService::dailyLimit() ?>).
                The other <?= (int) $counts['queued'] ?> can go from <?= e(date('D j M, g:ia', $nextAt)) ?>. Come back then and press Resume.
              <?php elseif ($counts['queued'] > 0): ?>
                <?= (int) $counts['queued'] ?> still to send.
              <?php else: ?>
                All done: <?= (int) $counts['sent'] ?> sent<?= $counts['failed'] ? ', ' . (int) $counts['failed'] . ' failed' : '' ?>.
                <?= $counts['removed'] ? (int) $counts['removed'] . ' unticked on review.' : '' ?>
                <?= $counts['opted_out'] ? (int) $counts['opted_out'] . ' opted out.' : '' ?>
              <?php endif; ?>
            </p>
          </div>

          <div class="flex flex-wrap gap-3">
            <button type="button" data-resume class="<?= $counts['queued'] > 0 && empty($_GET['go']) ? '' : 'hidden ' ?>inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-gray-900 hover:bg-primary-strong hover:text-white">
              <span class="material-icons-outlined text-base">play_arrow</span>
              Resume sending
            </button>
            <?php if ($counts['failed'] > 0 && $counts['queued'] === 0): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="retry">
                <input type="hidden" name="send_id" value="<?= (int) $send['id'] ?>">
                <button type="submit" class="inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:border-gray-400">
                  <span class="material-icons-outlined text-base">refresh</span>
                  Retry <?= (int) $counts['failed'] ?> failed
                </button>
              </form>
            <?php endif; ?>
          </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Recipients</h3>
            <span class="text-xs text-gray-500"><?= count($recipients) ?> on the reviewed list</span>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
                <tr><th class="px-6 py-3">Member</th><th class="px-6 py-3">Email</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Opened</th></tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php foreach ($recipients as $r): [$pillLabel, $pillClass] = $statusPills[$r['status']] ?? [$r['status'], 'bg-gray-100 text-gray-600']; ?>
                  <tr>
                    <td class="px-6 py-3 font-medium text-gray-900"><?= e($r['member_name'] ?: $r['greeting']) ?></td>
                    <td class="px-6 py-3 text-gray-500"><?= e($r['email']) ?></td>
                    <td class="px-6 py-3"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $pillClass ?>"><?= e($pillLabel) ?></span></td>
                    <td class="px-6 py-3 text-gray-500"><?= $r['opened_at'] ? e(date('j M, g:ia', strtotime($r['opened_at']))) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php else: ?>
        <?php
        $audience = WingsSendService::audience();
        $sendable = array_values(array_filter($audience, fn ($g) => !$g['opted_out']));
        $optedOut = array_values(array_filter($audience, fn ($g) => $g['opted_out']));
        $noEmail = WingsSendService::noEmailCount();
        $previous = WingsSendService::sendsForIssue((int) $issue['id']);
        $missing = array_keys(array_filter(['a cover image' => empty($issue['cover_image_url']), 'a PDF' => empty($issue['pdf_url'])]));
        $posted = $_SERVER['REQUEST_METHOD'] === 'POST';
        $subject = $posted ? trim((string) ($_POST['subject'] ?? '')) : WingsSendService::defaultSubject($issue);
        $message = $posted ? (string) ($_POST['message'] ?? '') : '';
        $ticked = $posted ? array_flip(array_map('intval', (array) ($_POST['include'] ?? []))) : null;
        $_SESSION['wings_send_nonce'] = bin2hex(random_bytes(16));
        $preview = WingsSendService::renderEmail($issue, $subject, $message, $firstName, [
            'read' => '#', 'download' => '#', 'cover' => (string) ($issue['cover_image_url'] ?? ''),
        ], true);
        ?>

        <?php if ($previous): $last = $previous[0]; ?>
          <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 flex flex-wrap items-center justify-between gap-3">
            <span>
              <span class="material-icons-outlined text-base align-text-bottom">mark_email_read</span>
              This issue was emailed on <?= e(date('j M Y', strtotime($last['created_at']))) ?>:
              <?= (int) $last['counts']['sent'] ?> sent, <?= (int) $last['counts']['opened'] ?> opened<?= $last['counts']['queued'] ? ', ' . (int) $last['counts']['queued'] . ' not sent yet' : '' ?>.
              Sending again emails everyone ticked below a second time.
            </span>
            <a class="font-semibold underline" href="/admin/wings-send.php?send=<?= (int) $last['id'] ?>">View report</a>
          </div>
        <?php endif; ?>

        <?php if ($missing): ?>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <?php $it = count($missing) > 1 ? 'them' : 'it'; ?>
            This issue has no <?= e(implode(' or ', $missing)) ?> yet, and the email needs <?= $it ?>. Add <?= $it ?> on the
            <a class="font-semibold underline" href="/admin/index.php?page=wings">Wings page</a> (Edit issue), then come back.
          </div>
        <?php endif; ?>

        <?php if ($baseUrlError !== null): ?>
          <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Email links can't be built yet: <?= e($baseUrlError) ?></div>
        <?php endif; ?>

        <?php $dailyLimit = WingsSendService::dailyLimit(); $usedToday = WingsSendService::sentLast24h(); ?>
        <details class="rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm text-gray-600">
          <summary class="cursor-pointer">
            <span class="material-icons-outlined text-base align-text-bottom text-gray-400">speed</span>
            <?php if ($dailyLimit > 0): ?>
              Sends at most <strong class="text-gray-900"><?= $dailyLimit ?> a day</strong> (<?= $usedToday ?> used in the last 24 hours). Bigger lists stop at the limit and carry on when you press Resume the next day.
            <?php else: ?>
              No daily sending limit: everything goes out in one go.
            <?php endif; ?>
            <span class="font-semibold text-gray-900 underline">Change</span>
          </summary>
          <form method="post" class="mt-3 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-3">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save_limit">
            <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
            <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
              Wings emails per 24 hours
              <input type="number" name="daily_limit" min="0" max="100000" value="<?= $dailyLimit ?>" class="mt-1 block w-32 rounded-lg border border-gray-200 px-3 py-2 text-sm normal-case font-normal">
            </label>
            <button type="submit" class="rounded-full border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-400">Save limit</button>
            <p class="basis-full text-xs text-gray-500">Resend's free plan allows 100 emails a day for the whole site, and receipts, password resets and login codes count too, so 80 leaves room for those. Paid Resend plans have no daily cap: set 0 for no limit.</p>
          </form>
        </details>

        <form method="post" id="wings-send-form" class="grid grid-cols-1 gap-6 lg:grid-cols-5 items-start">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="issue_id" value="<?= (int) $issue['id'] ?>">
          <input type="hidden" name="nonce" value="<?= e($_SESSION['wings_send_nonce']) ?>">

          <!-- Compose + preview -->
          <section class="lg:col-span-3 rounded-2xl border border-gray-200 bg-white shadow-sm p-6 space-y-5">
            <div class="flex items-center gap-4">
              <?php if (!empty($issue['cover_image_url'])): ?>
                <img src="<?= e($issue['cover_image_url']) ?>" alt="" class="w-12 rounded shadow-sm">
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <label for="wings-issue" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Issue</label>
                <select id="wings-issue" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" onchange="location.href='/admin/wings-send.php?issue=' + this.value">
                  <?php foreach ($allIssues as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>" <?= (int) $opt['id'] === (int) $issue['id'] ? 'selected' : '' ?>>
                      <?= e($opt['title']) ?> (<?= e(date('M Y', strtotime($opt['published_at']))) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div>
              <label for="wings-subject" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Subject</label>
              <input type="text" id="wings-subject" name="subject" maxlength="200" required value="<?= e($subject) ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
            </div>

            <div>
              <label for="wings-message-input" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Personal message <span class="normal-case font-normal text-gray-400">(optional)</span></label>
              <textarea id="wings-message-input" name="message" rows="4" maxlength="2000" placeholder="e.g. This month: the Cowra AGM wrap-up, a 1,000 km Tassie loop and the new rally calendar." class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"><?= e($message) ?></textarea>
              <p class="text-xs text-gray-500 mt-1">Shown in a gold box above the cover. Plain text; line breaks are kept.</p>
            </div>

            <div>
              <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Preview</span>
                <span class="flex items-center gap-3">
                  <span class="text-xs text-gray-500" data-test-status aria-live="polite"></span>
                  <button type="button" data-test-send class="inline-flex items-center gap-1.5 rounded-full border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-gray-400">
                    <span class="material-icons-outlined text-sm">mail</span>
                    Send me a test
                  </button>
                </span>
              </div>
              <div class="rounded-xl border border-gray-200 overflow-hidden">
                <div class="bg-gray-50 border-b border-gray-200 px-4 py-2.5 text-xs text-gray-500 space-y-0.5">
                  <p><span class="font-semibold text-gray-700">From:</span> Australian Goldwing Association</p>
                  <p><span class="font-semibold text-gray-700">Subject:</span> <span data-subject-preview><?= e($subject) ?></span></p>
                </div>
                <iframe id="wings-preview" title="Email preview" class="w-full block" style="height: 1100px; border: 0;" srcdoc="<?= e($preview) ?>"></iframe>
              </div>
              <p class="text-xs text-gray-500 mt-2">Each member's buttons open the issue straight away through a personal link, with no login needed. The preview's buttons do nothing.</p>
            </div>
          </section>

          <!-- Recipients -->
          <section class="lg:col-span-2 lg:sticky lg:top-6 rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="p-5 border-b border-gray-100 space-y-3">
              <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900">Who gets it</h2>
                <span class="rounded-full bg-primary/20 px-3 py-1 text-xs font-bold text-gray-900"><span data-selected-count><?= count($sendable) ?></span> of <?= count($sendable) ?> ticked</span>
              </div>
              <p class="text-xs text-gray-500">Every current member with an email address, print and digital alike (everyone gets the emailed copy). Couples sharing an inbox get one email.</p>
              <input type="search" data-recipient-search aria-label="Search recipients" placeholder="Search name, email, number or chapter" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              <div class="flex gap-4 text-xs font-semibold">
                <button type="button" data-tick="1" class="text-primary-strong hover:underline">Tick all</button>
                <button type="button" data-tick="0" class="text-gray-500 hover:underline">Untick all</button>
              </div>
            </div>
            <ul class="max-h-[55vh] overflow-y-auto divide-y divide-gray-100" data-recipient-list>
              <?php foreach ($sendable as $g):
                $search = strtolower(implode(' ', array_merge($g['names'], $g['numbers'], [$g['email'], $g['chapter']]))); ?>
                <li class="wr-row" data-search="<?= e($search) ?>">
                  <label class="flex items-start gap-3 px-5 py-3 cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" name="include[]" value="<?= (int) $g['key'] ?>" class="mt-0.5 rounded border-gray-300 text-primary-strong focus:ring-primary"
                      <?= $ticked === null || isset($ticked[$g['key']]) ? 'checked' : '' ?>>
                    <span class="min-w-0 flex-1">
                      <span class="wr-name block text-sm font-medium text-gray-900"><?= e($g['label']) ?></span>
                      <span class="block text-xs text-gray-500 truncate"><?= e($g['email']) ?><?= $g['chapter'] !== '' ? ' · ' . e($g['chapter']) : '' ?></span>
                    </span>
                    <?php if ($g['numbers']): ?>
                      <span class="text-xs text-gray-400 whitespace-nowrap">#<?= e(implode(', ', $g['numbers'])) ?></span>
                    <?php endif; ?>
                  </label>
                </li>
              <?php endforeach; ?>
              <li class="hidden px-5 py-6 text-center text-sm text-gray-500" data-recipient-empty>No members match.</li>
            </ul>
            <div class="p-5 border-t border-gray-100 space-y-3 text-xs text-gray-500">
              <?php if ($optedOut): ?>
                <details>
                  <summary class="cursor-pointer font-semibold text-gray-700"><?= count($optedOut) ?> turned off Wings emails</summary>
                  <p class="mt-2">They switched off "Wings magazine" (or all non-essential email) in their email preferences, so they're left off. They can switch it back on themselves.</p>
                  <ul class="mt-2 space-y-1">
                    <?php foreach ($optedOut as $g): ?>
                      <li><?= e($g['label']) ?> <span class="text-gray-400">· <?= e($g['email']) ?></span></li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php endif; ?>
              <?php if ($noEmail): ?>
                <p><?= (int) $noEmail ?> current member<?= $noEmail === 1 ? ' has' : 's have' ?> no email address, so they can't be emailed. They're on the printed mailing list.</p>
              <?php endif; ?>
            </div>
          </section>

          <!-- Send bar -->
          <div class="lg:col-span-5 sticky bottom-0 z-10 rounded-2xl border border-gray-200 bg-white/95 backdrop-blur shadow-card px-5 py-4 flex flex-wrap items-center justify-between gap-3">
            <p class="hidden sm:block text-sm text-gray-600">Sends straight away. You'll see progress on the next screen, and you may be asked to confirm your password first.</p>
            <button type="submit" data-send-button <?= $missing || $baseUrlError !== null ? 'disabled' : '' ?>
              data-issue-title="<?= e($issue['title']) ?>" data-already-sent="<?= $previous ? e(date('j M', strtotime($previous[0]['created_at']))) : '' ?>"
              data-daily-limit="<?= $dailyLimit ?>" data-sendable-now="<?= $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : '' ?>"
              class="inline-flex items-center gap-2 rounded-full bg-primary px-6 py-3 text-sm font-bold text-gray-900 hover:bg-primary-strong hover:text-white disabled:opacity-40 disabled:pointer-events-none">
              <span class="material-icons-outlined text-base">send</span>
              <span>Send to <span data-selected-count><?= count($sendable) ?></span> <span data-member-word>members</span></span>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
(() => {
  const csrf = <?= json_encode(Csrf::token()) ?>;
  const post = (data) => {
    const body = new FormData();
    body.append('csrf_token', csrf);
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    return fetch('/admin/wings-send.php', { method: 'POST', body, headers: { Accept: 'application/json' } })
      .then((r) => r.json()
        .catch(() => ({ error: 'the server sent an unexpected reply (' + r.status + ')' }))
        .then((j) => (r.ok && !j.error ? j : Promise.reject(new Error(j.error || 'request failed')))));
  };

  // ── Review screen ───────────────────────────────────────────────────────
  const form = document.getElementById('wings-send-form');
  if (form) {
    const boxes = [...form.querySelectorAll('input[name="include[]"]')];
    const sendBtn = form.querySelector('[data-send-button]');
    const frame = document.getElementById('wings-preview');
    const subject = document.getElementById('wings-subject');
    const message = document.getElementById('wings-message-input');

    const updateCount = () => {
      const n = boxes.filter((b) => b.checked).length;
      form.querySelectorAll('[data-selected-count]').forEach((el) => { el.textContent = n; });
      form.querySelector('[data-member-word]').textContent = n === 1 ? 'member' : 'members';
      if (!sendBtn.hasAttribute('data-locked')) sendBtn.toggleAttribute('disabled', n === 0);
    };
    if (sendBtn.disabled) sendBtn.setAttribute('data-locked', '');
    boxes.forEach((b) => b.addEventListener('change', updateCount));
    updateCount();

    const search = form.querySelector('[data-recipient-search]');
    const rows = [...form.querySelectorAll('[data-recipient-list] .wr-row')];
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach((row) => { const hit = !q || row.dataset.search.includes(q); row.hidden = !hit; shown += hit; });
      form.querySelector('[data-recipient-empty]').classList.toggle('hidden', shown > 0);
    });
    form.querySelectorAll('[data-tick]').forEach((btn) => btn.addEventListener('click', () => {
      rows.filter((row) => !row.hidden).forEach((row) => { row.querySelector('input').checked = btn.dataset.tick === '1'; });
      updateCount();
    }));

    // Live preview: subject line, the message box, and the frame's height.
    const fit = () => { const doc = frame.contentDocument; if (doc && doc.body) frame.style.height = doc.documentElement.scrollHeight + 'px'; };
    const syncMessage = () => {
      const doc = frame.contentDocument;
      const box = doc && doc.getElementById('wings-message');
      if (!box) return;
      const lines = message.value.trim().split('\n');
      doc.getElementById('wings-message-text').replaceChildren(...lines.flatMap((line, i) => (i ? [doc.createElement('br'), doc.createTextNode(line)] : [doc.createTextNode(line)])));
      box.style.display = message.value.trim() ? '' : 'none';
      fit();
    };
    frame.addEventListener('load', () => { syncMessage(); fit(); setTimeout(fit, 400); });
    message.addEventListener('input', syncMessage);
    subject.addEventListener('input', () => { form.querySelector('[data-subject-preview]').textContent = subject.value; });

    const testBtn = form.querySelector('[data-test-send]');
    const testStatus = form.querySelector('[data-test-status]');
    testBtn.addEventListener('click', () => {
      testBtn.disabled = true;
      testStatus.textContent = 'Sending…';
      post({ action: 'test', issue_id: form.issue_id.value, subject: subject.value, message: message.value })
        .then((j) => { testStatus.textContent = 'Test sent to ' + j.to; })
        .catch((e) => { testStatus.textContent = e.message; })
        .finally(() => { testBtn.disabled = false; });
    });

    form.addEventListener('submit', (e) => {
      const n = boxes.filter((b) => b.checked).length;
      const again = sendBtn.dataset.alreadySent ? '\n\nThis issue was already emailed on ' + sendBtn.dataset.alreadySent + '. Everyone ticked will get it again.' : '';
      const limit = Number(sendBtn.dataset.dailyLimit);
      const now = Number(sendBtn.dataset.sendableNow);
      const days = limit > 0 && n > now ? 1 + Math.ceil((n - now) / limit) : 1;
      const spread = days > 1 ? '\n\nThe daily limit is ' + limit + ', so ' + now + ' go today and the rest over the next ' + (days - 1) + ' day' + (days > 2 ? 's' : '') + '. You press Resume each day.' : '';
      if (!confirm('Email "' + sendBtn.dataset.issueTitle + '" to ' + n + ' member' + (n === 1 ? '' : 's') + ' now?' + spread + again)) {
        e.preventDefault();
        return;
      }
      sendBtn.disabled = true;
      sendBtn.lastElementChild.textContent = 'Starting…';
    });
  }

  // ── Report / progress screen ────────────────────────────────────────────
  const panel = document.getElementById('send-progress');
  if (panel) {
    const resume = panel.querySelector('[data-resume]');
    const text = panel.querySelector('[data-progress-text]');
    const bar = panel.querySelector('[data-progress-bar]');
    const show = (c) => {
      ['sent', 'queued', 'failed', 'opened'].forEach((k) => { panel.querySelector('[data-count="' + k + '"]').textContent = c[k]; });
      bar.style.width = (c.total ? Math.round(100 * (c.sent + c.failed) / c.total) : 100) + '%';
    };
    const run = () => {
      resume.classList.add('hidden');
      text.textContent = 'Sending… keep this page open until it finishes.';
      post({ action: 'batch', send_id: panel.dataset.sendId })
        .then((c) => {
          show(c);
          const pause = (msg) => { text.textContent = msg; resume.classList.remove('hidden'); };
          if (c.paused_until) {
            const when = new Date(c.paused_until * 1000).toLocaleString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
            return pause('Daily limit reached. The other ' + c.queued + ' can go from ' + when + '. Come back then and press Resume.');
          }
          if (c.stalled && c.queued > 0) {
            return pause('Paused: the last batch all failed, so the email service may be refusing (daily quota, login or an outage). Check it, then press Resume. Failed ones can be retried at the end.');
          }
          if (c.queued > 0) return run();
          text.textContent = 'All done. Loading the report…';
          location.replace('/admin/wings-send.php?send=' + panel.dataset.sendId);
        })
        .catch((e) => {
          text.textContent = 'Paused: ' + e.message + '. Nothing was sent twice. Press Resume to carry on.';
          resume.classList.remove('hidden');
        });
    };
    resume.addEventListener('click', run);
    if (panel.dataset.autostart === '1') run();
  }
})();
</script>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
