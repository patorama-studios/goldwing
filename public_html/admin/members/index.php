<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\MemberRepository;
use App\Services\SecurityPolicyService;
use App\Services\Database;
use App\Services\ChapterRepository;
use App\Services\Csrf;
use App\Services\AdminMemberAccess;

require_permission('admin.members.view');

$user = current_user();
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);
$canInlineEdit = AdminMemberAccess::isFullAccess($user);

$allowedLimits = [25, 50, 100];
$limit = (int) ($_GET['limit'] ?? 25);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 25;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$memberIdFilter = isset($_GET['member_id']) && $_GET['member_id'] !== '' ? (int) $_GET['member_id'] : null;
if ($memberIdFilter !== null && $memberIdFilter <= 0) {
    $memberIdFilter = null;
}
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'member_id' => $memberIdFilter,
    'member_number' => trim((string) ($_GET['member_number'] ?? '')),
    'membership_type_id' => isset($_GET['membership_type_id']) && $_GET['membership_type_id'] !== '' ? (int) $_GET['membership_type_id'] : null,
    'status' => $_GET['status'] ?? '',
    'role' => trim((string) ($_GET['role'] ?? '')),
    'directory_prefs' => $_GET['directory_pref'] ?? [],
    'created_range' => trim((string) ($_GET['created_range'] ?? '')),
    'expiring_within' => in_array($_GET['expiring_within'] ?? '', ['30d', '60d', '90d', 'eoy', 'expired'], true) ? $_GET['expiring_within'] : '',
    'renewed' => ($_GET['renewed'] ?? '') === 'this_month' ? 'this_month' : '',
    'renewed_from' => trim((string) ($_GET['renewed_from'] ?? '')),
    'renewed_to' => trim((string) ($_GET['renewed_to'] ?? '')),
    'created_from' => trim((string) ($_GET['created_from'] ?? '')),
    'created_to' => trim((string) ($_GET['created_to'] ?? '')),
    'vehicle_type' => $_GET['vehicle_type'] ?? '',
    'vehicle_make' => trim((string) ($_GET['vehicle_make'] ?? '')),
    'vehicle_model' => trim((string) ($_GET['vehicle_model'] ?? '')),
    'vehicle_year_exact' => trim((string) ($_GET['vehicle_year_exact'] ?? '')),
    'vehicle_year_from' => trim((string) ($_GET['vehicle_year_from'] ?? '')),
    'vehicle_year_to' => trim((string) ($_GET['vehicle_year_to'] ?? '')),
    'has_trike' => $_GET['has_trike'] ?? null,
    'has_trailer' => $_GET['has_trailer'] ?? null,
    'has_sidecar' => $_GET['has_sidecar'] ?? null,
    'has_historic_rego' => $_GET['has_historic_rego'] ?? null,
    'wings_preference' => in_array($_GET['wings_preference'] ?? '', ['digital', 'print', 'both', 'printed'], true) ? $_GET['wings_preference'] : '',
];

$sortOptions = [
    'created' => 'Created date',
    'id' => 'Record ID',
    'member' => 'Member name',
    'member_number' => 'Membership #',
    'chapter' => 'Chapter',
    'status' => 'Status',
];
$sortBy = strtolower(trim((string) ($_GET['sort_by'] ?? 'member')));
if ($sortBy === 'member_id') {
    $sortBy = 'member_number';
}
if (!array_key_exists($sortBy, $sortOptions)) {
    $sortBy = 'member';
}
$sortDirInput = strtolower(trim((string) ($_GET['sort_dir'] ?? '')));
$sortDir = $sortDirInput !== '' ? $sortDirInput : ($sortBy === 'created' ? 'desc' : 'asc');
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}
$filters['sort_by'] = $sortBy;
$filters['sort_dir'] = $sortDir;

if ($chapterRestriction !== null) {
    $filters['chapter_id'] = $chapterRestriction;
} elseif (isset($_GET['chapter_id']) && $_GET['chapter_id'] !== '') {
    $filters['chapter_id'] = (int) $_GET['chapter_id'];
}

$statusFilter = strtolower(trim((string) $filters['status']));
if ($statusFilter === 'archived') {
    $filters['status'] = 'cancelled';
}
if ($statusFilter === 'cancelled') {
    $statusFilter = 'archived';
}
// Hide archived (cancelled/inactive) members from the default browse list —
// but NOT from an explicit search: typing a name or member number means "find
// this person", and silently filtering them out made archived members look
// missing entirely (July 2026: Gannon #1514, Recoquillion #1672).
if ($filters['status'] === '' && trim((string) ($filters['q'] ?? '')) === '') {
    $filters['exclude_statuses'] = ['cancelled'];
}

$result = MemberRepository::search($filters, $limit, $offset);
$members = $result['data'];
$totalMembers = $result['total'];
$stats = MemberRepository::stats($filters);

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
    'label' => trim(($chapter['display_label'] ?? $chapter['name'] ?? '') . (($chapter['state'] ?? '') ? ' (' . $chapter['state'] . ')' : '')),
], $availableChapters);
$statusOptions = ['pending', 'active', 'expired', 'cancelled', 'suspended'];

$flash = $_SESSION['members_flash'] ?? null;
unset($_SESSION['members_flash']);

// Batch fetch avatar URLs for all members on this page
$userIds = array_values(array_filter(array_column($members, 'user_id')));
$avatarsByUserId = [];
if ($userIds) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    try {
        $avatarStmt = $pdo->prepare("SELECT user_id, value_json FROM settings_user WHERE user_id IN ($placeholders) AND key_name = 'avatar_url'");
        $avatarStmt->execute($userIds);
        foreach ($avatarStmt->fetchAll(PDO::FETCH_ASSOC) as $avatarRow) {
            $decoded = json_decode($avatarRow['value_json'], true);
            if ($decoded) {
                $avatarsByUserId[(int) $avatarRow['user_id']] = $decoded;
            }
        }
    } catch (Throwable $e) {
        // avatar fetch is best-effort
    }
}

// Group members by chapter for accordion view
$membersByChapter = [];
foreach ($members as $member) {
    $chapterId = (int) ($member['chapter_id'] ?? 0);
    $key = $chapterId ?: 'unassigned';
    if (!isset($membersByChapter[$key])) {
        $membersByChapter[$key] = [
            'name' => $member['chapter_name'] ?? 'Unassigned',
            'state' => $member['chapter_state'] ?? '',
            'id' => $chapterId,
            'members' => [],
        ];
    }
    $membersByChapter[$key]['members'][] = $member;
}
uasort($membersByChapter, static fn($a, $b) => strcmp($a['name'], $b['name']));

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

$currentListQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');
$currentListUrl = '/admin/members/index.php' . ($currentListQuery !== '' ? '?' . $currentListQuery : '');
$returnToParam = '&return_to=' . rawurlencode($currentListUrl);
// Remember the active filter set so "Back" from a member profile restores it
// even when return_to gets dropped along the way (edit saves, associate hops).
$_SESSION['admin_members_list_url'] = $currentListUrl;

function normalizeMemberStatus(string $status): string
{
    $clean = strtolower(trim($status));
    return match ($clean) {
        'inactive', 'cancelled', 'archived' => 'cancelled',
        'lapsed', 'expired' => 'expired',
        default => $clean,
    };
}

function statusBadgeClasses(string $status): string
{
    return match (normalizeMemberStatus($status)) {
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
    $status = normalizeMemberStatus($status);
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

$hasAdvancedFilters = $filters['vehicle_type'] !== ''
    || $filters['vehicle_make'] !== ''
    || $filters['vehicle_model'] !== ''
    || $filters['vehicle_year_exact'] !== ''
    || $filters['vehicle_year_from'] !== ''
    || $filters['vehicle_year_to'] !== ''
    || $filters['created_from'] !== ''
    || $filters['created_to'] !== ''
    || $filters['created_range'] !== ''
    || $filters['renewed_from'] !== ''
    || $filters['renewed_to'] !== ''
    || $filters['status'] !== ''
    || $filters['member_number'] !== ''
    || $filters['member_id'] !== null
    || $filters['membership_type_id'] !== null
    || $filters['role'] !== ''
    || !empty($filters['directory_prefs'])
    || filter_var($filters['has_trike'], FILTER_VALIDATE_BOOLEAN)
    || filter_var($filters['has_trailer'], FILTER_VALIDATE_BOOLEAN)
    || filter_var($filters['has_sidecar'], FILTER_VALIDATE_BOOLEAN)
    || filter_var($filters['has_historic_rego'], FILTER_VALIDATE_BOOLEAN)
|| $filters['wings_preference'] !== '';

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
      <?php
      $pendingChapterRequests = [];
      try {
          $pendingChapterStmt = $pdo->query('SELECT ccr.member_id, m.first_name, m.last_name FROM chapter_change_requests ccr JOIN members m ON m.id = ccr.member_id WHERE ccr.status = "PENDING" ORDER BY ccr.requested_at ASC LIMIT 10');
          if ($pendingChapterStmt) {
              $pendingChapterRequests = $pendingChapterStmt->fetchAll(PDO::FETCH_ASSOC);
          }
      } catch (Throwable $e) {
      }
      if ($pendingChapterRequests && ($chapterRestriction === null || AdminMemberAccess::isFullAccess($user))):
      ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="flex items-start gap-3">
            <span class="material-icons-outlined text-amber-600 mt-0.5">warning</span>
            <div class="text-sm text-amber-900">
              <strong class="font-semibold text-amber-900">Pending Chapter Change Requests</strong>
              <p class="mt-1 text-amber-800">
                The following members have requested to change their chapter:
                <?php
                $links = [];
                foreach ($pendingChapterRequests as $req) {
                  $links[] = '<a href="/admin/members/view.php?id=' . e($req['member_id']) . '&tab=profile" class="font-medium underline hover:text-amber-900">' . e(trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''))) . '</a>';
                }
                echo implode(', ', $links);
                if (count($pendingChapterRequests) === 10) { echo ', and more...'; }
                ?>
              </p>
            </div>
          </div>
        </div>
      <?php endif; ?>

<?php
      // Every summary card is a filter shortcut. Each link resets the other
      // filter params so a card always shows exactly its own slice, and
      // $activeStat marks which card matches the current view.
      $statReset = ['status' => null, 'expiring_within' => null, 'created_range' => null, 'created_from' => null, 'created_to' => null, 'renewed' => null, 'renewed_from' => null, 'renewed_to' => null, 'page' => 1];
      $hasCreatedFilter = ($filters['created_range'] ?? '') !== '' || ($filters['created_from'] ?? '') !== '' || ($filters['created_to'] ?? '') !== '';
      $activeStat = 'total';
      if ($statusFilter === 'active') { $activeStat = 'active'; }
      elseif ($statusFilter === 'pending') { $activeStat = 'pending'; }
      elseif ($statusFilter === 'expired') { $activeStat = 'expired'; }
      elseif (($filters['expiring_within'] ?? '') === '60d') { $activeStat = 'expiring'; }
      elseif (($filters['renewed'] ?? '') === 'this_month') { $activeStat = 'renewed'; }
      elseif (($filters['created_range'] ?? '') === '30d') { $activeStat = 'new'; }
      elseif ($statusFilter !== '' || ($filters['expiring_within'] ?? '') !== '' || $hasCreatedFilter) { $activeStat = ''; }
      $statCard = function (string $key, string $label, string $value, string $icon, string $iconClass, string $hover, array $override) use ($statReset, $activeStat) {
          $href = '/admin/members?' . e(buildQuery(array_merge($statReset, $override)));
          $ring = $activeStat === $key ? ' ring-2 ring-primary/60 border-transparent' : ' border-gray-100';
          echo '<a class="bg-white rounded-2xl p-4 shadow-sm border flex items-center justify-between transition-colors ' . $hover . $ring . '" href="' . $href . '">'
             . '<div><p class="text-xs uppercase tracking-[0.3em] text-gray-500">' . e($label) . '</p>'
             . '<p class="text-2xl font-semibold text-gray-900">' . e($value) . '</p></div>'
             . '<div class="h-10 w-10 rounded-full ' . $iconClass . ' flex items-center justify-center">'
             . '<span class="material-icons-outlined text-base">' . e($icon) . '</span></div></a>';
      };
      ?>
      <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <?php
        $statCard('total', 'Total members', (string) $stats['total'], 'groups', 'bg-primary/20 text-primary-strong', 'hover:border-gray-300', []);
        $statCard('active', 'Active', (string) $stats['active'], 'check_circle', 'bg-emerald-100 text-emerald-700', 'hover:border-emerald-200', ['status' => 'active']);
        $statCard('pending', 'Pending', (string) $stats['pending'], 'hourglass_top', 'bg-amber-100 text-amber-700', 'hover:border-amber-200', ['status' => 'pending']);
        $statCard('expired', 'Expired', (string) $stats['expired'], 'cancel', 'bg-rose-100 text-rose-700', 'hover:border-rose-200', ['status' => 'expired']);
        $statCard('expiring', 'Expiring (60d)', (string) $stats['expiring_soon'], 'alarm', 'bg-blue-100 text-blue-700', 'hover:border-blue-200', ['expiring_within' => '60d']);
        $statCard('new', 'New (30d)', (string) $stats['new_last_30_days'], 'person_add', 'bg-blue-100 text-blue-700', 'hover:border-blue-200', ['created_range' => '30d']);
        $statCard('renewed', 'Renewals', (string) $stats['renewals_this_month'], 'autorenew', 'bg-purple-100 text-purple-700', 'hover:border-purple-200', ['renewed' => 'this_month']);
        ?>
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
            <?php if ($chapterRestriction === null && AdminMemberAccess::isFullAccess($user) && AdminMemberAccess::canManualOrderFix($user)): ?>
            <a class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-white hover:bg-primary-strong" href="/admin/members/add.php">
              <span class="material-icons-outlined text-sm">person_add</span>
              Add Member
            </a>
            <?php endif; ?>
            <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members/export.php?<?= e(buildQuery()) ?>">
              <span class="material-icons-outlined text-sm">download</span>
              Export CSV
            </a>
            <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members/export.php?wings_preference=printed" title="Members who need a physical copy posted (includes postal address columns)">
              <span class="material-icons-outlined text-sm">local_post_office</span>
              Printed mailing list
            </a>
            <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members/export.php?list=email_pdf" title="Every current member with an email address — the full electronic Wings PDF distribution list. Ignores print/digital preference and directory opt-outs (everyone is emailed the PDF).">
              <span class="material-icons-outlined text-sm">mark_email_read</span>
              Email PDF list
            </a>
            <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/members/email-group.php" title="Compose a custom email and send it to a chosen audience (all active members, a chapter, or members expiring soon).">
              <span class="material-icons-outlined text-sm">forward_to_inbox</span>
              Email a group
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
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <label class="flex flex-col text-sm font-medium text-gray-700 sm:col-span-2 lg:col-span-2" data-tour="admin-find-member-search">
              Search
              <input type="search" name="q" value="<?= e($filters['q']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-primary focus:ring-2 focus:ring-primary/40" placeholder="Name, email, or phone">
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700" data-tour="admin-find-member-chapter">
              Chapter
              <select name="chapter_id" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" <?= $chapterRestriction !== null ? 'disabled' : '' ?>>
                <option value="">All chapters</option>
                <option value="0" <?= isset($filters['chapter_id']) && (int) $filters['chapter_id'] === 0 ? 'selected' : '' ?>>No chapter assigned</option>
                <?php foreach ($availableChapters as $chapter): ?>
                  <option value="<?= e($chapter['id']) ?>" <?= isset($filters['chapter_id']) && (int) $filters['chapter_id'] === (int) $chapter['id'] ? 'selected' : '' ?>><?= e($chapter['display_label'] ?? $chapter['name']) ?> (<?= e($chapter['state']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Expiring
              <select name="expiring_within" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <?php $expiringWithin = $filters['expiring_within'] ?? ''; ?>
                <option value="" <?= $expiringWithin === '' ? 'selected' : '' ?>>Any time</option>
                <option value="30d" <?= $expiringWithin === '30d' ? 'selected' : '' ?>>Within 30 days</option>
                <option value="60d" <?= $expiringWithin === '60d' ? 'selected' : '' ?>>Within 60 days</option>
                <option value="90d" <?= $expiringWithin === '90d' ? 'selected' : '' ?>>Within 90 days</option>
                <option value="eoy" <?= $expiringWithin === 'eoy' ? 'selected' : '' ?>>Before next 31 July</option>
                <option value="expired" <?= $expiringWithin === 'expired' ? 'selected' : '' ?>>Already expired</option>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Sort by
              <select name="sort_by" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <?php foreach ($sortOptions as $value => $label): ?>
                  <option value="<?= e($value) ?>" <?= $sortBy === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex flex-col text-sm font-medium text-gray-700">
              Order
              <select name="sort_dir" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <option value="asc" <?= $sortDir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= $sortDir === 'desc' ? 'selected' : '' ?>>Descending</option>
              </select>
            </label>
          </div>
          <details class="rounded-2xl border border-gray-200 bg-gray-50 p-4" <?= $hasAdvancedFilters ? 'open' : '' ?>>
            <summary class="cursor-pointer text-sm font-semibold text-gray-700 flex items-center gap-2">
              <span class="material-icons-outlined text-base text-primary-strong">tune</span>
              Advanced filters
            </summary>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <label class="flex flex-col text-sm font-medium text-gray-700" data-tour="admin-find-member-status">
                Status
                <select name="status" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="">All statuses</option>
                  <?php foreach (['pending', 'active', 'expired', 'suspended', 'archived'] as $statusOption): ?>
                    <option value="<?= e($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= $statusOption === 'archived' ? 'Archived' : ucfirst($statusOption) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Created
                <select name="created_range" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <?php $createdRange = $filters['created_range'] ?? ''; ?>
                  <option value="" <?= $createdRange === '' ? 'selected' : '' ?>>Any time</option>
                  <option value="7d" <?= $createdRange === '7d' ? 'selected' : '' ?>>Last 7 days</option>
                  <option value="30d" <?= $createdRange === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                  <option value="90d" <?= $createdRange === '90d' ? 'selected' : '' ?>>Last 90 days</option>
                  <option value="1y" <?= $createdRange === '1y' ? 'selected' : '' ?>>Last 12 months</option>
                  <option value="this_year" <?= $createdRange === 'this_year' ? 'selected' : '' ?>>This year</option>
                </select>
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Membership #
                <input type="text" name="member_number" value="<?= e($filters['member_number'] ?? '') ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="e.g. 12345 or 12345.1">
              </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Member ID
                <input type="number" min="1" name="member_id" value="<?= e((string) ($filters['member_id'] ?? '')) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="Record ID">
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
                User role
                <select name="role" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="">All roles</option>
                  <?php foreach ($availableRoles as $role): ?>
                    <?php $roleName = $role['name'] ?? ''; ?>
                    <option value="<?= e($roleName) ?>" <?= $filters['role'] === $roleName ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $roleName))) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
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
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Model
                <input type="text" name="vehicle_model" value="<?= e($filters['vehicle_model']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
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
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Wings magazine
                <select name="wings_preference" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm bg-white">
                  <option value="" <?= $filters['wings_preference'] === '' ? 'selected' : '' ?>>All members (everyone is emailed the PDF)</option>
                  <option value="printed" <?= $filters['wings_preference'] === 'printed' ? 'selected' : '' ?>>Needs a printed copy posted</option>
                  <option value="digital" <?= $filters['wings_preference'] === 'digital' ? 'selected' : '' ?>>Email-only (no posted copy)</option>
                </select>
              </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Created from
                <input type="date" name="created_from" value="<?= e($filters['created_from']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Created to
                <input type="date" name="created_to" value="<?= e($filters['created_to']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Renewed from
                <input type="date" name="renewed_from" value="<?= e($filters['renewed_from']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <span class="mt-1 text-xs font-normal text-gray-400">Members who made a membership payment on/after this date.</span>
              </label>
              <label class="flex flex-col text-sm font-medium text-gray-700">
                Renewed to
                <input type="date" name="renewed_to" value="<?= e($filters['renewed_to']) ?>" class="mt-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <span class="mt-1 text-xs font-normal text-gray-400">…and on/before this date. Either date can be used on its own.</span>
              </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-4">
              <?php foreach (['trike', 'trailer', 'sidecar'] as $type): ?>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                  <input type="checkbox" name="has_<?= e($type) ?>" value="1" <?= isset($filters['has_' . $type]) && filter_var($filters['has_' . $type], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                  Has <?= ucfirst($type) ?>
                </label>
              <?php endforeach; ?>
              <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                <input type="checkbox" name="has_historic_rego" value="1" <?= filter_var($filters['has_historic_rego'] ?? null, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?> class="rounded border-gray-200 text-primary focus:ring-2 focus:ring-primary">
                Has Historic Rego
              </label>
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
            <button type="submit" data-tour="admin-find-member-apply" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900 transition hover:bg-primary/80">Apply filters</button>
            <a href="/admin/members" class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700">Reset</a>
            <a href="/admin/members?<?= e(buildQuery(['status' => 'pending', 'page' => 1])) ?>" class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">
              Pending only
            </a>
          </div>
        </form>
      </section>

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm" data-members-list data-tour="admin-find-member-results">
        <!-- Section header with view toggle -->
        <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between gap-3">
          <p class="text-xs text-gray-500"><?= e((string) $totalMembers) ?> result<?= $totalMembers !== 1 ? 's' : '' ?></p>
          <div class="flex items-center gap-1 rounded-xl border border-gray-200 bg-gray-50 p-0.5" data-view-toggle>
            <button type="button" data-view-btn="list" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" title="Compact list">
              <span class="material-icons-outlined text-sm">view_list</span>
              List
            </button>
            <button type="button" data-view-btn="chapter" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" title="By chapter">
              <span class="material-icons-outlined text-sm">account_tree</span>
              Chapter
            </button>
            <button type="button" data-view-btn="grid" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors" title="Card grid">
              <span class="material-icons-outlined text-sm">grid_view</span>
              Grid
            </button>
          </div>
        </div>

        <!-- Bulk action toolbar -->
        <div data-bulk-toolbar class="hidden border-b border-gray-100 px-5 py-3 bg-slate-50">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-baseline gap-2 text-sm text-gray-800">
              <span class="font-semibold" data-selected-count>0</span>
              <span class="text-gray-500">selected</span>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" data-bulk-action="archive" class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">Archive</button>
              <button type="button" data-bulk-action="delete" class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">Delete</button>
              <button type="button" data-bulk-action="assign_chapter" class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Assign chapter</button>
              <button type="button" data-bulk-action="change_status" class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700">Change status</button>
              <button type="button" data-bulk-action="enable_2fa" class="rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-emerald-700">Require 2FA</button>
              <button type="button" data-bulk-action="send_welcome_email" class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Send welcome</button>
              <button type="button" data-bulk-action="send_reset_link" class="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">Reset password</button>
            </div>
            <button type="button" data-select-all-page class="text-xs font-semibold text-primary">Select all on page</button>
          </div>
        </div>
        <div data-bulk-message class="hidden px-5 py-2 text-sm text-gray-600"></div>
        <div data-members-config="<?= $membersListConfigJson ?>" class="hidden"></div>

        <!-- ══════════════════════════════════════════════════ -->
        <!-- VIEW 1: COMPACT LIST TABLE -->
        <!-- ══════════════════════════════════════════════════ -->
        <div data-view="list">
          <div class="overflow-x-auto">
            <table class="w-full text-sm" data-members-table>
              <thead class="text-left text-xs uppercase tracking-wide text-gray-400 bg-gray-50 border-b border-gray-100">
                <tr>
                  <th class="py-2.5 pl-4 pr-2 w-8">
                    <input type="checkbox" data-select-all class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary">
                  </th>
                  <th class="py-2.5 px-3">Member</th>
                  <th class="py-2.5 px-3">Chapter</th>
                  <th class="py-2.5 px-3">Type</th>
                  <th class="py-2.5 px-3">Email</th>
                  <th class="py-2.5 px-3">Status</th>
                  <th class="py-2.5 px-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php $tourRowIndex = 0; ?>
                <?php foreach ($members as $member): ?>
                  <?php
                    $fullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                    $initials = memberInitials($member['first_name'] ?? 'M', $member['last_name'] ?? 'M');
                    $chapterLabel = $member['chapter_name'] ?? 'Unassigned';
                    $chapterState = $member['chapter_state'] ?? '';
                    $statusLabelText = statusLabel($member['status']);
                    $statusKey = normalizeMemberStatus((string) ($member['status'] ?? ''));
                    $memberType = strtoupper((string) ($member['member_type'] ?? 'FULL'));
                    $isLifeMember = $memberType === 'LIFE';
                    $isAssociate = $memberType === 'ASSOCIATE';
                    $primaryMemberName = trim((string) ($member['primary_member_name'] ?? ''));
                    $userId = (int) ($member['user_id'] ?? 0);
                    $avatarUrl = !empty($member['avatar_url'])
                        ? (string) $member['avatar_url']
                        : ($userId ? ($avatarsByUserId[$userId] ?? '') : '');
                    $tourRowAttr = $tourRowIndex === 0 ? ' data-tour="admin-find-member-row"' : '';
                    $tourRowIndex++;
                  ?>
                  <tr class="group hover:bg-gray-50/70 transition-colors <?= $isLifeMember ? 'bg-yellow-50/50' : ($isAssociate ? 'bg-purple-50/20 hover:bg-purple-50/40' : ($statusKey === 'pending' ? 'bg-amber-50/40' : '')) ?>"
                      data-member-row data-member-id="<?= e((int) $member['id']) ?>" data-member-name="<?= e($fullName !== '' ? $fullName : 'Member') ?>"<?= $tourRowAttr ?>>

                    <!-- Checkbox -->
                    <td class="pl-4 pr-2 py-2.5 w-8">
                      <input type="checkbox" data-member-checkbox value="<?= e((int) $member['id']) ?>" class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary">
                    </td>

                    <!-- Member -->
                    <td class="px-3 py-2.5 <?= $isAssociate ? 'pl-7' : '' ?>">
                      <div class="flex items-center gap-2.5">
                        <?php if ($isAssociate): ?>
                          <span class="text-purple-300 text-sm leading-none flex-shrink-0">↳</span>
                        <?php endif; ?>
                        <!-- Avatar -->
                        <?php if ($avatarUrl): ?>
                          <img src="<?= e($avatarUrl) ?>" alt="<?= e($initials) ?>" class="h-8 w-8 rounded-full object-cover flex-shrink-0">
                        <?php else: ?>
                          <div class="h-8 w-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-bold
                            <?= $isLifeMember ? 'bg-yellow-200 text-yellow-800' : ($isAssociate ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-700') ?>">
                            <?= $isLifeMember ? '<span class="material-icons-outlined text-sm">star</span>' : e($initials) ?>
                          </div>
                        <?php endif; ?>
                        <!-- Name + number -->
                        <div class="min-w-0">
                          <a href="/admin/members/view.php?id=<?= e((int) $member['id']) ?><?= $returnToParam ?>"
                             class="text-sm font-semibold truncate <?= $isLifeMember ? 'text-yellow-900 hover:text-yellow-700' : ($isAssociate ? 'text-purple-900 hover:text-purple-700' : 'text-gray-900 hover:text-primary') ?>">
                            <?= e($fullName !== '' ? $fullName : 'Member') ?>
                          </a>
                          <p class="text-[11px] text-gray-400 leading-tight">#<?= e($member['member_number_display'] ?? '—') ?></p>
                        </div>
                      </div>
                    </td>

                    <!-- Chapter -->
                    <td class="px-3 py-2.5">
                      <?php if ($canInlineEdit): ?>
                        <div class="flex items-center gap-1 group/inline" data-inline-field data-field="chapter" data-member-id="<?= e((int) $member['id']) ?>">
                          <div data-inline-display>
                            <p class="text-sm text-gray-800 font-medium" data-inline-value><?= e($chapterLabel) ?></p>
                            <?php if ($chapterState): ?>
                              <p class="text-[11px] text-gray-400"><?= e($chapterState) ?></p>
                            <?php endif; ?>
                          </div>
                          <button type="button" data-inline-trigger
                            class="opacity-0 group-hover/inline:opacity-100 transition-opacity ml-1 rounded p-0.5 text-gray-400 hover:text-primary hover:bg-primary/10"
                            title="Edit chapter">
                            <span class="material-icons-outlined text-sm">edit</span>
                          </button>
                          <div class="hidden" data-inline-editor>
                            <select class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 focus:border-primary focus:ring-1 focus:ring-primary/30" data-inline-input>
                              <option value="">Unassigned</option>
                              <?php foreach ($availableChapters as $chapter): ?>
                                <?php $chapterState2 = $chapter['state'] ?? ''; ?>
                                <option value="<?= e($chapter['id']) ?>" <?= (int) ($member['chapter_id'] ?? 0) === (int) $chapter['id'] ? 'selected' : '' ?>>
                                  <?= e(($chapter['display_label'] ?? $chapter['name']) . ($chapterState2 ? ' (' . $chapterState2 . ')' : '')) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                            <p class="text-[11px] text-gray-400 mt-0.5" data-inline-feedback></p>
                          </div>
                        </div>
                      <?php else: ?>
                        <p class="text-sm text-gray-700"><?= e($chapterLabel) ?></p>
                        <?php if ($chapterState): ?>
                          <p class="text-[11px] text-gray-400"><?= e($chapterState) ?></p>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>

                    <!-- Type / Associate -->
                    <td class="px-3 py-2.5">
                      <?php if ($isLifeMember): ?>
                        <span class="inline-flex items-center gap-0.5 rounded-full bg-yellow-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-yellow-800">
                          <span class="material-icons-outlined text-[10px]">star</span>Life
                        </span>
                      <?php elseif ($isAssociate): ?>
                        <span class="inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-purple-700">Assoc.</span>
                        <?php if ($primaryMemberName !== ''): ?>
                          <p class="text-[11px] text-gray-400 mt-0.5 truncate max-w-[120px]" title="Associate of <?= e($primaryMemberName) ?>">of <?= e($primaryMemberName) ?></p>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="text-xs text-gray-400">Full</span>
                      <?php endif; ?>
                    </td>

                    <!-- Email -->
                    <td class="px-3 py-2.5">
                      <p class="text-sm text-gray-700 truncate max-w-[200px]" title="<?= e($member['email']) ?>"><?= e($member['email']) ?></p>
                    </td>

                    <!-- Status -->
                    <td class="px-3 py-2.5">
                      <?php if ($canInlineEdit): ?>
                        <div class="flex items-center gap-1 group/status" data-inline-field data-field="status" data-member-id="<?= e((int) $member['id']) ?>">
                          <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold <?= statusBadgeClasses($member['status']) ?>" data-inline-value data-inline-badge><?= e($statusLabelText) ?></span>
                          <button type="button" data-inline-trigger
                            class="opacity-0 group-hover/status:opacity-100 transition-opacity rounded p-0.5 text-gray-400 hover:text-primary hover:bg-primary/10"
                            title="Edit status">
                            <span class="material-icons-outlined text-sm">edit</span>
                          </button>
                          <div class="hidden" data-inline-editor>
                            <select class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 focus:border-primary focus:ring-1 focus:ring-primary/30" data-inline-input>
                              <?php foreach ($statusOptions as $statusOption): ?>
                                <option value="<?= e($statusOption) ?>" <?= $statusOption === $statusKey ? 'selected' : '' ?>>
                                  <?= e($statusOption === 'cancelled' ? 'Archived' : ucfirst($statusOption)) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                            <p class="text-[11px] text-gray-400 mt-0.5" data-inline-feedback></p>
                          </div>
                        </div>
                      <?php else: ?>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold <?= statusBadgeClasses($member['status']) ?>"><?= e($statusLabelText) ?></span>
                      <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td class="px-3 py-2.5 text-right">
                      <a href="/admin/members/view.php?id=<?= e((int) $member['id']) ?><?= $returnToParam ?>"
                         class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-600 hover:border-gray-300 hover:text-gray-900 transition-colors">
                        View
                        <span class="material-icons-outlined text-xs">chevron_right</span>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                  <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">No members found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ══════════════════════════════════════════════════ -->
        <!-- VIEW 2: CHAPTER ACCORDION -->
        <!-- ══════════════════════════════════════════════════ -->
        <div data-view="chapter" class="hidden divide-y divide-gray-100">
          <?php if (empty($membersByChapter)): ?>
            <p class="px-5 py-8 text-center text-sm text-gray-400">No members found.</p>
          <?php else: ?>
            <?php foreach ($membersByChapter as $chapterData): ?>
              <?php
                $chapterMembers = $chapterData['members'];
                $chapterMemberCount = count($chapterMembers);
                $activeCount = count(array_filter($chapterMembers, fn($m) => normalizeMemberStatus((string) ($m['status'] ?? '')) === 'active'));
                $pendingCount = count(array_filter($chapterMembers, fn($m) => normalizeMemberStatus((string) ($m['status'] ?? '')) === 'pending'));
              ?>
              <details class="group/chapter" open>
                <summary class="flex cursor-pointer select-none items-center justify-between gap-4 px-5 py-3 hover:bg-gray-50 transition-colors list-none">
                  <div class="flex items-center gap-3">
                    <span class="material-icons-outlined text-base text-gray-400 transition-transform group-open/chapter:rotate-90">chevron_right</span>
                    <div>
                      <span class="font-semibold text-gray-900"><?= e($chapterData['name']) ?></span>
                      <?php if ($chapterData['state']): ?>
                        <span class="ml-1.5 text-xs text-gray-400"><?= e($chapterData['state']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 flex-wrap justify-end">
                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600"><?= $chapterMemberCount ?> member<?= $chapterMemberCount !== 1 ? 's' : '' ?></span>
                    <?php if ($activeCount): ?><span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-700"><?= $activeCount ?> active</span><?php endif; ?>
                    <?php if ($pendingCount): ?><span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700"><?= $pendingCount ?> pending</span><?php endif; ?>
                  </div>
                </summary>
                <div class="border-t border-gray-100 bg-gray-50/40">
                  <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                      <?php foreach ($chapterMembers as $member): ?>
                        <?php
                          $fullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                          $initials = memberInitials($member['first_name'] ?? 'M', $member['last_name'] ?? 'M');
                          $statusKey = normalizeMemberStatus((string) ($member['status'] ?? ''));
                          $statusLabelText = statusLabel($member['status']);
                          $memberType = strtoupper((string) ($member['member_type'] ?? 'FULL'));
                          $isLifeMember = $memberType === 'LIFE';
                          $isAssociate = $memberType === 'ASSOCIATE';
                          $primaryMemberName = trim((string) ($member['primary_member_name'] ?? ''));
                          $userId = (int) ($member['user_id'] ?? 0);
                          $avatarUrl = !empty($member['avatar_url'])
                        ? (string) $member['avatar_url']
                        : ($userId ? ($avatarsByUserId[$userId] ?? '') : '');
                        ?>
                        <tr class="hover:bg-white/80 transition-colors <?= $isLifeMember ? 'bg-yellow-50/30' : '' ?>"
                            data-member-row data-member-id="<?= e((int) $member['id']) ?>" data-member-name="<?= e($fullName !== '' ? $fullName : 'Member') ?>">
                          <td class="pl-10 pr-3 py-2.5 w-8">
                            <input type="checkbox" data-member-checkbox value="<?= e((int) $member['id']) ?>" class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary">
                          </td>
                          <td class="px-3 py-2.5">
                            <div class="flex items-center gap-2.5">
                              <?php if ($avatarUrl): ?>
                                <img src="<?= e($avatarUrl) ?>" alt="<?= e($initials) ?>" class="h-7 w-7 rounded-full object-cover flex-shrink-0">
                              <?php else: ?>
                                <div class="h-7 w-7 rounded-full flex-shrink-0 flex items-center justify-center text-[10px] font-bold
                                  <?= $isLifeMember ? 'bg-yellow-200 text-yellow-800' : ($isAssociate ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-700') ?>">
                                  <?= $isLifeMember ? '<span class="material-icons-outlined text-xs">star</span>' : e($initials) ?>
                                </div>
                              <?php endif; ?>
                              <div>
                                <a href="/admin/members/view.php?id=<?= e((int) $member['id']) ?><?= $returnToParam ?>"
                                   class="text-sm font-medium text-gray-900 hover:text-primary">
                                  <?= e($fullName !== '' ? $fullName : 'Member') ?>
                                </a>
                                <?php if ($isAssociate && $primaryMemberName !== ''): ?>
                                  <p class="text-[11px] text-gray-400">of <?= e($primaryMemberName) ?></p>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>
                          <td class="px-3 py-2.5">
                            <p class="text-xs text-gray-500">#<?= e($member['member_number_display'] ?? '—') ?></p>
                          </td>
                          <td class="px-3 py-2.5">
                            <?php if ($isLifeMember): ?>
                              <span class="inline-flex items-center gap-0.5 rounded-full bg-yellow-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-yellow-800">
                                <span class="material-icons-outlined text-[10px]">star</span>Life
                              </span>
                            <?php elseif ($isAssociate): ?>
                              <span class="inline-flex rounded-full bg-purple-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-purple-700">Assoc.</span>
                            <?php else: ?>
                              <span class="text-xs text-gray-400">Full</span>
                            <?php endif; ?>
                          </td>
                          <td class="px-3 py-2.5">
                            <p class="text-sm text-gray-600 truncate max-w-[200px]"><?= e($member['email']) ?></p>
                          </td>
                          <td class="px-3 py-2.5">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold <?= statusBadgeClasses($member['status']) ?>"><?= e($statusLabelText) ?></span>
                          </td>
                          <td class="px-3 py-2.5 text-right pr-5">
                            <a href="/admin/members/view.php?id=<?= e((int) $member['id']) ?><?= $returnToParam ?>"
                               class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-600 hover:border-gray-300 transition-colors">
                              View <span class="material-icons-outlined text-xs">chevron_right</span>
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </details>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════════════════ -->
        <!-- VIEW 3: CARD GRID -->
        <!-- ══════════════════════════════════════════════════ -->
        <div data-view="grid" class="hidden p-5">
          <?php if (empty($members)): ?>
            <p class="py-8 text-center text-sm text-gray-400">No members found.</p>
          <?php else: ?>
            <div class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
              <?php foreach ($members as $member): ?>
                <?php
                  $fullName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                  $initials = memberInitials($member['first_name'] ?? 'M', $member['last_name'] ?? 'M');
                  $chapterLabel = $member['chapter_name'] ?? 'Unassigned';
                  $chapterState = $member['chapter_state'] ?? '';
                  $statusKey = normalizeMemberStatus((string) ($member['status'] ?? ''));
                  $statusLabelText = statusLabel($member['status']);
                  $memberType = strtoupper((string) ($member['member_type'] ?? 'FULL'));
                  $isLifeMember = $memberType === 'LIFE';
                  $isAssociate = $memberType === 'ASSOCIATE';
                  $primaryMemberName = trim((string) ($member['primary_member_name'] ?? ''));
                  $userId = (int) ($member['user_id'] ?? 0);
                  $avatarUrl = !empty($member['avatar_url'])
                      ? (string) $member['avatar_url']
                      : ($userId ? ($avatarsByUserId[$userId] ?? '') : '');
                  // Generate a deterministic colour for initials avatars
                  $colorPalette = ['bg-slate-200 text-slate-700','bg-blue-100 text-blue-700','bg-teal-100 text-teal-700','bg-violet-100 text-violet-700','bg-rose-100 text-rose-700','bg-orange-100 text-orange-700','bg-cyan-100 text-cyan-700'];
                  $colorClass = $isLifeMember ? 'bg-yellow-200 text-yellow-800' : ($isAssociate ? 'bg-purple-100 text-purple-700' : $colorPalette[crc32($fullName) % count($colorPalette)]);
                ?>
                <a href="/admin/members/view.php?id=<?= e((int) $member['id']) ?><?= $returnToParam ?>"
                   class="group flex flex-col items-center rounded-2xl border border-gray-200 bg-white p-4 text-center shadow-sm hover:border-primary/30 hover:shadow-md transition-all">
                  <!-- Avatar -->
                  <div class="relative mb-3">
                    <?php if ($avatarUrl): ?>
                      <img src="<?= e($avatarUrl) ?>" alt="<?= e($initials) ?>"
                           class="h-16 w-16 rounded-full object-cover ring-2 ring-white shadow">
                    <?php else: ?>
                      <div class="h-16 w-16 rounded-full flex items-center justify-center text-xl font-bold ring-2 ring-white shadow <?= $colorClass ?>">
                        <?= $isLifeMember ? '<span class="material-icons-outlined text-2xl">star</span>' : e($initials) ?>
                      </div>
                    <?php endif; ?>
                    <!-- Status dot -->
                    <span class="absolute bottom-0 right-0 h-3.5 w-3.5 rounded-full border-2 border-white
                      <?= match($statusKey) { 'active' => 'bg-green-400', 'pending' => 'bg-amber-400', 'expired' => 'bg-red-400', 'suspended' => 'bg-indigo-400', default => 'bg-gray-300' } ?>">
                    </span>
                  </div>
                  <!-- Name -->
                  <p class="text-sm font-semibold text-gray-900 group-hover:text-primary leading-tight truncate w-full">
                    <?= e($fullName !== '' ? $fullName : 'Member') ?>
                  </p>
                  <!-- Member # -->
                  <p class="text-[11px] text-gray-400 mt-0.5">#<?= e($member['member_number_display'] ?? '—') ?></p>
                  <!-- Chapter -->
                  <p class="text-xs text-gray-500 mt-2 truncate w-full" title="<?= e($chapterLabel) ?>">
                    <?= e($chapterLabel) ?><?= $chapterState ? ' · ' . e($chapterState) : '' ?>
                  </p>
                  <!-- Type badges -->
                  <div class="mt-2 flex flex-wrap justify-center gap-1">
                    <?php if ($isLifeMember): ?>
                      <span class="inline-flex items-center gap-0.5 rounded-full bg-yellow-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-yellow-800">
                        <span class="material-icons-outlined text-[9px]">star</span>Life
                      </span>
                    <?php elseif ($isAssociate): ?>
                      <span class="inline-flex rounded-full bg-purple-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-purple-700">Assoc.</span>
                    <?php endif; ?>
                    <span class="inline-flex rounded-full px-1.5 py-0.5 text-[9px] font-semibold <?= statusBadgeClasses($member['status']) ?>"><?= e($statusLabelText) ?></span>
                  </div>
                  <?php if ($isAssociate && $primaryMemberName !== ''): ?>
                    <p class="text-[10px] text-gray-400 mt-1 truncate w-full" title="Associate of <?= e($primaryMemberName) ?>">of <?= e($primaryMemberName) ?></p>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
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
