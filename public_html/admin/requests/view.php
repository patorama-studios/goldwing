<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\PendingRequestsService;

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

$pageTitle  = 'Request Detail';
$activePage = 'requests';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';

function reqStatusBadge2(string $status): string {
    return match (strtolower($status)) {
        'approved' => 'bg-emerald-100 text-emerald-800',
        'rejected' => 'bg-rose-100 text-rose-800',
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
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
          <h1 class="font-display text-xl font-bold text-gray-900">Request not found</h1>
          <p class="mt-2 text-sm text-gray-600">It may have been removed or already actioned.</p>
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
            <?php if (!empty($item['summary'])): ?>
              <p class="text-sm text-gray-700"><?= e($item['summary']) ?></p>
            <?php endif; ?>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
              <p class="text-xs uppercase tracking-[0.3em] text-gray-500 mb-2">Submission details</p>
              <dl class="grid gap-3 sm:grid-cols-2 text-sm">
                <?php foreach ($item['raw'] as $k => $v):
                  if (in_array($k, ['feedback_message', 'reviewed_by', 'reviewed_at', 'submitter_name', 'submitter_email', 'admin_notes', 'rejection_reason'], true)) continue;
                  if (is_array($v) || is_object($v)) continue;
                  if ($v === null || $v === '') continue;
                ?>
                  <div>
                    <dt class="text-xs uppercase tracking-wider text-gray-500"><?= e(str_replace('_', ' ', $k)) ?></dt>
                    <dd class="text-gray-900 break-words"><?= e((string) $v) ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>

            <?php if (!empty($item['raw']['feedback_message'])): ?>
              <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-[0.3em] text-amber-700 mb-1">Existing feedback</p>
                <p class="text-sm text-amber-900 whitespace-pre-wrap"><?= e((string) $item['raw']['feedback_message']) ?></p>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <?php if ($canAction && $item['status'] !== 'approved' && $item['status'] !== 'rejected'): ?>
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
                <label class="block text-xs uppercase tracking-[0.3em] text-gray-500 mb-1">Message to submitter (optional for approve, required for deny/feedback)</label>
                <textarea name="message" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none" placeholder="Optional context for the submitter..."></textarea>
              </div>

              <div class="flex flex-wrap gap-2">
                <button type="submit" name="action" value="approve" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                  <span class="material-icons-outlined text-base">check_circle</span> Approve
                </button>
                <button type="submit" name="action" value="reject" class="inline-flex items-center gap-1 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                  <span class="material-icons-outlined text-base">cancel</span> Deny
                </button>
                <button type="submit" name="action" value="feedback" class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                  <span class="material-icons-outlined text-base">forum</span> Send feedback
                </button>
              </div>
            </form>
          </section>
        <?php elseif ($canAction): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-6">
            <p class="text-sm text-gray-600">This request has been <?= e($item['status']) ?>.
              <?php if (!empty($item['raw']['reviewed_at'])): ?>
                Reviewed <?= e((string) $item['raw']['reviewed_at']) ?>.
              <?php endif; ?>
            </p>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
