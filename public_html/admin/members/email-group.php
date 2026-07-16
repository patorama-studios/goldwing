<?php
// "Email a group" — compose a custom subject/body and send it to a chosen
// audience (all active members, one chapter, or members expiring within 30 days).
//
// v1 limitations: sends are SYNCHRONOUS (no queue) and throttled at 0.2s each,
// the audience is CAPPED at 800 recipients to avoid a web-request timeout, and
// there is NO scheduling — it sends immediately on confirm. If step-up bounces
// the confirm POST to /stepup.php, the composed draft is lost and must be
// re-entered (same trade-off the rest of the admin actions carry).
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\AdminMemberAccess;
use App\Services\ChapterRepository;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\EmailService;
use App\Services\MemberRepository;
use App\Services\NotificationPreferenceService;

// Same gate as export.php — members.view, not import_export — so committee roles
// that can already see members on screen can email those same audiences.
require_permission('admin.members.view');

$user = current_user();
$pdo = Database::connection();
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);

// ponytail: v1 cap; add a queue/batch worker to lift this
const EMAIL_GROUP_CAP = 800;

$audiences = [
    'all_active' => 'All active members',
    'chapter' => 'A specific chapter',
    'expiring_30' => 'Members expiring within 30 days',
];

$chapters = ChapterRepository::listForSelection($pdo, true);

// Form state (echoed back on preview / validation errors).
$audience = $_POST['audience'] ?? 'all_active';
if (!isset($audiences[$audience])) {
    $audience = 'all_active';
}
$chapterId = isset($_POST['chapter_id']) && $_POST['chapter_id'] !== '' ? (int) $_POST['chapter_id'] : null;
$subject = trim((string) ($_POST['subject'] ?? ''));
$body = (string) ($_POST['body'] ?? '');

$errors = [];
$overCap = false;
$previewRecipients = null; // resolved member rows shown on the preview step
$previewCount = 0;
$sendSummary = null;       // ['sent' => int, 'skipped' => int, 'total' => int]

// Resolve the chosen audience into a MemberRepository::search() filter set.
$resolveFilters = static function (string $audience, ?int $chapterId) use ($chapterRestriction): array {
    $filters = [];
    switch ($audience) {
        case 'chapter':
            $filters['status'] = 'active';
            $filters['chapter_id'] = $chapterId;
            break;
        case 'expiring_30':
            // MemberRepository honours '30d' — latest ACTIVE period ending within 30 days.
            $filters['expiring_within'] = '30d';
            break;
        case 'all_active':
        default:
            $filters['status'] = 'active';
            break;
    }
    // A chapter-restricted admin (area rep) may only ever email their own chapter,
    // whatever audience they picked.
    if ($chapterRestriction !== null) {
        $filters['chapter_id'] = $chapterRestriction;
    }
    return $filters;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? 'preview';
    $confirm = ($_POST['confirm'] ?? '') === '1';

    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    }
    if ($subject === '') {
        $errors[] = 'Subject is required.';
    }
    if (trim($body) === '') {
        $errors[] = 'Message body is required.';
    }
    if ($audience === 'chapter' && $chapterRestriction === null && ($chapterId === null || $chapterId <= 0)) {
        $errors[] = 'Please choose a chapter.';
    }

    if (!$errors) {
        $filters = $resolveFilters($audience, $chapterId);
        $result = MemberRepository::search($filters, 5000, 0);
        $previewRecipients = $result['data'];
        $previewCount = (int) $result['total'];
        $overCap = $previewCount > EMAIL_GROUP_CAP;

        if ($previewCount === 0) {
            $errors[] = 'No members match that audience.';
            $previewRecipients = null;
        } elseif ($formAction === 'send' && $confirm && !$overCap) {
            // Sensitive bulk action — step up on the SEND path only (not preview).
            require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members/email-group.php');

            // Plain text → escape + nl2br; anything containing a real HTML tag is
            // treated as admin-authored HTML and passed through untouched.
            $looksHtml = (bool) preg_match('/<[a-z!\/][^>]*>/i', $body);
            $bodyHtml = $looksHtml ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

            $sent = 0;
            $skipped = 0;
            foreach ($previewRecipients as $member) {
                $email = trim((string) ($member['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }
                // Opt-out: honour the member's per-user notification preferences.
                // Category 'admin' = "General admin announcements" — this is a
                // non-essential broadcast, so master-off / unsubscribe-all /
                // admin-category-off all skip the member.
                $userId = isset($member['user_id']) ? (int) $member['user_id'] : 0;
                if (!NotificationPreferenceService::shouldReceive($userId > 0 ? $userId : null, 'admin')) {
                    $skipped++;
                    continue;
                }
                // Pass member_id so EmailService renders the unsubscribe/preferences
                // footer (non-mandatory mail).
                $ok = EmailService::send($email, $subject, $bodyHtml, ['member_id' => (int) $member['id']]);
                if ($ok) {
                    $sent++;
                } else {
                    $skipped++;
                }
                usleep(200000); // 0.2s throttle — synchronous send, no queue
            }

            ActivityLogger::log('admin', $user['id'] ?? null, null, 'member.email_group', [
                'audience' => $audience,
                'chapter_id' => $filters['chapter_id'] ?? null,
                'recipients' => $previewCount,
                'sent' => $sent,
                'skipped' => $skipped,
                'subject' => $subject,
            ]);

            $sendSummary = ['sent' => $sent, 'skipped' => $skipped, 'total' => $previewCount];
            $previewRecipients = null; // show the summary view instead of the preview
        }
    }
}

$pageTitle = 'Email a group';
$activePage = 'members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Email a group'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div class="flex items-center justify-between gap-4">
        <div>
          <h1 class="font-display text-2xl font-bold text-gray-900">Email a group</h1>
          <p class="text-sm text-gray-500">Send a custom email to a chosen audience of members.</p>
          <?php if ($chapterRestriction !== null): ?>
            <p class="text-xs text-gray-500 mt-1">You can only email your chapter's members.</p>
          <?php endif; ?>
        </div>
        <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members">
          <span class="material-icons-outlined text-sm">arrow_back</span>
          Back to members
        </a>
      </div>

      <?php if ($errors): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <ul class="list-disc list-inside space-y-1">
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($sendSummary !== null): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800 space-y-1">
          <p class="font-semibold text-base">Email sent.</p>
          <p><?= (int) $sendSummary['sent'] ?> sent &middot; <?= (int) $sendSummary['skipped'] ?> skipped (opted-out or no valid email) &middot; <?= (int) $sendSummary['total'] ?> in audience.</p>
        </div>
      <?php endif; ?>

      <?php if ($previewRecipients !== null): ?>
        <div class="rounded-2xl border <?= $overCap ? 'border-amber-200 bg-amber-50' : 'border-blue-200 bg-blue-50' ?> p-5 space-y-3">
          <p class="text-sm font-semibold <?= $overCap ? 'text-amber-800' : 'text-blue-800' ?>">
            <?= (int) $previewCount ?> recipient<?= $previewCount === 1 ? '' : 's' ?> match this audience.
          </p>
          <?php if ($overCap): ?>
            <p class="text-sm text-amber-800">
              That is over the <?= EMAIL_GROUP_CAP ?> recipient send limit. Please narrow the audience (for example, pick a single chapter) before sending.
            </p>
          <?php else: ?>
            <p class="text-xs text-gray-600">Sample recipients (opted-out members and those without a valid email will be skipped at send time):</p>
            <ul class="text-sm text-gray-700 space-y-0.5">
              <?php foreach (array_slice($previewRecipients, 0, 10) as $member): ?>
                <li>
                  <?= e(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?>
                  <?php if (trim((string) ($member['email'] ?? '')) !== ''): ?>
                    <span class="text-gray-400">&lt;<?= e($member['email']) ?>&gt;</span>
                  <?php else: ?>
                    <span class="text-red-500">(no email)</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($previewCount > 10): ?>
              <p class="text-xs text-gray-500">…and <?= (int) ($previewCount - 10) ?> more.</p>
            <?php endif; ?>

            <form method="post" action="/admin/members/email-group.php" class="pt-2">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="form_action" value="send">
              <input type="hidden" name="confirm" value="1">
              <input type="hidden" name="audience" value="<?= e($audience) ?>">
              <input type="hidden" name="chapter_id" value="<?= e((string) ($chapterId ?? '')) ?>">
              <input type="hidden" name="subject" value="<?= e($subject) ?>">
              <input type="hidden" name="body" value="<?= e($body) ?>">
              <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-strong">
                <span class="material-icons-outlined text-base">send</span>
                Send now to <?= (int) $previewCount ?> recipient<?= $previewCount === 1 ? '' : 's' ?>
              </button>
              <p class="text-xs text-gray-500 mt-2">You may be asked to re-authenticate before the email is sent.</p>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-6">
        <form method="post" action="/admin/members/email-group.php" class="space-y-5">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="form_action" value="preview">

          <div>
            <label for="audience" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Audience</label>
            <select id="audience" name="audience" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" onchange="document.getElementById('chapter-row').style.display = this.value === 'chapter' ? '' : 'none';">
              <?php foreach ($audiences as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $audience === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($chapterRestriction === null): ?>
            <div id="chapter-row" style="<?= $audience === 'chapter' ? '' : 'display:none;' ?>">
              <label for="chapter_id" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Chapter</label>
              <select id="chapter_id" name="chapter_id" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <option value="">— Select a chapter —</option>
                <?php foreach ($chapters as $chapter): ?>
                  <option value="<?= (int) $chapter['id'] ?>" <?= ($chapterId !== null && (int) $chapter['id'] === $chapterId) ? 'selected' : '' ?>>
                    <?= e($chapter['display_label'] ?? $chapter['name'] ?? '') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div>
            <label for="subject" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Subject</label>
            <input type="text" id="subject" name="subject" maxlength="200" required value="<?= e($subject) ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
          </div>

          <div>
            <label for="body" class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Message</label>
            <textarea id="body" name="body" rows="12" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm font-mono"><?= e($body) ?></textarea>
            <p class="text-xs text-gray-500 mt-1">Plain text (line breaks are preserved) or simple HTML. The club branding and unsubscribe footer are added automatically.</p>
          </div>

          <div class="flex items-center gap-3 pt-1">
            <button type="submit" class="inline-flex items-center gap-2 rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:border-gray-400">
              <span class="material-icons-outlined text-base">groups</span>
              Preview recipients
            </button>
          </div>
        </form>
      </div>

    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
