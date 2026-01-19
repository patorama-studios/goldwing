<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Database;

require_permission('admin.logs.view');

$filters = [
    'user_id' => isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : null,
    'action' => trim((string) ($_GET['action'] ?? '')),
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'target_type' => trim((string) ($_GET['target_type'] ?? '')),
    'start' => trim((string) ($_GET['start'] ?? '')),
    'end' => trim((string) ($_GET['end'] ?? '')),
];

$pdo = Database::connection();
$sql = 'SELECT al.*, u.email AS user_email, u.name AS user_name FROM activity_log al LEFT JOIN users u ON u.id = al.actor_id WHERE 1=1';
$params = [];
if ($filters['user_id']) {
    $sql .= ' AND al.actor_id = :user_id';
    $params['user_id'] = $filters['user_id'];
}
if ($filters['action'] !== '') {
    $sql .= ' AND al.action LIKE :action';
    $params['action'] = '%' . $filters['action'] . '%';
}
if ($filters['ip'] !== '') {
    $sql .= ' AND al.ip_address = :ip';
    $params['ip'] = $filters['ip'];
}
if ($filters['target_type'] !== '') {
    $sql .= ' AND al.target_type = :target_type';
    $params['target_type'] = $filters['target_type'];
}
if ($filters['start'] !== '') {
    $sql .= ' AND al.created_at >= :start';
    $params['start'] = $filters['start'] . ' 00:00:00';
}
if ($filters['end'] !== '') {
    $sql .= ' AND al.created_at <= :end';
    $params['end'] = $filters['end'] . ' 23:59:59';
}
$sql .= ' ORDER BY al.created_at DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $type = $key === 'user_id' ? \PDO::PARAM_INT : \PDO::PARAM_STR;
    $stmt->bindValue(':' . $key, $value, $type);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Security Activity Log';
$activePage = 'security-log';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Security Activity Log'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm p-6">
        <form method="get" class="grid gap-4 md:grid-cols-3">
          <label class="text-sm text-gray-700">User ID
            <input type="number" name="user_id" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" value="<?= e((string) ($filters['user_id'] ?? '')) ?>">
          </label>
          <label class="text-sm text-gray-700">Action contains
            <input type="text" name="action" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" value="<?= e($filters['action']) ?>">
          </label>
          <label class="text-sm text-gray-700">IP address
            <input type="text" name="ip" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" value="<?= e($filters['ip']) ?>">
          </label>
          <label class="text-sm text-gray-700">Target type
            <input type="text" name="target_type" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" value="<?= e($filters['target_type']) ?>">
          </label>
          <label class="text-sm text-gray-700">Start date
            <input type="date" name="start" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" value="<?= e($filters['start']) ?>">
          </label>
          <label class="text-sm text-gray-700">End date
            <input type="date" name="end" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" value="<?= e($filters['end']) ?>">
          </label>
          <div class="md:col-span-3 flex items-center gap-2">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">Filter</button>
            <a href="/admin/security/activity_log.php" class="text-sm text-slate-500">Clear</a>
          </div>
        </form>
      </section>

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-500 border-b">
              <tr>
                <th class="py-2 px-3">Time</th>
                <th class="py-2 px-3">User</th>
                <th class="py-2 px-3">Action</th>
                <th class="py-2 px-3">IP</th>
                <th class="py-2 px-3">Target</th>
                <th class="py-2 px-3">Metadata</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td class="py-3 px-3 text-gray-600"><?= e($row['created_at']) ?></td>
                  <td class="py-3 px-3 text-gray-600"><?= e($row['user_name'] ?? '—') ?><br><span class="text-xs text-gray-500"><?= e($row['user_email'] ?? '') ?></span></td>
                  <td class="py-3 px-3 text-gray-700"><?= e($row['action']) ?></td>
                  <td class="py-3 px-3 text-gray-600"><?= e($row['ip_address'] ?? '—') ?></td>
                  <td class="py-3 px-3 text-gray-600"><?= e(($row['target_type'] ?? '') . ($row['target_id'] ? ':' . $row['target_id'] : '')) ?></td>
                  <td class="py-3 px-3 text-xs text-gray-500"><?= e($row['metadata'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr>
                  <td colspan="6" class="py-4 text-center text-gray-500">No activity found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
