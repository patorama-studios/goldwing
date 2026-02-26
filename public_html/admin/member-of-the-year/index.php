<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

require_permission('admin.member_of_year.view');

$pdo = db();

function member_of_year_table_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'member_of_year_nominations'");
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

$tableReady = member_of_year_table_ready($pdo);
$yearRows = $tableReady
    ? $pdo->query('SELECT DISTINCT submission_year FROM member_of_year_nominations ORDER BY submission_year DESC')->fetchAll()
    : [];
$yearOptions = array_filter(array_map('intval', array_column($yearRows ?: [], 'submission_year')));
$currentYear = (int) date('Y');
if (!in_array($currentYear, $yearOptions, true)) {
    $yearOptions[] = $currentYear;
}
rsort($yearOptions);

$statusOptions = ['all', 'new', 'reviewed', 'shortlisted', 'winner'];

$selectedYear = $_GET['year'] ?? (string) $currentYear;
if ($selectedYear === '') {
    $selectedYear = (string) $currentYear;
}
if ($selectedYear !== 'all') {
    $selectedYear = (string) (int) $selectedYear;
}
$selectedStatus = $_GET['status'] ?? 'all';
if (!in_array($selectedStatus, $statusOptions, true)) {
    $selectedStatus = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'year' => $selectedYear,
    'status' => $selectedStatus,
    'q' => $q,
];

$limit = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($selectedYear !== 'all') {
    $where[] = 'submission_year = :submission_year';
    $params['submission_year'] = (int) $selectedYear;
}
if ($selectedStatus !== 'all') {
    $where[] = 'status = :status';
    $params['status'] = $selectedStatus;
}
if ($q !== '') {
    $where[] = '(LOWER(nominator_first_name) LIKE :term1
        OR LOWER(nominator_last_name) LIKE :term2
        OR LOWER(CONCAT(nominator_first_name, " ", nominator_last_name)) LIKE :term3
        OR LOWER(nominator_email) LIKE :term4
        OR LOWER(nominee_first_name) LIKE :term5
        OR LOWER(nominee_last_name) LIKE :term6
        OR LOWER(CONCAT(nominee_first_name, " ", nominee_last_name)) LIKE :term7
        OR LOWER(nominee_chapter) LIKE :term8)';
    $searchTerm = '%' . strtolower($q) . '%';
    $params['term1'] = $searchTerm;
    $params['term2'] = $searchTerm;
    $params['term3'] = $searchTerm;
    $params['term4'] = $searchTerm;
    $params['term5'] = $searchTerm;
    $params['term6'] = $searchTerm;
    $params['term7'] = $searchTerm;
    $params['term8'] = $searchTerm;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalCount = 0;
$totalPages = 1;
$nominations = [];
if ($tableReady) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM member_of_year_nominations ' . $whereSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    $totalPages = (int) max(1, ceil($totalCount / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $sql = 'SELECT * FROM member_of_year_nominations ' . $whereSql . ' ORDER BY submitted_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $nominations = $stmt->fetchAll();
}

$flash = $_SESSION['member_of_year_flash'] ?? null;
unset($_SESSION['member_of_year_flash']);

function status_badge_classes(string $status): string
{
    return match ($status) {
        'new' => 'bg-blue-50 text-blue-700',
        'reviewed' => 'bg-amber-50 text-amber-700',
        'shortlisted' => 'bg-indigo-50 text-indigo-700',
        'winner' => 'bg-emerald-50 text-emerald-700',
        default => 'bg-slate-100 text-slate-700',
    };
}

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

$pageTitle = 'Member of the Year Submissions';
$activePage = 'member-of-year';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
    <main class="flex-1 overflow-y-auto bg-background-light relative">
        <?php $topbarTitle = $pageTitle;
        require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <?php if (!$tableReady): ?>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                    Member of the Year nominations are not available yet. Run the migration in
                    <code>database/migrations/2025_04_22_member_of_year.sql</code>.
                </div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div
                    class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php require __DIR__ . '/../../../app/Views/admin/member_of_year/MemberOfYearAdminList.php'; ?>
        </div>
    </main>
</div>