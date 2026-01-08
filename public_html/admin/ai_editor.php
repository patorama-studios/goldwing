<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AiService;
use App\Services\Csrf;
use App\Services\PageService;
use App\Services\NoticeService;
use App\Services\EventService;
use App\Services\AuditService;

require_role(['admin']);

$pdo = db();
$user = current_user();
$message = '';
$error = '';
$draft = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        if (isset($_POST['prompt'])) {
            $prompt = trim($_POST['prompt']);
            $conversationId = AiService::createConversation($user['id']);
            $result = AiService::chat($conversationId, $prompt);

            $stmt = $pdo->prepare('INSERT INTO ai_drafts (conversation_id, target_type, target_id, slug, proposed_content, change_summary, status, created_by, created_at) VALUES (:conversation_id, :target_type, :target_id, :slug, :proposed, :summary, :status, :created_by, NOW())');
            $stmt->execute([
                'conversation_id' => $conversationId,
                'target_type' => $result['target_type'] ?? 'page',
                'target_id' => $result['target_id'] ?? null,
                'slug' => $result['slug'] ?? null,
                'proposed' => $result['proposed_html'] ?? $result['proposed_text'] ?? '',
                'summary' => $result['change_summary'] ?? '',
                'status' => 'DRAFT',
                'created_by' => $user['id'],
            ]);
            $draftId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM ai_drafts WHERE id = :id');
            $stmt->execute(['id' => $draftId]);
            $draft = $stmt->fetch();
            $message = 'Draft created. Review below.';
        } elseif (isset($_POST['apply_id'])) {
            $draftId = (int) $_POST['apply_id'];
            $stmt = $pdo->prepare('SELECT * FROM ai_drafts WHERE id = :id AND status = "DRAFT"');
            $stmt->execute(['id' => $draftId]);
            $draft = $stmt->fetch();
            if ($draft) {
                if ($draft['target_type'] === 'page') {
                    $targetId = (int) $draft['target_id'];
                    if (!$targetId && $draft['slug']) {
                        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = :slug');
                        $stmt->execute(['slug' => $draft['slug']]);
                        $row = $stmt->fetch();
                        $targetId = $row ? (int) $row['id'] : 0;
                    }
                    if ($targetId) {
                        PageService::updateContent($targetId, $draft['proposed_content'], $user['id'], $draft['change_summary']);
                    }
                } elseif ($draft['target_type'] === 'notice') {
                    NoticeService::updateContent((int) $draft['target_id'], $draft['proposed_content'], $user['id'], $draft['change_summary']);
                } elseif ($draft['target_type'] === 'event') {
                    EventService::updateDescription((int) $draft['target_id'], $draft['proposed_content'], $user['id'], $draft['change_summary']);
                }
                $stmt = $pdo->prepare('UPDATE ai_drafts SET status = "APPLIED", applied_by = :user_id, applied_at = NOW() WHERE id = :id');
                $stmt->execute(['user_id' => $user['id'], 'id' => $draftId]);
                AuditService::log($user['id'], 'ai_apply', 'AI draft #' . $draftId . ' applied.');
                $message = 'Draft applied.';
            } else {
                $error = 'Draft not found.';
            }
        }
    }
}

if (!$draft) {
    $stmt = $pdo->query('SELECT * FROM ai_drafts ORDER BY created_at DESC LIMIT 1');
    $draft = $stmt->fetch();
}

$pageTitle = 'AI Editor (comming soon)';
$activePage = 'ai-editor';

require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'AI Editor (comming soon)'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($message): ?>
        <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($error) ?></div>
      <?php endif; ?>
      <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
        <h1 class="font-display text-2xl font-bold text-gray-900 mb-4">AI Page Editor (comming soon)</h1>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <textarea name="prompt" rows="4" class="w-full" placeholder="Draft an update to the About page..."></textarea>
          <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Send to AI</button>
        </form>
      </section>
      <?php if ($draft): ?>
        <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="font-display text-xl font-bold text-gray-900 mb-2">Latest Draft</h2>
          <p class="text-sm text-gray-600">Target: <?= e($draft['target_type']) ?> <?= e($draft['slug'] ?? '') ?> #<?= e((string) $draft['target_id']) ?></p>
          <p class="text-sm text-gray-600">Summary: <?= e($draft['change_summary']) ?></p>
          <div class="prose prose-sm text-gray-700 mt-4"><?= $draft['proposed_content'] ?></div>
          <?php if ($draft['status'] === 'DRAFT'): ?>
            <form method="post" class="mt-4">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="apply_id" value="<?= e((string) $draft['id']) ?>">
              <button class="inline-flex items-center px-4 py-2 rounded-lg bg-secondary text-white text-sm font-semibold" type="submit">Apply</button>
            </form>
          <?php else: ?>
            <p class="mt-4 text-sm text-gray-500">Status: <?= e($draft['status']) ?></p>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../app/Views/partials/backend_footer.php'; ?>
