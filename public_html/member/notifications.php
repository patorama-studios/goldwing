<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\PendingRequestsService;

require_login();

$user = current_user();
$userId = (int) ($user['id'] ?? 0);

// Resolve member ID for this user
$pdo = db();
$memberRow = $pdo->prepare('SELECT id FROM members WHERE user_id = :uid LIMIT 1');
$memberRow->execute(['uid' => $userId]);
$memberData = $memberRow->fetch();
$memberId = (int) ($memberData['id'] ?? 0);

$flash = $_SESSION['notif_flash'] ?? null;
unset($_SESSION['notif_flash']);

// ── Handle member reply ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type      = trim((string) ($_POST['type'] ?? ''));
    $id        = (int) ($_POST['id'] ?? 0);
    $replyText = trim((string) ($_POST['reply'] ?? ''));

    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $_SESSION['notif_flash'] = ['type' => 'error', 'message' => 'Invalid form token. Please try again.'];
        header('Location: /member/notifications.php?type=' . urlencode($type) . '&id=' . $id);
        exit;
    }

    if ($replyText === '') {
        $_SESSION['notif_flash'] = ['type' => 'error', 'message' => 'Reply cannot be empty.'];
        header('Location: /member/notifications.php?type=' . urlencode($type) . '&id=' . $id);
        exit;
    }

    $validTypes = array_keys(PendingRequestsService::types());
    if (!in_array($type, $validTypes, true) || $id <= 0) {
        $_SESSION['notif_flash'] = ['type' => 'error', 'message' => 'Invalid request.'];
        header('Location: /member/notifications.php');
        exit;
    }

    // Verify the item belongs to this member/user before allowing a reply.
    $memberItems = PendingRequestsService::allForUser($userId, $memberId);
    $owns = false;
    foreach ($memberItems as $mi) {
        if ($mi['type'] === $type && (int) $mi['id'] === $id) {
            $owns = true;
            break;
        }
    }

    if (!$owns) {
        $_SESSION['notif_flash'] = ['type' => 'error', 'message' => 'You cannot reply to this request.'];
        header('Location: /member/notifications.php');
        exit;
    }

    $senderName = trim($user['name'] ?? 'Member');
    PendingRequestsService::addMessage($type, $id, 'member', $userId, $senderName, $replyText);

    // Reopen the ticket if it was in an actioned (non-pending, non-archived) state.
    foreach ($memberItems as $mi) {
        if ($mi['type'] === $type && (int) $mi['id'] === $id) {
            if (!in_array($mi['status'], ['pending', 'archived'], true)) {
                PendingRequestsService::reopen($type, $id);
            }
            break;
        }
    }

    $_SESSION['notif_flash'] = ['type' => 'success', 'message' => 'Your reply has been sent.'];
    header('Location: /member/notifications.php?type=' . urlencode($type) . '&id=' . $id);
    exit;
}

// ── Resolve detail view or list view ───────────────────────────────────────
$typeParam = trim((string) ($_GET['type'] ?? ''));
$idParam   = (int) ($_GET['id'] ?? 0);
$validTypes = array_keys(PendingRequestsService::types());
$showDetail = ($idParam > 0 && in_array($typeParam, $validTypes, true));

$notifItems = PendingRequestsService::allForUser($userId, $memberId);

$detailItem     = null;
$detailMessages = [];

if ($showDetail) {
    foreach ($notifItems as $ni) {
        if ($ni['type'] === $typeParam && (int) $ni['id'] === $idParam) {
            $detailItem = $ni;
            break;
        }
    }
    if ($detailItem) {
        $detailMessages = PendingRequestsService::getMessages($typeParam, $idParam);
    } else {
        // Item not found or doesn't belong to this user
        $showDetail = false;
    }
}

// ── Page setup ─────────────────────────────────────────────────────────────
$pageTitle  = 'My Notifications';
$activePage = 'notifications';
require __DIR__ . '/../../app/Views/partials/backend_head.php';

function notifStatusBadge(?string $status): string {
    return match (strtolower($status ?? '')) {
        'approved'             => 'bg-emerald-100 text-emerald-800',
        'rejected', 'wont_fix' => 'bg-rose-100 text-rose-800',
        'archived'             => 'bg-gray-200 text-gray-600',
        default                => 'bg-amber-100 text-amber-800',
    };
}

function notifStatusLabel(?string $status): string {
    return match (strtolower($status ?? '')) {
        'approved' => 'Approved',
        'rejected' => 'Denied',
        'archived' => 'Archived',
        default    => 'Pending',
    };
}
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_member_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'My Notifications'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <?php $lockdownPageKey = 'notifications'; require __DIR__ . '/../../app/Views/partials/member_lockdown.php'; ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <?php if ($flash): ?>
        <div class="rounded-2xl border <?= ($flash['type'] ?? '') === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> px-4 py-3 text-sm">
          <?= e($flash['message'] ?? '') ?>
        </div>
      <?php endif; ?>

      <?php if ($showDetail && $detailItem): ?>
        <!-- ── Detail view ────────────────────────────────────────── -->
        <a class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900" href="/member/notifications.php">
          <span class="material-icons-outlined text-sm">arrow_back</span> Back to notifications
        </a>

        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="border-b border-gray-100 px-6 py-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <div class="flex items-center gap-2 text-xs uppercase tracking-[0.3em] text-gray-500">
                <span class="material-icons-outlined text-base"><?= e($detailItem['type_icon']) ?></span>
                <?= e($detailItem['type_label']) ?> #<?= (int) $detailItem['id'] ?>
              </div>
              <h1 class="font-display text-xl font-bold text-gray-900 mt-1"><?= e($detailItem['title']) ?></h1>
              <p class="text-sm text-gray-500">Submitted <?= e($detailItem['submitted_at'] ?? '') ?></p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= notifStatusBadge($detailItem['status']) ?>">
              <?= notifStatusLabel($detailItem['status']) ?>
            </span>
          </div>

          <?php if (!empty($detailItem['summary'])): ?>
            <div class="px-6 py-4 border-b border-gray-100">
              <p class="text-sm text-gray-700"><?= e($detailItem['summary']) ?></p>
            </div>
          <?php endif; ?>

          <?php if (!empty($detailItem['raw']['feedback_message'])): ?>
            <div class="px-6 py-4 border-b border-gray-100">
              <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-[0.3em] text-amber-700 mb-1">Admin response</p>
                <p class="text-sm text-amber-900 whitespace-pre-wrap"><?= e((string) $detailItem['raw']['feedback_message']) ?></p>
              </div>
            </div>
          <?php endif; ?>
        </section>

        <?php if (!empty($detailMessages)): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-3 flex items-center gap-2">
              <span class="material-icons-outlined text-base text-gray-400">forum</span>
              <h2 class="font-semibold text-gray-900">Conversation</h2>
            </div>
            <div class="divide-y divide-gray-100">
              <?php foreach ($detailMessages as $tmsg): ?>
                <div class="px-6 py-4 flex gap-3 <?= $tmsg['sender_type'] === 'admin' ? 'bg-blue-50/50' : '' ?>">
                  <span class="material-icons-outlined text-2xl mt-0.5 <?= $tmsg['sender_type'] === 'admin' ? 'text-blue-400' : 'text-gray-400' ?>">
                    <?= $tmsg['sender_type'] === 'admin' ? 'support_agent' : 'person' ?>
                  </span>
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                      <span class="font-semibold text-sm text-gray-900"><?= e($tmsg['sender_name']) ?></span>
                      <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $tmsg['sender_type'] === 'admin' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' ?>">
                        <?= $tmsg['sender_type'] === 'admin' ? 'Admin' : 'You' ?>
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

        <?php if ($detailItem['status'] !== 'archived'): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-3">
              <h2 class="font-semibold text-gray-900">Reply</h2>
              <p class="text-xs text-gray-500">
                <?= $detailItem['status'] !== 'pending'
                  ? 'Send a reply — this will reopen your request for admin review.'
                  : 'Add a message to this request.' ?>
              </p>
            </div>
            <form method="post" action="/member/notifications.php" class="p-6 space-y-4">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="type" value="<?= e($typeParam) ?>">
              <input type="hidden" name="id" value="<?= (int) $idParam ?>">
              <textarea name="reply" rows="4" required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none"
                        placeholder="Type your reply here..."></textarea>
              <button type="submit"
                      class="inline-flex items-center gap-1 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-gray-900 hover:opacity-90">
                <span class="material-icons-outlined text-base">send</span> Send reply
              </button>
            </form>
          </section>
        <?php else: ?>
          <section class="rounded-2xl border border-gray-100 bg-gray-50 p-6 text-sm text-gray-500 flex items-center gap-2">
            <span class="material-icons-outlined">inventory_2</span>
            This request has been archived and is closed. If you have a new query, please submit a new request.
          </section>
        <?php endif; ?>

      <?php else: ?>
        <!-- ── List view ──────────────────────────────────────────── -->
        <header>
          <h1 class="font-display text-2xl font-bold text-gray-900">My Notifications</h1>
          <p class="text-sm text-gray-500">A record of all your requests and their current status.</p>
        </header>

        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <?php if (empty($notifItems)): ?>
            <div class="px-6 py-16 text-center">
              <span class="material-icons-outlined text-5xl text-gray-300">inbox</span>
              <h2 class="mt-3 text-lg font-semibold text-gray-700">No requests yet</h2>
              <p class="text-sm text-gray-500">When you submit a notice, event, or other request it will appear here.</p>
            </div>
          <?php else: ?>
            <div class="divide-y divide-gray-100">
              <?php foreach ($notifItems as $ni): ?>
                <a class="block px-6 py-4 hover:bg-gray-50 transition-colors"
                   href="/member/notifications.php?type=<?= e($ni['type']) ?>&id=<?= (int) $ni['id'] ?>">
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-3 min-w-0">
                      <span class="material-icons-outlined text-gray-400 mt-0.5"><?= e($ni['type_icon']) ?></span>
                      <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                          <span class="text-xs font-semibold uppercase tracking-wider text-gray-500"><?= e($ni['type_label']) ?></span>
                          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold <?= notifStatusBadge($ni['status']) ?>">
                            <?= notifStatusLabel($ni['status']) ?>
                          </span>
                        </div>
                        <p class="font-semibold text-gray-900 truncate mt-0.5"><?= e($ni['title']) ?></p>
                        <?php if (!empty($ni['summary'])): ?>
                          <p class="text-sm text-gray-500 mt-0.5 line-clamp-1"><?= e($ni['summary']) ?></p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="text-xs text-gray-400 shrink-0 text-right">
                      <p><?= e($ni['submitted_at'] ?? '') ?></p>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

    </div>
  </main>
</div>
</body></html>
