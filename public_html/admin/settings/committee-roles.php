<?php
// Reset OPcache so the latest deployed copy of this page is what runs
// (Goldwing's bytecode cache is sticky — see committee-roles-search.php).
if (function_exists('opcache_reset')) { @opcache_reset(); }
// Committee & Leadership Roles — role-centric admin page.
// Lives under Settings ▸ People & Access.
//
// Pick a role first (or filter to a chapter), search a member by name or
// member number, and assign. All edits hit committee-roles-save.php via
// fetch() so the page stays put.

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\ChapterRepository;
use App\Services\CommitteeService;
use App\Services\Csrf;

require_permission('admin.members.view');

// Make sure every active chapter has a matching rep role row.
CommitteeService::syncChapterRoles();

$pdo = db();

$chapters = ChapterRepository::listForSelection($pdo, true);

// Pull every active role with all its current holders. queryRoles() LEFT-JOINs
// assignments, so vacant roles still appear (one row with member_id = NULL).
$nationalRows = CommitteeService::nationalRoles();
$chapterRowsByState = CommitteeService::chapterRolesByState();

// Group rows by role so a role with multiple holders renders as one card.
$groupByRoleId = function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $rid = (int) $row['role_id'];
        if (!isset($out[$rid])) {
            $out[$rid] = [
                'role'    => $row,
                'members' => [],
            ];
        }
        if (!empty($row['member_id'])) {
            $out[$rid]['members'][] = $row;
        }
    }
    return array_values($out);
};

$nationalRoles = $groupByRoleId($nationalRows);

$chapterRolesByState = [];
foreach ($chapterRowsByState as $state => $rows) {
    $chapterRolesByState[$state] = $groupByRoleId($rows);
}

$activePage = 'settings';
$pageTitle = 'Committee & Leadership Roles';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Settings Hub'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6"
         data-csrf="<?= e(Csrf::token()) ?>">

      <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <div>
            <h1 class="font-display text-2xl font-bold text-gray-900">Committee &amp; Leadership Roles</h1>
            <nav class="mt-1 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
              <a href="/admin/" class="hover:text-slate-700">Admin</a>
              <span class="text-slate-300">/</span>
              <a href="/admin/settings/" class="hover:text-slate-700">Settings</a>
              <span class="text-slate-300">/</span>
              <span class="font-semibold text-gray-900 border-b-2 border-primary pb-0.5">Committee &amp; Leadership Roles</span>
            </nav>
            <p class="text-sm text-slate-500 mt-2 max-w-3xl">
              Pick a role, search for a member by name or member number, and assign.
              Cards on the public Committee &amp; Chapter Reps pages and the member-area Committee page render from these assignments automatically.
            </p>
          </div>
          <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <div class="relative">
              <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-base">filter_list</span>
              <select id="category-filter" class="min-w-[200px] rounded-lg border border-gray-200 bg-white pl-10 pr-3 py-2.5 text-sm shadow-sm">
                <option value="all">All roles</option>
                <option value="national">National only</option>
                <option value="chapter">Chapter only</option>
              </select>
            </div>
            <div class="relative">
              <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-base">search</span>
              <input id="role-search" type="search" placeholder="Filter roles or chapters" class="w-full sm:w-64 rounded-lg border border-gray-200 bg-white pl-10 pr-3 py-2.5 text-sm shadow-sm">
            </div>
            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-sm cursor-pointer select-none whitespace-nowrap">
              <input type="checkbox" id="hide-vacant-toggle" class="rounded border-gray-300 text-primary focus:ring-2 focus:ring-primary">
              <span class="text-gray-700">Hide vacant</span>
            </label>
          </div>
        </div>
      </div>

      <div id="toast" class="hidden rounded-lg px-4 py-2 text-sm"></div>

      <?php
        // Render helper for a single role card.
        $renderRole = function (array $entry) {
            $role = $entry['role'];
            $members = $entry['members'];
            $roleId = (int) $role['role_id'];
            $isChapter = ($role['category'] ?? '') === 'chapter';
            $searchIndex = strtolower(($role['name'] ?? '') . ' ' . ($role['chapter_name'] ?? '') . ' ' . ($role['chapter_state'] ?? ''));
            ?>
            <div class="role-card border border-gray-100 rounded-xl p-4 bg-white"
                 data-role-id="<?= $roleId ?>"
                 data-category="<?= e($role['category'] ?? '') ?>"
                 data-chapter-id="<?= (int) ($role['chapter_id'] ?? 0) ?>"
                 data-assigned-count="<?= count($members) ?>"
                 data-search="<?= e($searchIndex) ?>">
              <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-3">
                <div>
                  <p class="text-sm font-semibold text-gray-900"><?= e($role['name']) ?></p>
                  <?php if (!empty($role['email'])): ?>
                    <p class="text-xs text-slate-500"><?= e($role['email']) ?></p>
                  <?php endif; ?>
                  <?php if ($isChapter && !empty($role['chapter_state'])): ?>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?= e($role['chapter_state']) ?></p>
                  <?php endif; ?>
                </div>
                <span class="text-xs <?= $members ? 'text-emerald-700 bg-emerald-50 border-emerald-100' : 'text-slate-500 bg-slate-50 border-slate-100' ?> border px-2 py-0.5 rounded-full self-start whitespace-nowrap">
                  <?= $members ? count($members) . ' assigned' : 'Vacant' ?>
                </span>
              </div>

              <div class="space-y-2 mb-3" data-assignments>
                <?php foreach ($members as $m):
                  $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                  $isPrivate = !empty($m['committee_private']);
                ?>
                  <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm"
                       data-assignment data-member-id="<?= (int) $m['member_id'] ?>">
                    <a href="/admin/members/view.php?id=<?= (int) $m['member_id'] ?>" class="font-medium text-gray-900 hover:underline flex-1 min-w-0">
                      <?= e($name !== '' ? $name : 'Unnamed member') ?>
                    </a>
                    <label class="inline-flex items-center gap-1 text-xs text-slate-600 cursor-pointer" title="Hide last name + role phone on public cards">
                      <input type="checkbox" class="privacy-toggle rounded border-gray-300 text-primary focus:ring-2 focus:ring-primary"
                             data-member-id="<?= (int) $m['member_id'] ?>"
                             <?= $isPrivate ? 'checked' : '' ?>>
                      Private
                    </label>
                    <button type="button"
                            class="remove-assignment text-xs text-red-600 hover:text-red-800 inline-flex items-center gap-1"
                            data-role-id="<?= $roleId ?>"
                            data-member-id="<?= (int) $m['member_id'] ?>"
                            data-member-name="<?= e($name) ?>">
                      <span class="material-icons-outlined text-sm">close</span> Remove
                    </button>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="relative" data-search-box>
                <span class="material-icons-outlined absolute left-3 top-2.5 text-gray-400 text-base">person_search</span>
                <input type="search"
                       placeholder="Add member — name or member number"
                       class="member-search w-full rounded-lg border border-gray-200 bg-white pl-10 pr-3 py-2 text-sm shadow-sm"
                       data-role-id="<?= $roleId ?>"
                       autocomplete="off">
                <div class="search-results hidden absolute left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-10 max-h-72 overflow-y-auto"></div>
              </div>
            </div>
            <?php
        };
      ?>

      <?php if ($nationalRoles): ?>
        <section class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-category-block="national">
          <div>
            <h2 class="font-display text-lg font-bold text-gray-900">National Roles</h2>
            <p class="text-xs text-slate-500">Club-wide leadership positions.</p>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($nationalRoles as $entry) { $renderRole($entry); } ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($chapterRolesByState): ?>
        <section class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-4" data-category-block="chapter">
          <div>
            <h2 class="font-display text-lg font-bold text-gray-900">Chapter Representative Roles</h2>
            <p class="text-xs text-slate-500">One Area Rep per active chapter. New chapters get a role automatically.</p>
          </div>
          <?php foreach ($chapterRolesByState as $state => $entries): ?>
            <div class="space-y-2" data-state-block="<?= e($state) ?>">
              <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500"><?= e($state) ?></p>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($entries as $entry) { $renderRole($entry); } ?>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <?php if (!$nationalRoles && !$chapterRolesByState): ?>
        <div class="bg-card-light rounded-2xl border border-gray-100 p-8 text-center text-sm text-slate-500">
          No committee roles configured yet. Run the database migration to seed the default catalog.
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
(function () {
  const root = document.querySelector('[data-csrf]');
  const csrfToken = root.dataset.csrf;
  const toast = document.getElementById('toast');

  function showToast(message, kind) {
    toast.textContent = message;
    toast.className = 'rounded-lg px-4 py-2 text-sm ' + (kind === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700');
    toast.classList.remove('hidden');
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => toast.classList.add('hidden'), 3500);
  }

  async function post(data) {
    const body = new URLSearchParams({ csrf_token: csrfToken, ...data });
    const res = await fetch('/admin/settings/committee-roles-save.php', {
      method: 'POST',
      body,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); }
    catch (parseErr) {
      const snippet = text.replace(/\s+/g, ' ').slice(0, 80);
      throw new Error('Bad response (' + res.status + '): ' + snippet);
    }
    if (!res.ok || !json.ok) {
      throw new Error(json.detail || json.error || ('HTTP ' + res.status));
    }
    return json;
  }

  // ── Top filters: category dropdown + role/chapter text filter + hide-vacant
  const categoryFilter = document.getElementById('category-filter');
  const roleSearch = document.getElementById('role-search');
  const hideVacantToggle = document.getElementById('hide-vacant-toggle');

  // Persist the hide-vacant preference across the page reloads triggered by
  // assign/remove so the admin doesn't have to re-tick it every time.
  const HIDE_VACANT_KEY = 'committee-roles.hide-vacant';
  try {
    hideVacantToggle.checked = localStorage.getItem(HIDE_VACANT_KEY) === '1';
  } catch (e) { /* private mode etc — fine to ignore */ }

  function applyFilters() {
    const cat = categoryFilter.value;
    const term = roleSearch.value.trim().toLowerCase();
    const hideVacant = hideVacantToggle.checked;
    document.querySelectorAll('[data-category-block]').forEach(block => {
      const blockCat = block.dataset.categoryBlock;
      const catVisible = cat === 'all' || cat === blockCat;
      block.classList.toggle('hidden', !catVisible);
      if (!catVisible) return;
      let anyVisibleInBlock = false;
      block.querySelectorAll('.role-card').forEach(card => {
        const matchesTerm = term === '' || (card.dataset.search || '').includes(term);
        const assigned = parseInt(card.dataset.assignedCount || '0', 10);
        const passesVacancy = !hideVacant || assigned > 0;
        const visible = matchesTerm && passesVacancy;
        card.classList.toggle('hidden', !visible);
        if (visible) anyVisibleInBlock = true;
      });
      block.querySelectorAll('[data-state-block]').forEach(stateBlock => {
        const visibleCards = stateBlock.querySelectorAll('.role-card:not(.hidden)').length;
        stateBlock.classList.toggle('hidden', visibleCards === 0);
      });
      block.classList.toggle('hidden', !anyVisibleInBlock);
    });
  }
  categoryFilter.addEventListener('change', applyFilters);
  roleSearch.addEventListener('input', applyFilters);
  hideVacantToggle.addEventListener('change', () => {
    try { localStorage.setItem(HIDE_VACANT_KEY, hideVacantToggle.checked ? '1' : '0'); }
    catch (e) { /* ignore */ }
    applyFilters();
  });

  // Apply once on load so a persisted "hide vacant" pref takes effect immediately.
  applyFilters();

  // ── Privacy toggle ────────────────────────────────────────────────────
  document.addEventListener('change', async (ev) => {
    const tgt = ev.target;
    if (!tgt.classList.contains('privacy-toggle')) return;
    const memberId = tgt.dataset.memberId;
    try {
      await post({ action: 'set_privacy', member_id: memberId, private: tgt.checked ? '1' : '0' });
      // Mirror to any other rows for the same member visible on this page.
      document.querySelectorAll('.privacy-toggle[data-member-id="' + memberId + '"]').forEach(t => {
        if (t !== tgt) t.checked = tgt.checked;
      });
      showToast('Privacy updated.');
    } catch (e) {
      tgt.checked = !tgt.checked;
      showToast('Could not update privacy: ' + (e.message || 'unknown error'), 'error');
      console.error('committee-roles privacy error', e);
    }
  });

  // ── Remove assignment ─────────────────────────────────────────────────
  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.remove-assignment');
    if (!btn) return;
    const name = btn.dataset.memberName || 'this member';
    if (!confirm('Remove ' + name + ' from this role?')) return;
    try {
      await post({ action: 'unassign', role_id: btn.dataset.roleId, member_id: btn.dataset.memberId });
      showToast('Removed.');
      window.location.reload();
    } catch (e) {
      showToast('Could not remove assignment: ' + (e.message || 'unknown error'), 'error');
      console.error('committee-roles unassign error', e);
    }
  });

  // ── Add member: live search ───────────────────────────────────────────
  let searchTimer = null;
  document.addEventListener('input', (ev) => {
    const input = ev.target;
    if (!input.classList.contains('member-search')) return;
    const wrap = input.closest('[data-search-box]');
    const results = wrap.querySelector('.search-results');
    const q = input.value.trim();
    clearTimeout(searchTimer);
    if (q.length < 2) {
      results.classList.add('hidden');
      results.innerHTML = '';
      return;
    }
    searchTimer = setTimeout(async () => {
      try {
        const res = await fetch('/admin/settings/committee-roles-search.php?q=' + encodeURIComponent(q), {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin',
        });
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); }
        catch (parseErr) {
          // Server didn't return JSON — show first 80 chars of body so we
          // can diagnose (likely an HTML error page or login redirect).
          const snippet = text.replace(/\s+/g, ' ').slice(0, 80);
          throw new Error('Bad response (' + res.status + '): ' + snippet);
        }
        if (!res.ok || json.ok === false) {
          throw new Error(json.detail || json.error || ('HTTP ' + res.status));
        }
        renderSearchResults(results, json.results || [], input.dataset.roleId);
      } catch (e) {
        results.innerHTML = '<div class="px-3 py-2 text-xs text-red-600">Search failed: '
          + escapeHtml(e.message || 'unknown error')
          + '</div>';
        results.classList.remove('hidden');
        console.error('committee-roles search error', e);
      }
    }, 220);
  });

  function renderSearchResults(container, results, roleId) {
    if (!results.length) {
      container.innerHTML = '<div class="px-3 py-2 text-xs text-slate-500">No matches.</div>';
      container.classList.remove('hidden');
      return;
    }
    container.innerHTML = results.map(r => {
      const meta = [r.member_number, r.chapter].filter(Boolean).join(' · ');
      return '<button type="button" class="result-row block w-full text-left px-3 py-2 hover:bg-primary/5 border-b border-gray-100 last:border-0"'
        + ' data-role-id="' + roleId + '"'
        + ' data-member-id="' + r.id + '"'
        + ' data-member-name="' + escapeAttr(r.name) + '">'
        + '<div class="text-sm font-medium text-gray-900">' + escapeHtml(r.name) + '</div>'
        + (meta ? '<div class="text-xs text-slate-500">' + escapeHtml(meta) + '</div>' : '')
        + '</button>';
    }).join('');
    container.classList.remove('hidden');
  }

  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function escapeAttr(s) { return escapeHtml(s); }

  // Click a search result → assign.
  document.addEventListener('click', async (ev) => {
    const row = ev.target.closest('.result-row');
    if (!row) return;
    try {
      await post({ action: 'assign', role_id: row.dataset.roleId, member_id: row.dataset.memberId });
      showToast('Assigned.');
      window.location.reload();
    } catch (e) {
      showToast('Could not assign role: ' + (e.message || 'unknown error'), 'error');
      console.error('committee-roles assign error', e);
    }
  });

  // Close results when clicking outside.
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-search-box]')) return;
    document.querySelectorAll('.search-results').forEach(r => {
      r.classList.add('hidden');
      r.innerHTML = '';
    });
  });
})();
</script>

<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
</body>
</html>
