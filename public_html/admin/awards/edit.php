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

      <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm" data-tour="winner-form">
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
            <div class="block" data-member-picker data-tour="winner-member-picker">
              <span class="text-sm font-semibold text-gray-700">Assign Member</span>
              <input type="hidden" name="member_id" id="member-id-input"
                     value="<?= (int) ($winner['member_id'] ?? 0) > 0 ? (int) $winner['member_id'] : '' ?>">

              <!-- Currently assigned chip — only shown when a member is selected -->
              <div id="member-chip-wrap" class="mt-1 <?= !empty($winner['member_id']) ? '' : 'hidden' ?>">
                <div class="flex items-center gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2.5">
                  <?php
                    $chipAvatar = $winner['member_avatar_url'] ?? '';
                    $chipName = trim(($winner['member_first_name'] ?? '') . ' ' . ($winner['member_last_name'] ?? ''));
                    $chipInitials = strtoupper(substr($chipName !== '' ? $chipName : '?', 0, 2));
                    $chipNumber = $winner['member_number'] ?? '';
                  ?>
                  <div id="member-chip-avatar">
                    <?php if ($chipAvatar): ?>
                      <img src="<?= e($chipAvatar) ?>" alt="" class="w-10 h-10 rounded-full object-cover border border-white shadow-sm">
                    <?php else: ?>
                      <div class="w-10 h-10 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm">
                        <?= e($chipInitials) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-900" id="member-chip-name"><?= e($chipName !== '' ? $chipName : 'Unknown member') ?></p>
                    <p class="text-xs text-gray-500" id="member-chip-meta">
                      <?php if ($chipNumber): ?>Member #<?= e($chipNumber) ?><?php endif; ?>
                    </p>
                  </div>
                  <?php if ($canManage): ?>
                    <button type="button" id="member-chip-remove"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">
                      <span class="material-icons-outlined text-base">close</span> Remove
                    </button>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Search input — shown when no member is selected -->
              <?php if ($canManage): ?>
                <div id="member-search-wrap" class="relative mt-1 <?= !empty($winner['member_id']) ? 'hidden' : '' ?>" data-search-box>
                  <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-base">person_search</span>
                  <input type="search" id="member-search-input"
                         placeholder="Search by name, member number, or email"
                         class="w-full rounded-lg border border-gray-200 bg-white pl-10 pr-3 py-2.5 text-sm shadow-sm"
                         autocomplete="off">
                  <div id="member-search-results"
                       class="search-results hidden absolute left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-20 max-h-72 overflow-y-auto"></div>
                </div>
              <?php endif; ?>

              <p class="text-xs text-gray-500 mt-1">Pick a current AGA member, or use the override below for past winners not yet in the directory.</p>
            </div>

            <label class="block">
              <span class="text-sm font-semibold text-gray-700">Winner Name Override</span>
              <input type="text" name="member_name_override" maxlength="200"
                     value="<?= e($winner['member_name_override'] ?? '') ?>"
                     <?= $canManage ? '' : 'readonly' ?>
                     placeholder="For past winners not in the member directory"
                     class="mt-1 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm">
              <p class="text-xs text-gray-500 mt-1">Used only when no current member is selected.</p>
            </label>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4" data-tour="winner-bike-fields">
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
            <label class="block" data-tour="winner-photos">
              <span class="text-sm font-semibold text-gray-700">Add Photos</span>
              <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp"
                     class="mt-1 block w-full text-sm">
              <p class="text-xs text-gray-500 mt-1">JPG, PNG, or WEBP. The first photo on a new winner becomes the primary.</p>
            </label>
          <?php endif; ?>

          <?php if ($canManage): ?>
            <div class="flex flex-wrap items-center gap-2 pt-2" data-tour="winner-save">
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
        <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm" data-tour="winner-photo-gallery">
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
// Member picker — same UX pattern as Committee & Leadership Roles:
//   search input → result rows with avatar + name + meta → click to assign,
//   shown as a removable chip until the form is saved or Remove is clicked.
(function () {
  var pickerRoot = document.querySelector('[data-member-picker]');
  if (!pickerRoot) return;

  var idInput = document.getElementById('member-id-input');
  var chipWrap = document.getElementById('member-chip-wrap');
  var chipAvatar = document.getElementById('member-chip-avatar');
  var chipName = document.getElementById('member-chip-name');
  var chipMeta = document.getElementById('member-chip-meta');
  var chipRemove = document.getElementById('member-chip-remove');
  var searchWrap = document.getElementById('member-search-wrap');
  var searchInput = document.getElementById('member-search-input');
  var results = document.getElementById('member-search-results');

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function initials(name) {
    var clean = (name || '').trim();
    if (!clean) return '?';
    var parts = clean.split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  function avatarMarkup(avatarUrl, name) {
    if (avatarUrl) {
      return '<img src="' + escapeHtml(avatarUrl) + '" alt="" class="w-10 h-10 rounded-full object-cover border border-white shadow-sm">';
    }
    return '<div class="w-10 h-10 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm">'
      + escapeHtml(initials(name)) + '</div>';
  }

  function renderChip(member) {
    chipAvatar.innerHTML = avatarMarkup(member.avatar_url, member.name);
    chipName.textContent = member.name || 'Unknown member';
    var metaParts = [];
    if (member.member_number) metaParts.push('Member #' + member.member_number);
    if (member.chapter) metaParts.push(member.chapter);
    chipMeta.textContent = metaParts.join(' · ');
    chipWrap.classList.remove('hidden');
    if (searchWrap) searchWrap.classList.add('hidden');
    if (searchInput) searchInput.value = '';
    if (results) { results.innerHTML = ''; results.classList.add('hidden'); }
  }

  function clearChip() {
    idInput.value = '';
    chipWrap.classList.add('hidden');
    if (searchWrap) searchWrap.classList.remove('hidden');
    if (searchInput) { searchInput.focus(); }
  }

  if (chipRemove) {
    chipRemove.addEventListener('click', clearChip);
  }

  if (!searchInput) return; // Read-only viewers don't have the search field.

  var searchTimer = null;
  searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    var q = searchInput.value.trim();
    if (q.length < 2) {
      results.innerHTML = '';
      results.classList.add('hidden');
      return;
    }
    searchTimer = setTimeout(function () {
      fetch('/admin/awards/search-members.php?q=' + encodeURIComponent(q), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
      })
        .then(function (r) { return r.text(); })
        .then(function (text) {
          var json;
          try { json = JSON.parse(text); }
          catch (e) {
            results.innerHTML = '<div class="px-3 py-2 text-xs text-red-600">Search error.</div>';
            results.classList.remove('hidden');
            return;
          }
          if (!json.ok) {
            results.innerHTML = '<div class="px-3 py-2 text-xs text-red-600">'
              + escapeHtml(json.detail || json.error || 'Lookup failed.') + '</div>';
            results.classList.remove('hidden');
            return;
          }
          var rows = json.results || [];
          if (!rows.length) {
            results.innerHTML = '<div class="px-3 py-2 text-xs text-slate-500">No matches.</div>';
            results.classList.remove('hidden');
            return;
          }
          results.innerHTML = rows.map(function (m) {
            var meta = [m.member_number ? 'Member #' + m.member_number : '', m.chapter]
              .filter(Boolean).join(' · ');
            return '<button type="button"'
              + ' class="result-row flex items-center gap-3 w-full text-left px-3 py-2.5 hover:bg-primary/5 border-b border-gray-100 last:border-0"'
              + ' data-member-json=\'' + escapeHtml(JSON.stringify(m)) + '\'>'
              + '<span class="shrink-0">' + avatarMarkup(m.avatar_url, m.name) + '</span>'
              + '<span class="flex-1 min-w-0">'
              + '<span class="block text-sm font-semibold text-gray-900 truncate">' + escapeHtml(m.name) + '</span>'
              + (meta ? '<span class="block text-xs text-slate-500 truncate">' + escapeHtml(meta) + '</span>' : '')
              + '</span>'
              + '</button>';
          }).join('');
          results.classList.remove('hidden');
        })
        .catch(function () {
          results.innerHTML = '<div class="px-3 py-2 text-xs text-red-600">Network error.</div>';
          results.classList.remove('hidden');
        });
    }, 220);
  });

  results.addEventListener('click', function (ev) {
    var row = ev.target.closest('.result-row');
    if (!row) return;
    try {
      var member = JSON.parse(row.getAttribute('data-member-json'));
      idInput.value = member.id;
      renderChip(member);
    } catch (e) { /* ignore */ }
  });

  document.addEventListener('click', function (ev) {
    if (!pickerRoot.contains(ev.target)) {
      results.classList.add('hidden');
    }
  });
})();
</script>

<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
