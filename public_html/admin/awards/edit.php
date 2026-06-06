<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AwardsService;
use App\Services\Csrf;

require_permission('admin.awards.view');

$user = current_user();
$canManage = function_exists('current_admin_can') && current_admin_can('admin.awards.manage', $user);

if (!AwardsService::tablesReady()) {
    $_SESSION['awards_flash'] = ['type' => 'error', 'message' => 'Awards tables are not ready. Run the migration runner.'];
    header('Location: /admin/awards/');
    exit;
}

$winnerId = (int) ($_GET['id'] ?? 0);
$winner = $winnerId > 0 ? AwardsService::findWinner($winnerId) : null;

// New-record path: ?category_id=X&year=Y
$categoryId = (int) ($_GET['category_id'] ?? ($winner['category_id'] ?? 0));
$year = (int) ($_GET['year'] ?? ($winner['year'] ?? date('Y')));

$categories = AwardsService::listCategories(true);
$category = null;
foreach ($categories as $c) {
    if ((int) $c['id'] === $categoryId) {
        $category = $c;
        break;
    }
}

$photos = $winner ? AwardsService::photosForWinner((int) $winner['id']) : [];

$flash = $_SESSION['awards_flash'] ?? null;
unset($_SESSION['awards_flash']);

$pageTitle = $winner ? 'Edit Winner' : 'Add Winner';
$activePage = 'awards';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = $pageTitle; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div>
        <a href="/admin/awards/?year=<?= (int) $year ?>" class="text-sm text-gray-500 hover:text-gray-700 inline-flex items-center gap-1">
          <span class="material-icons-outlined text-base">arrow_back</span> Back to <?= (int) $year ?> awards
        </a>
        <h1 class="font-display text-3xl font-bold text-gray-900 mt-2">
          <?= $winner ? 'Edit Award Winner' : 'Add Award Winner' ?>
        </h1>
        <?php if ($category): ?>
          <p class="text-sm text-gray-600 mt-1">
            <span class="font-semibold"><?= e($category['name']) ?></span>
            <?php if (!empty($category['memorial_trophy_name'])): ?>
              <span class="text-amber-700"> · <?= e($category['memorial_trophy_name']) ?></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>

      <?php if ($flash): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
        <form method="post" action="/admin/awards/actions.php" enctype="multipart/form-data" class="space-y-5">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="save_winner">
          <?php if ($winner): ?>
            <input type="hidden" name="id" value="<?= (int) $winner['id'] ?>">
          <?php endif; ?>
          <input type="hidden" name="redirect_after" value="/admin/awards/?year=<?= (int) $year ?>">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Trophy Category *</span>
              <select name="category_id" required <?= $canManage ? '' : 'disabled' ?>
                      class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="">— select —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $categoryId ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                    <?php if (!empty($c['memorial_trophy_name'])): ?>
                      (<?= e($c['memorial_trophy_name']) ?>)
                    <?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">AGM Year *</span>
              <input type="number" name="year" min="1970" max="2100" value="<?= (int) $year ?>" required
                     <?= $canManage ? '' : 'readonly' ?>
                     class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            </label>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Member (current member)</span>
              <select name="member_id" id="member-picker" <?= $canManage ? '' : 'disabled' ?>
                      class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="">— none / use override below —</option>
                <?php if ($winner && !empty($winner['member_id'])): ?>
                  <option value="<?= (int) $winner['member_id'] ?>" selected>
                    <?= e(trim(($winner['member_first_name'] ?? '') . ' ' . ($winner['member_last_name'] ?? ''))) ?>
                  </option>
                <?php endif; ?>
              </select>
              <p class="text-xs text-gray-500 mt-1">Type to search the members directory.</p>
            </label>
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Winner Name Override</span>
              <input type="text" name="member_name_override" maxlength="200"
                     value="<?= e($winner['member_name_override'] ?? '') ?>"
                     <?= $canManage ? '' : 'readonly' ?>
                     placeholder="For past winners not in the member directory"
                     class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              <p class="text-xs text-gray-500 mt-1">Used only when no current member is selected.</p>
            </label>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Bike Description</span>
              <input type="text" name="bike_description" maxlength="255"
                     value="<?= e($winner['bike_description'] ?? '') ?>"
                     <?= $canManage ? '' : 'readonly' ?>
                     placeholder="e.g. 1985 GL1200 Aspencade — Burgundy"
                     class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            </label>
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Awarded On</span>
              <input type="date" name="awarded_at"
                     value="<?= e($winner['awarded_at'] ?? '') ?>"
                     <?= $canManage ? '' : 'readonly' ?>
                     class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            </label>
          </div>

          <label class="block">
            <span class="text-sm font-semibold text-gray-700">Notes</span>
            <textarea name="notes" rows="3" maxlength="500"
                      <?= $canManage ? '' : 'readonly' ?>
                      placeholder="Distance travelled, judging notes, anecdotes..."
                      class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm"><?= e($winner['notes'] ?? '') ?></textarea>
          </label>

          <?php if ($canManage): ?>
            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Add Photos</span>
              <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp"
                     class="mt-1 block w-full text-sm">
              <p class="text-xs text-gray-500 mt-1">JPG, PNG, or WEBP. The first photo on a new winner becomes the primary.</p>
            </label>
          <?php endif; ?>

          <?php if ($canManage): ?>
            <div class="flex flex-wrap items-center gap-2 pt-2">
              <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-gray-900 hover:bg-primary/90">
                <span class="material-icons-outlined text-base">save</span>
                <?= $winner ? 'Save changes' : 'Add winner' ?>
              </button>
              <a href="/admin/awards/?year=<?= (int) $year ?>" class="rounded-full border border-gray-200 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
          <?php endif; ?>
        </form>
      </section>

      <?php if ($winner): ?>
        <!-- Photos gallery -->
        <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
          <h2 class="font-display text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-icons-outlined text-amber-500">photo_library</span>
            Photos
          </h2>
          <?php if (!$photos): ?>
            <p class="text-sm text-gray-500">No photos yet. Add some above.</p>
          <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
              <?php foreach ($photos as $photo): ?>
                <div class="relative group rounded-xl overflow-hidden border border-gray-200">
                  <img src="<?= e($photo['media_path']) ?>" alt="" class="w-full h-40 object-cover">
                  <?php if ((int) $photo['is_primary'] === 1): ?>
                    <span class="absolute top-2 left-2 inline-flex items-center gap-1 rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-bold uppercase text-white">
                      Primary
                    </span>
                  <?php endif; ?>
                  <?php if ($canManage): ?>
                    <div class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-black/50 px-2 py-1.5">
                      <?php if ((int) $photo['is_primary'] !== 1): ?>
                        <form method="post" action="/admin/awards/actions.php" class="inline">
                          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                          <input type="hidden" name="action" value="set_primary_photo">
                          <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                          <input type="hidden" name="redirect_after" value="/admin/awards/edit.php?id=<?= (int) $winner['id'] ?>">
                          <button class="text-xs text-white font-semibold hover:text-amber-300" type="submit">Set primary</button>
                        </form>
                      <?php else: ?>
                        <span class="text-xs text-white/60">Primary</span>
                      <?php endif; ?>
                      <form method="post" action="/admin/awards/actions.php" class="inline" onsubmit="return confirm('Delete this photo?');">
                        <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                        <input type="hidden" name="redirect_after" value="/admin/awards/edit.php?id=<?= (int) $winner['id'] ?>">
                        <button class="text-xs text-white font-semibold hover:text-red-300" type="submit">Delete</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <?php if ($canManage): ?>
          <section class="bg-white rounded-2xl border border-red-100 p-6 shadow-sm">
            <h2 class="font-display text-base font-bold text-red-700 mb-2">Danger zone</h2>
            <form method="post" action="/admin/awards/actions.php" onsubmit="return confirm('Delete this winner and all attached photos? This cannot be undone.');">
              <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="action" value="delete_winner">
              <input type="hidden" name="id" value="<?= (int) $winner['id'] ?>">
              <input type="hidden" name="redirect_after" value="/admin/awards/?year=<?= (int) $year ?>">
              <button class="inline-flex items-center gap-2 rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700" type="submit">
                <span class="material-icons-outlined text-base">delete</span>
                Delete winner
              </button>
            </form>
          </section>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </main>
</div>

<script>
// Lightweight member typeahead: load matches as you type. The select keeps the
// already-selected option above the live results so picking a member doesn't
// wipe the form value if you click outside the field.
(function () {
  var picker = document.getElementById('member-picker');
  if (!picker) return;
  // Replace the select with a combo: text input + hidden select. Keep the
  // existing <option selected> wired so the saved value persists on reload.
  var wrap = document.createElement('div');
  wrap.className = 'relative';
  picker.parentNode.insertBefore(wrap, picker);
  wrap.appendChild(picker);
  picker.style.display = 'none';

  var search = document.createElement('input');
  search.type = 'text';
  search.placeholder = 'Search members by name or email...';
  search.className = 'mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm';
  var sel = picker.options[picker.selectedIndex];
  if (sel && sel.value !== '') {
    search.value = sel.textContent.trim() + ' (member #' + sel.value + ')';
  }
  wrap.insertBefore(search, picker);

  var results = document.createElement('div');
  results.className = 'absolute z-20 left-0 right-0 mt-1 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg hidden';
  wrap.appendChild(results);

  var debounce;
  search.addEventListener('input', function () {
    clearTimeout(debounce);
    var q = search.value.trim();
    if (q.length < 2) {
      results.innerHTML = '';
      results.classList.add('hidden');
      return;
    }
    debounce = setTimeout(function () {
      fetch('/admin/awards/search-members.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          results.innerHTML = '';
          if (!Array.isArray(data) || data.length === 0) {
            results.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">No matches.</div>';
          } else {
            data.forEach(function (m) {
              var btn = document.createElement('button');
              btn.type = 'button';
              btn.className = 'block w-full text-left px-3 py-2 text-sm hover:bg-gray-50';
              btn.textContent = (m.first_name || '') + ' ' + (m.last_name || '') + ' — ' + (m.email || '');
              btn.addEventListener('click', function () {
                // Replace select options so the form submits the right value.
                picker.innerHTML = '';
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = (m.first_name || '') + ' ' + (m.last_name || '');
                opt.selected = true;
                picker.appendChild(opt);
                search.value = (m.first_name || '') + ' ' + (m.last_name || '') + ' (member #' + m.id + ')';
                results.classList.add('hidden');
              });
              results.appendChild(btn);
            });
          }
          results.classList.remove('hidden');
        });
    }, 200);
  });
  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) results.classList.add('hidden');
  });
})();
</script>

<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
