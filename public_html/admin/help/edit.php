<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\TourService;
use App\Services\Csrf;
use App\Services\ActivityLogger;

require_login();
require_role(['admin', 'webmaster']);
$user = current_user();

$slug = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($_GET['slug'] ?? $_POST['slug'] ?? ''));
$tour = $slug !== '' ? TourService::tour($slug) : null;
$flash = '';
$flashKind = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        die('CSRF token invalid');
    }
    if (!$tour) {
        http_response_code(404);
        die('Unknown tour');
    }
    $action = (string) ($_POST['action'] ?? '');
    $pdo = db();
    try {
        if ($action === 'save_draft') {
            $stepId = (int) ($_POST['step_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $desc  = (string) ($_POST['description'] ?? '');
            $side  = in_array(($_POST['side'] ?? 'bottom'), ['top','bottom','left','right','over'], true) ? $_POST['side'] : 'bottom';
            $align = in_array(($_POST['align'] ?? 'start'), ['start','center','end'], true) ? $_POST['align'] : 'start';
            $stmt = $pdo->prepare(
                'UPDATE tour_steps
                    SET draft_title = :t, draft_description = :d, draft_side = :s, draft_align = :a,
                        has_draft = 1, updated_at = NOW(), updated_by = :uid
                  WHERE id = :id AND tour_slug = :slug'
            );
            $stmt->execute(['t' => $title, 'd' => $desc, 's' => $side, 'a' => $align, 'uid' => (int) $user['id'], 'id' => $stepId, 'slug' => $slug]);
            $flash = 'Draft saved. Click "Publish all" to make it live for members.';
        } elseif ($action === 'discard_draft') {
            $stepId = (int) ($_POST['step_id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE tour_steps
                    SET draft_title = NULL, draft_description = NULL, draft_side = NULL, draft_align = NULL,
                        draft_element_selector = NULL, has_draft = 0, updated_at = NOW(), updated_by = :uid
                  WHERE id = :id AND tour_slug = :slug'
            );
            $stmt->execute(['uid' => (int) $user['id'], 'id' => $stepId, 'slug' => $slug]);
            $flash = 'Draft discarded — live version is back.';
        } elseif ($action === 'publish_all') {
            $stmt = $pdo->prepare(
                "UPDATE tour_steps
                    SET title = COALESCE(draft_title, title),
                        description = COALESCE(draft_description, description),
                        side = COALESCE(draft_side, side),
                        align = COALESCE(draft_align, align),
                        element_selector = COALESCE(draft_element_selector, element_selector),
                        draft_title = NULL, draft_description = NULL, draft_side = NULL, draft_align = NULL,
                        draft_element_selector = NULL, has_draft = 0,
                        published_at = NOW(), updated_at = NOW(), updated_by = :uid
                  WHERE tour_slug = :slug AND has_draft = 1"
            );
            $stmt->execute(['uid' => (int) $user['id'], 'slug' => $slug]);
            $changed = $stmt->rowCount();
            if (class_exists(ActivityLogger::class)) {
                ActivityLogger::log('admin', (int) $user['id'], null, 'tour.publish', ['slug' => $slug, 'steps_changed' => $changed]);
            }
            $flash = $changed > 0
                ? "Published $changed step" . ($changed === 1 ? '' : 's') . ". Members will see the new wording immediately."
                : 'No drafts to publish.';
        }
    } catch (Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $flashKind = 'error';
    }
    // Redirect to GET so refresh doesn't resubmit.
    $_SESSION['tour_editor_flash'] = $flash;
    $_SESSION['tour_editor_flash_kind'] = $flashKind;
    header('Location: /admin/help/edit.php?slug=' . urlencode($slug));
    exit;
}

if (isset($_SESSION['tour_editor_flash'])) {
    $flash = $_SESSION['tour_editor_flash'];
    $flashKind = $_SESSION['tour_editor_flash_kind'] ?? 'success';
    unset($_SESSION['tour_editor_flash'], $_SESSION['tour_editor_flash_kind']);
}

$steps = $tour ? TourService::stepRowsFor($slug) : [];
$draftCount = 0;
foreach ($steps as $s) { if ((int) $s['has_draft'] === 1) $draftCount++; }

$allTours = TourService::allTours();
$pageTitle = $tour ? ('Edit tour: ' . $tour['name']) : 'Tour Editor';
$activePage = 'help-edit';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex min-h-screen bg-background-light">
  <?php include __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 p-6 md:p-10">
    <div class="max-w-4xl mx-auto">

      <?php if (!$tour): ?>
        <header class="mb-6">
          <h1 class="font-display text-3xl text-gray-900">Tour Editor</h1>
          <p class="text-gray-600 mt-1">Pick a tour to edit its wording.</p>
        </header>
        <div class="grid sm:grid-cols-2 gap-4">
          <?php foreach ($allTours as $tslug => $t): ?>
            <a href="?slug=<?= e($tslug) ?>" class="block bg-white rounded-2xl border border-gray-200 shadow-sm hover:border-primary hover:shadow-md p-5">
              <div class="font-semibold text-gray-900"><?= e($t['name'] ?? $tslug) ?></div>
              <div class="text-xs text-gray-500 mt-1"><?= e($tslug) ?></div>
              <div class="text-xs text-gray-500 mt-2"><?= e($t['audience'] ?? 'member') ?> · <?= e($t['page_url'] ?? '') ?></div>
            </a>
          <?php endforeach; ?>
        </div>

      <?php else: ?>
        <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
          <div>
            <a href="/admin/help/edit.php" class="text-xs text-secondary hover:underline">← All tours</a>
            <h1 class="font-display text-3xl text-gray-900 mt-1"><?= e($tour['name']) ?></h1>
            <p class="text-gray-600 mt-1">Edit the wording members see for each step. Saving creates a draft; clicking <strong>Publish all</strong> makes it live.</p>
            <p class="text-xs text-gray-500 mt-2">
              Page: <code><?= e($tour['page_url'] ?? '') ?></code> ·
              Audience: <?= e($tour['audience'] ?? 'member') ?>
            </p>
          </div>
          <div class="flex flex-col gap-2">
            <a href="<?= e($tour['page_url'] ?? '#') ?><?= strpos($tour['page_url'] ?? '', '?') !== false ? '&' : '?' ?>gw_tour=<?= urlencode($slug) ?>&preview=1" target="_blank" rel="noopener"
               class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-white border border-gray-300 text-gray-800 text-sm font-semibold hover:bg-gray-50">
              <span class="material-icons-outlined text-base">visibility</span>
              Preview drafts
            </a>
            <a href="<?= e($tour['page_url'] ?? '#') ?><?= strpos($tour['page_url'] ?? '', '?') !== false ? '&' : '?' ?>gw_tour=<?= urlencode($slug) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-white border border-gray-300 text-gray-800 text-sm font-semibold hover:bg-gray-50">
              <span class="material-icons-outlined text-base">play_arrow</span>
              Run live version
            </a>
          </div>
        </header>

        <?php if ($flash): ?>
          <div class="mb-6 rounded-2xl px-4 py-3 text-sm font-semibold <?= $flashKind === 'error' ? 'bg-rose-50 text-rose-800 border border-rose-200' : 'bg-emerald-50 text-emerald-800 border border-emerald-200' ?>">
            <?= e($flash) ?>
          </div>
        <?php endif; ?>

        <?php if ($draftCount > 0): ?>
          <form method="post" class="mb-6 flex items-center gap-3 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="slug" value="<?= e($slug) ?>">
            <input type="hidden" name="action" value="publish_all">
            <span class="material-icons-outlined text-amber-700">edit_note</span>
            <div class="flex-1">
              <div class="font-semibold text-amber-900"><?= (int) $draftCount ?> step<?= $draftCount === 1 ? '' : 's' ?> with unsaved changes</div>
              <div class="text-xs text-amber-700">Publishing makes these live for members immediately.</div>
            </div>
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-secondary text-white text-sm font-semibold hover:bg-secondary/90">
              <span class="material-icons-outlined text-base">publish</span>
              Publish all
            </button>
          </form>
        <?php endif; ?>

        <?php if (!$steps): ?>
          <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center text-gray-500">
            No steps in the database for this tour yet. After deploying, run <code>/admin/run-migration.php</code> to seed the steps.
          </div>
        <?php endif; ?>

        <?php foreach ($steps as $i => $step): ?>
          <?php $hasDraft = (int) $step['has_draft'] === 1; ?>
          <div class="bg-white rounded-2xl border <?= $hasDraft ? 'border-amber-300' : 'border-gray-200' ?> shadow-sm mb-4">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
              <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-100 text-gray-700 text-xs font-bold"><?= (int) $step['step_index'] + 1 ?></span>
                <code class="text-xs text-gray-500"><?= e($step['element_selector']) ?></code>
              </div>
              <?php if ($hasDraft): ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold bg-amber-100 text-amber-800">
                  <span class="material-icons-outlined text-sm">edit</span>
                  Draft
                </span>
              <?php endif; ?>
            </div>
            <form method="post" class="p-5 space-y-4">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="slug" value="<?= e($slug) ?>">
              <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
              <input type="hidden" name="action" value="save_draft">

              <div>
                <label class="text-sm font-semibold text-gray-700">Title</label>
                <input type="text" name="title" value="<?= e($hasDraft && $step['draft_title'] !== null ? $step['draft_title'] : $step['title']) ?>"
                       class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" required maxlength="255">
                <?php if ($hasDraft && $step['draft_title'] !== null && $step['draft_title'] !== $step['title']): ?>
                  <div class="text-xs text-gray-500 mt-1">Live: <em><?= e($step['title']) ?></em></div>
                <?php endif; ?>
              </div>

              <div>
                <label class="text-sm font-semibold text-gray-700">Description (HTML allowed — use &lt;strong&gt; for emphasis)</label>
                <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm font-mono" required><?= e($hasDraft && $step['draft_description'] !== null ? $step['draft_description'] : $step['description']) ?></textarea>
                <?php if ($hasDraft && $step['draft_description'] !== null && $step['draft_description'] !== $step['description']): ?>
                  <details class="mt-2 text-xs text-gray-500">
                    <summary class="cursor-pointer">Show live version</summary>
                    <pre class="bg-gray-50 rounded p-2 mt-1 whitespace-pre-wrap font-mono"><?= e($step['description']) ?></pre>
                  </details>
                <?php endif; ?>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="text-sm font-semibold text-gray-700">Popover side</label>
                  <select name="side" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    <?php $currSide = $hasDraft && $step['draft_side'] !== null ? $step['draft_side'] : $step['side']; ?>
                    <?php foreach (['top','bottom','left','right','over'] as $opt): ?>
                      <option value="<?= e($opt) ?>" <?= $opt === $currSide ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-semibold text-gray-700">Popover align</label>
                  <select name="align" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    <?php $currAlign = $hasDraft && $step['draft_align'] !== null ? $step['draft_align'] : $step['align']; ?>
                    <?php foreach (['start','center','end'] as $opt): ?>
                      <option value="<?= e($opt) ?>" <?= $opt === $currAlign ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold hover:bg-primary/90">
                  <span class="material-icons-outlined text-base">save</span>
                  Save as draft
                </button>
                <?php if ($hasDraft): ?>
                  <button type="submit" name="action" value="discard_draft"
                          class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-xs text-gray-600 hover:text-gray-900 hover:bg-gray-100"
                          onclick="return confirm('Throw away the unsaved changes and go back to the live version?');">
                    Discard draft
                  </button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
