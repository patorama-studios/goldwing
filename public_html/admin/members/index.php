<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\MemberRepository;
use App\Services\SecurityPolicyService;
use App\Services\Database;
use App\Services\ChapterRepository;
use App\Services\Csrf;

require_role(['super_admin', 'admin', 'committee', 'treasurer', 'chapter_leader']);

$user = current_user();
$roles = $user['roles'] ?? [];
$chapterRestriction = null;
if (!in_array('admin', $roles, true) && !in_array('super_admin', $roles, true) && in_array('chapter_leader', $roles, true)) {
    $memberId = $user['member_id'] ?? null;
    if ($memberId) {
        $stmt = db()->prepare('SELECT chapter_id FROM members WHERE id = :id');
        $stmt->execute(['id' => $memberId]);
        $row = $stmt->fetch();
        if ($row && $row['chapter_id']) {
            $chapterRestriction = (int) $row['chapter_id'];
        }
    }
}
$canInlineEdit = !empty(array_intersect($roles, ['super_admin', 'admin', 'committee']));

$allowedLimits = [25, 50, 100];
$limit = (int) ($_GET['limit'] ?? 25);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 25;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'membership_type_id' => isset($_GET['membership_type_id']) && $_GET['membership_type_id'] !== '' ? (int) $_GET['membership_type_id'] : null,
    'status' => $_GET['status'] ?? '',
    'role' => trim((string) ($_GET['role'] ?? '')),
    'directory_prefs' => $_GET['directory_pref'] ?? [],
    'vehicle_type' => $_GET['vehicle_type'] ?? '',
    'vehicle_make' => trim((string) ($_GET['vehicle_make'] ?? '')),
    'vehicle_model' => trim((string) ($_GET['vehicle_model'] ?? '')),
    'vehicle_year_exact' => trim((string) ($_GET['vehicle_year_exact'] ?? '')),
    'vehicle_year_from' => trim((string) ($_GET['vehicle_year_from'] ?? '')),
    'vehicle_year_to' => trim((string) ($_GET['vehicle_year_to'] ?? '')),
    'has_trike' => $_GET['has_trike'] ?? null,
    'has_trailer' => $_GET['has_trailer'] ?? null,
    'has_sidecar' => $_GET['has_sidecar'] ?? null,
];

if ($chapterRestriction !== null) {
    $filters['chapter_id'] = $chapterRestriction;
} elseif (isset($_GET['chapter_id']) && $_GET['chapter_id'] !== '') {
    $filters['chapter_id'] = (int) $_GET['chapter_id'];
}

$statusFilter = $filters['status'];
if ($statusFilter === 'archived') {
    $filters['status'] = 'cancelled';
}
if ($statusFilter === 'cancelled') {
    $statusFilter = 'archived';
}
if ($filters['status'] === '') {
    $filters['exclude_statuses'] = ['cancelled', 'pending'];
}

$result = MemberRepository::search($filters, $limit, $offset);
$members = $result['data'];
$totalMembers = $result['total'];
$statsChapter = $filters['chapter_id'] ?? null;
$stats = MemberRepository::stats($statsChapter ?? null);

$pdo = Database::connection();
$allChapters = ChapterRepository::listForSelection($pdo, false);
$availableChapters = $chapterRestriction !== null ? array_filter($allChapters, fn($row) => (int) $row['id'] === $chapterRestriction) : $allChapters;

$membershipStmt = $pdo->prepare('SELECT id, name FROM membership_types WHERE is_active = 1 ORDER BY name');
$membershipStmt->execute();
$membershipTypes = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);

$roleStmt = $pdo->prepare('SELECT name FROM roles ORDER BY name');
$roleStmt->execute();
$availableRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

$directoryPrefs = MemberRepository::directoryPreferences();
$chapterSelectOptions = array_map(fn($chapter) => [
    'id' => (int) ($chapter['id'] ?? 0),
    'label' => trim(($chapter['name'] ?? '') . (($chapter['state'] ?? '') ? ' (' . $chapter['state'] . ')' : '')),
], $availableChapters);
$statusOptions = ['pending', 'active', 'expired', 'cancelled', 'suspended'];

$flash = $_SESSION['members_flash'] ?? null;
unset($_SESSION['members_flash']);

function buildQuery(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }
    return http_build_query($params);
}

function statusBadgeClasses(string $status): string
{
    return match ($status) {
        'active' => 'bg-green-100 text-green-800',
        'pending' => 'bg-yellow-100 text-yellow-800',
        'expired' => 'bg-red-100 text-red-800',
        'cancelled' => 'bg-amber-100 text-amber-800',
        'suspended' => 'bg-indigo-100 text-indigo-800',
        default => 'bg-slate-100 text-slate-800',
    };
}

function statusLabel(string $status): string
{
    if ($status === 'cancelled') {
        return 'Archived';
    }
    return ucfirst($status);
}

function memberInitials(string $firstName, string $lastName): string
{
    $first = mb_substr($firstName, 0, 1);
    $last = mb_substr($lastName, 0, 1);
    return strtoupper($first . $last);
}

function formatDate(?string $value): string
{
    if (!$value) {
        return '—';
    }
    return date('Y-m-d', strtotime($value));
}

$hasAdvancedFilters = $filters['vehicle_type'] !== ''
    || $filters['vehicle_make'] !== ''
    || $filters['vehicle_model'] !== ''
    || $filters['vehicle_year_exact'] !== ''
    || $filters['vehicle_year_from'] !== ''
    || $filters['vehicle_year_to'] !== ''
    || !empty($filters['directory_prefs'])
    || filter_var($filters['has_trike'], FILTER_VALIDATE_BOOLEAN)
    || filter_var($filters['has_trailer'], FILTER_VALIDATE_BOOLEAN)
|| filter_var($filters['has_sidecar'], FILTER_VALIDATE_BOOLEAN);

$membersListConfig = [
    'csrf' => Csrf::token(),
    'chapters' => $chapterSelectOptions,
    'statuses' => $statusOptions,
];
$membersListConfigJson = htmlspecialchars(json_encode($membersListConfig, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
$canInlineEdit = $canInlineEdit ?? false;

$pageTitle = 'Members';
$activePage = 'members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Members'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($flash): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Total members</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e((string) $stats['total']) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-primary/20 text-primary-strong flex items-center justify-center">
            <span class="material-icons-outlined text-base">groups</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Active</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e((string) $stats['active']) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">check_circle</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Pending</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e((string) $stats['pending']) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">hourglass_top</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Expired</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e((string) $stats['expired']) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">cancel</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">New (30d)</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e((string) $stats['new_last_30_days']) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">person_add</span>
          </div>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Renewals</p>
            <p class="text-2xl font-semibold text-gray-900"><?= e((string) $stats['renewals_this_month']) ?></p>
          </div>
          <div class="h-10 w-10 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center">
            <span class="material-icons-outlined text-base">autorenew</span>
          </div>
        </div>
      </section>

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 class="font-display text-2xl font-bold text-gray-900">Members</h1>
            <p class="text-sm text-gray-500">Manage and view all association members.</p>
            <?php if ($chapterRestriction !== null): ?>
              <p class="text-xs text-gray-500 mt-1">Showing chapter members only.</p>
            <?php endif; ?>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members/export.php?<?= e(buildQuery()) ?>">
              <span class="material-icons-outlined text-sm">download</span>
              Export CSV
            </a>
            <details class="relative">
              <summary class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300 cursor-pointer">
                <span class="material-icons-outlined text-sm">upload</span>
                Import CSV
              </summary>
              <div class="mt-2 w-full md:w-[420px] rounded-2xl border border-gray-200 bg-white p-4 shadow-lg">
                <form method="post" action="/admin/members/import.php" enctype="multipart/form-data" class="space-y-3">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <div>
                    <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">CSV file</label>
                    <input type="file" name="members_csv" accept=".csv,text/csv" required class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  </div>
                  <p class="text-xs text-gray-500">
                    Required columns: first_name, last_name, member_id, email. Optional: member_type, status, phone, address_line1,
                    address_line2, city, suburb, state, postal_code, country, chapter_id, chapter, full_member_number, privacy_level,
                    assist_ute, assist_phone, assist_bed, assist_tools, exclude_printed, exclude_electronic, membership_type_id, notes.
                  </p>
                  <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900 hover:bg-primary/90">
                    <span class="material-icons-outlined text-sm">file_upload</span>
                    Upload &amp; import
                  </button>
                </form>
              </div>
            </details>
            <a class="inline-flex items-center gap-2 rounded-full bg-red-500 px-4 py-2 text-xs font-semibold text-white hover:bg-red-600" href="/admin/members">
              <span class="material-icons-outlined text-sm">filter_alt_off</span>
              Clear filters
            </a>
          </div>
        </div>
        <form method="get" class="space-y-4 p-5" id="members-filters">
          <?php if ($chapterRestriction !== null): ?>
            <input type="hidden" name="chapter_id" value="<?= e($chapterRestriction) ?>">
          <?php endif; ?>
          <div class="grid gap-4 lg:grid-cols-4">
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Search
              <input type="search" name="q" value="<?= e($filters['q']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-primary focus:ring-2 focus:ring-primary/40" placeholder="Name, email, phone, or ID">
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Chapter
              <select name="chapter_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" <?= $chapterRestriction !== null ? 'disabled' : '' ?>>
                <option value="">All chapters</option>
                <?php foreach ($availableChapters as $chapter): ?>
                  <option value="<?= e($chapter['id']) ?>" <?= isset($filters['chapter_id']) && (int) $filters['chapter_id'] === (int) $chapter['id'] ? 'selected' : '' ?>><?= e($chapter['name']) ?> (<?= e($chapter['state']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Membership type
              <select name="membership_type_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <option value="">All types</option>
                <?php foreach ($membershipTypes as $type): ?>
                  <option value="<?= e($type['id']) ?>" <?= isset($filters['membership_type_id']) && (int) $filters['membership_type_id'] === (int) $type['id'] ? 'selected' : '' ?>><?= e($type['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Status
              <select name="status" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <option value="">All statuses</option>
                <?php foreach (['pending', 'active', 'expired', 'suspended', 'archived'] as $statusOption): ?>
                  <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= $statusOption === 'archived' ? 'Archived' : ucfirst($statusOption) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <details class="rounded-2xl border border-gray-200 bg-gray-50 p-4" <?= $hasAdvancedFilters ? 'open' : '' ?>>
            <summary class="cursor-pointer text-sm font-semibold text-gray-700 flex items-center gap-2">
              <span class="material-icons-outlined text-base text-primary-strong">tune</span>
              Advanced filters
            </summary>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <label class="flex flex-col text-sm font-medium text-gray-700">
                User role
                <select name="role" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="">All roles</option>
                  <?php foreach ($availableRoles as $role): ?>
                    <?php $roleName = $role['name'] ?? ''; ?>
                    <option value="<?= e($roleName) ?>" <?= $filters['role'] === $roleName ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $roleName))) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Vehicle type
                <select name="vehicle_type" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="">Any type</option>
                  <?php foreach (['bike', 'trike', 'sidecar', 'trailer'] as $type): ?>
                    <option value="<?= e($type) ?>" <?= $filters['vehicle_type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Make
                <input type="text" name="vehicle_make" value="<?= e($filters['vehicle_make']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Model
                <input type="text" name="vehicle_model" value="<?= e($filters['vehicle_model']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Year exact
                <input type="number" min="1900" max="2100" name="vehicle_year_exact" value="<?= e($filters['vehicle_year_exact']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Year range
                <div class="mt-1 grid grid-cols-2 gap-2">
                  <input type="number" min="1900" max="2100" name="vehicle_year_from" value="<?= e($filters['vehicle_year_from']) ?>" class="rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="From">
                  <input type="number" min="1900" max="2100" name="vehicle_year_to" value="<?= e($filters['vehicle_year_to']) ?>" class="rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="To">
                </div>
              </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-4">
              <?php foreach (['trike', 'trailer', 'sidecar'] as $type): ?>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                  <input type="checkbox" name="has_<?= e($type) ?>" value="1" <?= isset($filters['has_' . $type]) && filter_var($filters['has_' . $type], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                  Has <?= ucfirst($type) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <?php foreach ($directoryPrefs as $letter => $info): ?>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                  <input type="checkbox" name="directory_pref[]" value="<?= e($letter) ?>" <?= in_array($letter, $filters['directory_prefs'] ?? [], true) ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                  <?= e($letter) ?> — <?= e($info['label']) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
          <div class="flex flex-wrap gap-2">
            <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900 transition hover:bg-primary/80">Apply filters</button>
            <a href="/admin/members" class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700">Reset</a>
          </div>
        </form>
      </section>

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm" data-members-list>
        <div data-bulk-toolbar class="hidden border-b border-gray-100 px-5 py-4 bg-slate-50">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-baseline gap-2 text-sm text-gray-800">
              <span class="font-semibold" data-selected-count>0</span>
              <span class="text-gray-500">selected</span>
              <span class="text-xs text-gray-400">Actions apply to highlighted rows.</span>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" data-bulk-action="archive" class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">Archive</button>
              <button type="button" data-bulk-action="delete" class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">Delete</button>
              <button type="button" data-bulk-action="assign_chapter" class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Assign chapter</button>
              <button type="button" data-bulk-action="change_status" class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700">Change status</button>
              <button type="button" data-bulk-action="enable_2fa" class="rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-emerald-700">Require 2FA</button>
              <button type="button" data-bulk-action="send_reset_link" class="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">Password reset</button>
            </div>
            <button type="button" data-select-all-page class="text-xs font-semibold text-primary">Select all on page</button>
          </div>
        </div>
        <div data-bulk-message class="hidden px-5 py-3 text-sm text-gray-600"></div>
        <div data-members-config="<?= $membersListConfigJson ?>" class="hidden"></div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm block md:table" data-members-table>
            <thead class="hidden md:table-header-group text-left text-xs uppercase text-gray-500 border-b">
              <tr>
                <th class="py-3 px-4 w-10">
                  <label class="sr-only">Select all</label>
                  <input type="checkbox" data-select-all>
                </th>
                <th class="py-3 px-4">Member</th>
                <th class="py-3 px-4">Contact info</th>
                <th class="py-3 px-4">Chapter</th>
                <th class="py-3 px-4">Status</th>
                <th class="py-3 px-4">2FA</th>
                <th class="py-3 px-4">Created</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="block divide-y md:table-row-group">
              <?php foreach ($members as $member): ?>
                <?php
                  $memberRoles = [];
                  if (!empty($member['user_roles_csv'])) {
                      $memberRoles = array_filter(array_map('trim', explode(',', $member['user_roles_csv'])));
                  }
                  $requirement = SecurityPolicyService::computeTwoFaRequirement([
                      'id' => (int) ($member['user_id'] ?? 0),
                      'roles' => $memberRoles,
                  ]);
                  $override = $member['twofa_override'] ?? 'DEFAULT';
                  if ($override === 'EXEMPT') {
                      $enforcement = 'Exempt';
                  } elseif ($requirement === 'DISABLED') {
                      $enforcement = 'Disabled';
                  } else {
                      $enforcement = $requirement === 'REQUIRED' ? 'Required' : 'Optional';
                  }
                  $fullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                  $initials = memberInitials($member['first_name'] ?? 'M', $member['last_name'] ?? 'M');
                  $chapterLabel = $member['chapter_name'] ?? 'Unassigned';
                  $statusLabelText = statusLabel($member['status']);
                  $twoFaRequired = $override === 'REQUIRED';
                ?>
                <tr class="block md:table-row" data-member-row data-member-id="<?= e((int) $member['id']) ?>">
                  <td class="block px-4 py-3 md:table-cell md:w-10 md:px-4 md:py-3">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Select</span>
                    <div class="mt-1 md:mt-0">
                      <input type="checkbox" data-member-checkbox value="<?= e((int) $member['id']) ?>" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                    </div>
                  </td>
                  <td class="block px-4 py-3 md:table-cell md:px-4 md:py-4">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Member</span>
                    <div class="mt-1 flex items-center gap-3 md:mt-0">
                      <div class="h-10 w-10 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-sm font-semibold">
                        <?= e($initials) ?>
                      </div>
                      <div>
                        <a class="text-sm font-semibold text-gray-900 hover:text-primary" href="/admin/members/view.php?id=<?= e((int) $member['id']) ?>">
                          <?= e($fullName !== '' ? $fullName : 'Member') ?>
                        </a>
                        <p class="text-xs text-gray-500">ID: <?= e($member['member_number_display'] ?? '—') ?></p>
                      </div>
                    </div>
                  </td>
                  <td class="block px-4 py-3 text-gray-600 md:table-cell md:px-4 md:py-4">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Contact</span>
                    <div class="mt-1 md:mt-0">
                      <p class="text-sm"><?= e($member['email']) ?></p>
                      <p class="text-xs text-gray-500"><?= e($member['phone'] ?? '—') ?></p>
                    </div>
                  </td>
                  <td class="block px-4 py-3 md:table-cell md:px-4 md:py-4">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Chapter</span>
                    <div class="mt-1 md:mt-0">
                    <?php if ($canInlineEdit): ?>
                      <div class="space-y-2" data-inline-field data-field="chapter" data-member-id="<?= e((int) $member['id']) ?>">
                        <button type="button" class="flex items-center justify-between w-full rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm" data-inline-trigger>
                          <span class="text-left" data-inline-value><?= e($chapterLabel) ?></span>
                          <span class="material-icons-outlined text-sm">edit</span>
                        </button>
                        <div class="hidden space-y-2" data-inline-editor>
                          <select class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30" data-inline-input>
                            <option value="">Unassigned</option>
                            <?php foreach ($availableChapters as $chapter): ?>
                              <?php $chapterState = $chapter['state'] ?? ''; ?>
                              <option value="<?= e($chapter['id']) ?>" <?= (int) ($member['chapter_id'] ?? 0) === (int) $chapter['id'] ? 'selected' : '' ?>>
                                <?= e($chapter['name'] . ($chapterState ? ' (' . $chapterState . ')' : '')) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <p class="text-xs text-gray-500" data-inline-feedback></p>
                        </div>
                      </div>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                        <?= e($chapterLabel) ?>
                      </span>
                    <?php endif; ?>
                    </div>
                  </td>
                  <td class="block px-4 py-3 md:table-cell md:px-4 md:py-4">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Status</span>
                    <div class="mt-1 md:mt-0">
                    <?php if ($canInlineEdit): ?>
                      <div class="space-y-2" data-inline-field data-field="status" data-member-id="<?= e((int) $member['id']) ?>">
                        <button type="button" class="flex items-center justify-between w-full rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm" data-inline-trigger>
                          <span class="text-left inline-flex items-center gap-2" data-inline-value>
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= statusBadgeClasses($member['status']) ?>" data-inline-badge><?= e($statusLabelText) ?></span>
                          </span>
                          <span class="material-icons-outlined text-sm">edit</span>
                        </button>
                        <div class="hidden space-y-2" data-inline-editor>
                          <select class="w-full rounded-2xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-900 shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/30" data-inline-input>
                            <?php foreach ($statusOptions as $statusOption): ?>
                              <option value="<?= e($statusOption) ?>" <?= $statusOption === $member['status'] ? 'selected' : '' ?>>
                                <?= e($statusOption === 'cancelled' ? 'Archived' : ucfirst($statusOption)) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <p class="text-xs text-gray-500" data-inline-feedback></p>
                        </div>
                      </div>
                    <?php else: ?>
                      <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= statusBadgeClasses($member['status']) ?>"><?= e($statusLabelText) ?></span>
                    <?php endif; ?>
                    </div>
                  </td>
                  <td class="block px-4 py-3 text-xs md:table-cell md:px-4 md:py-4">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">2FA</span>
                    <div class="mt-1 md:mt-0">
                    <div class="space-y-1" data-inline-field data-field="twofa" data-member-id="<?= e((int) $member['id']) ?>" data-twofa-required="<?= $twoFaRequired ? '1' : '0' ?>">
                      <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold <?= $member['twofa_enabled_at'] ? 'text-emerald-700' : 'text-gray-500' ?>" data-inline-value>
                          <?= $twoFaRequired ? 'Required' : 'Optional' ?>
                        </p>
                        <?php if ($canInlineEdit): ?>
                          <button type="button" class="rounded-full border border-primary/30 bg-primary/5 px-4 py-2 text-sm font-semibold text-primary shadow-sm" data-inline-toggle data-state="<?= $twoFaRequired ? '1' : '0' ?>" aria-pressed="<?= $twoFaRequired ? 'true' : 'false' ?>">
                            <?= $twoFaRequired ? 'Set optional' : 'Require 2FA' ?>
                          </button>
                        <?php endif; ?>
                      </div>
                      <p class="text-xs <?= $enforcement === 'Disabled' ? 'text-rose-500' : 'text-gray-500' ?>"><?= e($enforcement) ?></p>
                      <p class="text-xs text-gray-500 hidden" data-inline-feedback></p>
                    </div>
                    </div>
                  </td>
                  <td class="block px-4 py-3 text-gray-600 md:table-cell md:px-4 md:py-4">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Created</span>
                    <div class="mt-1 md:mt-0"><?= formatDate($member['created_at']) ?></div>
                  </td>
                  <td class="block px-4 py-3 md:table-cell md:px-4 md:py-4 md:text-right">
                    <span class="text-[11px] uppercase tracking-wide text-gray-400 md:hidden">Actions</span>
                    <div class="mt-1 md:mt-0">
                      <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members/view.php?id=<?= e((int) $member['id']) ?>">
                        View
                        <span class="material-icons-outlined text-sm">chevron_right</span>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($members)): ?>
                <tr class="block md:table-row">
                  <td colspan="8" class="block px-4 py-6 text-center text-gray-500 md:table-cell">No members found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 text-sm">
        <div class="flex flex-wrap items-center gap-3 text-gray-600">
          <span>Showing <?= e((string) ($totalMembers ? ($offset + 1) : 0)) ?> to <?= e((string) ($totalMembers ? min($page * $limit, $totalMembers) : 0)) ?> of <?= e((string) $totalMembers) ?> results</span>
          <label class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.2em] text-gray-400">
            Page size
            <select name="limit" class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" form="members-filters" onchange="this.form.submit()">
              <?php foreach ($allowedLimits as $size): ?>
                <option value="<?= e($size) ?>" <?= $limit === $size ? 'selected' : '' ?>><?= e($size) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="flex items-center gap-2">
          <?php if ($page > 1): ?>
            <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" href="/admin/members?<?= e(buildQuery(['page' => $page - 1])) ?>">&larr; Previous</a>
          <?php endif; ?>
          <?php if ($totalMembers > $page * $limit): ?>
            <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" href="/admin/members?<?= e(buildQuery(['page' => $page + 1])) ?>">Next &rarr;</a>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </main>
</div>
<script defer src="/assets/js/admin-members-list.js"></script>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
