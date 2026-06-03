<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\PendingRequestsService;

// Capture fatal errors so a blank page leaves a debuggable trace in the HTML source.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo '<!-- PHP_FATAL:' . base64_encode(json_encode($err)) . ' -->';
    }
});

require_permission('admin.requests.view');

$type = trim((string) ($_GET['type'] ?? ''));
$id   = (int) ($_GET['id'] ?? 0);
$validTypes = array_keys(PendingRequestsService::types());

if (!in_array($type, $validTypes, true) || $id <= 0) {
    header('Location: /admin/requests/');
    exit;
}

$item = PendingRequestsService::find($type, $id);

$flash = $_SESSION['requests_flash'] ?? null;
unset($_SESSION['requests_flash']);

$canAction = current_admin_can('admin.requests.action');

// Load conversation thread
$threadMessages = $item ? PendingRequestsService::getMessages($type, $id) : [];

$pageTitle  = 'Request Detail';
$activePage = 'requests';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';

function reqStatusBadge2(?string $status): string {
    return match (strtolower($status ?? '')) {
        'approved' => 'bg-emerald-100 text-emerald-800',
        'rejected' => 'bg-rose-100 text-rose-800',
        'archived' => 'bg-gray-200 text-gray-600',
        'pending', 'new' => 'bg-amber-100 text-amber-800',
        default => 'bg-gray-100 text-gray-700',
    };
}
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Request Detail'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <?php if ($flash): ?>
        <div class="rounded-2xl border <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> px-4 py-3 text-sm">
          <?= e($flash['message'] ?? '') ?>
        </div>
      <?php endif; ?>

      <a class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900" href="/admin/requests/">
        <span class="material-icons-outlined text-sm">arrow_back</span> Back to hub
      </a>

      <?php if (!$item): ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-3">
          <h1 class="font-display text-xl font-bold text-gray-900">Notification not found</h1>
          <p class="text-sm text-gray-600">
            We couldn't load notification
            <span class="font-mono text-xs bg-gray-100 px-1.5 py-0.5 rounded"><?= e($type) ?> #<?= (int) $id ?></span>.
            It may have been deleted, archived, or the underlying record was removed.
          </p>
          <div class="pt-2">
            <a href="/admin/requests/" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800">
              <span class="material-icons-outlined text-sm">arrow_back</span> Back to notification hub
            </a>
          </div>
        </section>
      <?php else: ?>
        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="border-b border-gray-100 px-6 py-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <div class="flex items-center gap-2 text-xs uppercase tracking-[0.3em] text-gray-500">
                <span class="material-icons-outlined text-base"><?= e($item['type_icon']) ?></span>
                <?= e($item['type_label']) ?> #<?= (int) $item['id'] ?>
              </div>
              <h1 class="font-display text-2xl font-bold text-gray-900 mt-1"><?= e($item['title']) ?></h1>
              <p class="text-sm text-gray-500">Submitted <?= e($item['submitted_at'] ?? '') ?>
                <?php if (!empty($item['submitter_name'])): ?>· by <?= e($item['submitter_name']) ?><?php endif; ?>
                <?php if (!empty($item['submitter_email'])): ?>(<?= e($item['submitter_email']) ?>)<?php endif; ?>
              </p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= reqStatusBadge2($item['status']) ?>"><?= e(strtoupper($item['status'])) ?></span>
          </div>

          <div class="p-6 space-y-4">

            <?php
              // Rich-text / long-form fields rendered with preserved HTML so they're readable.
              $richFields  = ['message', 'content', 'description', 'tribute', 'nomination_details'];
              // Fields to omit from the compact metadata grid (shown elsewhere or internal noise).
              $skipMeta    = array_merge($richFields, [
                  'id', 'status', 'created_at', 'created_by', 'submitted_at',
                  'user_id', 'user_name', 'user_email', 'ticket_status',
                  'feedback_message', 'reviewed_by', 'reviewed_at', 'response',
                  'submitter_name', 'submitter_email', 'admin_notes', 'rejection_reason',
              ]);
            ?>

            <?php foreach ($richFields as $rf): ?>
              <?php $rfVal = (string) ($item['raw'][$rf] ?? ''); if ($rfVal === '') continue; ?>
              <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs uppercase tracking-[0.3em] text-gray-500 mb-2"><?= e(ucwords(str_replace('_', ' ', $rf))) ?></p>
                <div class="text-sm text-gray-800 leading-relaxed prose prose-sm max-w-none">
                  <?= strip_tags($rfVal, '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><a><blockquote><pre><code>') ?>
                </div>
              </div>
            <?php endforeach; ?>

            <?php
              $metaFields = array_filter($item['raw'], function ($v, $k) use ($skipMeta) {
                  if (in_array($k, $skipMeta, true)) return false;
                  if (is_array($v) || is_object($v)) return false;
                  return $v !== null && $v !== '';
              }, ARRAY_FILTER_USE_BOTH);
            ?>
            <?php if (!empty($metaFields)): ?>
              <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs uppercase tracking-[0.3em] text-gray-500 mb-2">Submission details</p>
                <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                  <?php foreach ($metaFields as $k => $v): ?>
                    <div>
                      <dt class="text-xs uppercase tracking-wider text-gray-500"><?= e(str_replace('_', ' ', $k)) ?></dt>
                      <dd class="text-gray-900 break-words"><?= e((string) $v) ?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              </div>
            <?php endif; ?>

            <?php if (!empty($item['raw']['feedback_message'])): ?>
              <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-[0.3em] text-amber-700 mb-1">Existing feedback</p>
                <p class="text-sm text-amber-900 whitespace-pre-wrap"><?= e((string) $item['raw']['feedback_message']) ?></p>
              </div>
            <?php endif; ?>

            <?php if (!empty($item['raw']['response'])): ?>
              <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                <p class="text-xs uppercase tracking-[0.3em] text-blue-700 mb-1">Admin response on record</p>
                <p class="text-sm text-blue-900 whitespace-pre-wrap"><?= e((string) $item['raw']['response']) ?></p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <?php
          $isFeedback   = $type === \App\Services\PendingRequestsService::TYPE_FEEDBACK;
          $approveLabel  = $isFeedback ? 'Mark resolved' : 'Approve';
          $rejectLabel   = $isFeedback ? "Won't fix"     : 'Deny';
          $feedbackLabel = $isFeedback ? 'Reply to user' : 'Send feedback';
          $approveIcon   = $isFeedback ? 'task_alt'      : 'check_circle';
          $rejectIcon    = $isFeedback ? 'block'         : 'cancel';
          $feedbackIcon  = $isFeedback ? 'reply'         : 'forum';
          $messageLabel  = $isFeedback
            ? 'Response to submitter (optional for resolve / won\'t fix, required for reply)'
            : 'Message to submitter (optional for approve, required for deny/feedback)';
          $itemStatus    = $item['status'] ?? '';
          $isPending     = !in_array($itemStatus, ['approved', 'rejected', 'archived'], true);
          $isActioned    = in_array($itemStatus, ['approved', 'rejected'], true);
          $isArchived    = ($itemStatus === 'archived');
        ?>

        <?php if (!empty($threadMessages)): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-3 flex items-center gap-2">
              <span class="material-icons-outlined text-base text-gray-400">forum</span>
              <h2 class="font-semibold text-gray-900">Conversation</h2>
            </div>
            <div class="divide-y divide-gray-100">
              <?php foreach ($threadMessages as $tmsg): ?>
                <div class="px-6 py-4 flex gap-3 <?= $tmsg['sender_type'] === 'admin' ? 'bg-blue-50/50' : 'bg-white' ?>">
                  <span class="material-icons-outlined text-2xl mt-0.5 <?= $tmsg['sender_type'] === 'admin' ? 'text-blue-400' : 'text-gray-400' ?>">
                    <?= $tmsg['sender_type'] === 'admin' ? 'support_agent' : 'person' ?>
                  </span>
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                      <span class="font-semibold text-sm text-gray-900"><?= e($tmsg['sender_name']) ?></span>
                      <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $tmsg['sender_type'] === 'admin' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' ?>">
                        <?= $tmsg['sender_type'] === 'admin' ? 'Admin' : 'Member' ?>
                      </span>
                      <span class="text-xs text-gray-400"><?= e($tmsg['created_at']) ?></span>
                    </div>
                    <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= e($tmsg['message']) ?></p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($canAction && $isPending): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-3">
              <h2 class="font-semibold text-gray-900">Take action</h2>
              <p class="text-xs text-gray-500">Approve, deny, or send feedback to the submitter.</p>
            </div>
            <form method="post" action="/admin/requests/actions.php" class="p-6 space-y-4">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="type" value="<?= e($type) ?>">
              <input type="hidden" name="id" value="<?= (int) $id ?>">

              <div>
                <label class="block text-xs uppercase tracking-[0.3em] text-gray-500 mb-1"><?= e($messageLabel) ?></label>
                <textarea name="message" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none" placeholder="Optional context for the submitter..."></textarea>
              </div>

              <div class="flex flex-wrap gap-2">
                <button type="submit" name="action" value="approve" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                  <span class="material-icons-outlined text-base"><?= e($approveIcon) ?></span> <?= e($approveLabel) ?>
                </button>
                <button type="submit" name="action" value="reject" class="inline-flex items-center gap-1 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                  <span class="material-icons-outlined text-base"><?= e($rejectIcon) ?></span> <?= e($rejectLabel) ?>
                </button>
                <button type="submit" name="action" value="feedback" class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                  <span class="material-icons-outlined text-base"><?= e($feedbackIcon) ?></span> <?= e($feedbackLabel) ?>
                </button>
              </div>
            </form>
          </section>

        <?php elseif ($canAction && $isActioned): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <p class="text-sm text-gray-600">This request has been <strong><?= e($itemStatus) ?></strong>.
                <?php if (!empty($item['raw']['reviewed_at'])): ?>
                  Reviewed <?= e((string) $item['raw']['reviewed_at']) ?>.
                <?php endif; ?>
              </p>
              <form method="post" action="/admin/requests/actions.php" class="shrink-0">
                <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <button type="submit" name="action" value="archive"
                        class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                  <span class="material-icons-outlined text-base">archive</span> Archive
                </button>
              </form>
            </div>
          </section>

        <?php elseif ($canAction && $isArchived): ?>
          <section class="rounded-2xl border border-gray-100 bg-gray-50 shadow-sm p-6">
            <div class="flex items-center gap-2 text-gray-500">
              <span class="material-icons-outlined">inventory_2</span>
              <p class="text-sm">This request has been archived and is closed.</p>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
