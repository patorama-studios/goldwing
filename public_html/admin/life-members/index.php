<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;

require_permission('admin.pages.view');

$pdo = db();
try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'life_members'")->fetch();
} catch (Throwable $e) {
    $tableReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    require_permission('admin.pages.edit');
    $flash = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    if (Csrf::verify($_POST['csrf_token'] ?? '')) {
        $action = $_POST['action'] ?? '';
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'add') {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $year = (int) ($_POST['year_awarded'] ?? 0);
            if ($name === '' || mb_strlen($name) > 150 || $year < 1900 || $year > (int) date('Y') + 1) {
                $flash = ['type' => 'error', 'message' => 'Enter a name and a valid year.'];
            } else {
                $pdo->prepare('INSERT INTO life_members (full_name, year_awarded, is_deceased, is_honorary, created_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$name, $year, empty($_POST['is_deceased']) ? 0 : 1, empty($_POST['is_honorary']) ? 0 : 1]);
                $flash = ['type' => 'success', 'message' => $name . ' added.'];
            }
        } elseif ($action === 'toggle_deceased' && $id > 0) {
            $pdo->prepare('UPDATE life_members SET is_deceased = 1 - is_deceased WHERE id = ?')->execute([$id]);
            $flash = ['type' => 'success', 'message' => 'Updated.'];
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM life_members WHERE id = ?')->execute([$id]);
            $flash = ['type' => 'success', 'message' => 'Removed.'];
        } else {
            $flash = ['type' => 'error', 'message' => 'Unknown action.'];
        }
    }
    $_SESSION['life_members_flash'] = $flash;
    header('Location: /admin/life-members/');
    exit;
}

$flash = $_SESSION['life_members_flash'] ?? null;
unset($_SESSION['life_members_flash']);
$rows = $tableReady ? $pdo->query('SELECT * FROM life_members ORDER BY is_honorary, year_awarded, id')->fetchAll() : [];
$canEdit = current_admin_can('admin.pages.edit', current_user());

$pageTitle = 'Life Members';
$activePage = 'life-members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
    <main class="flex-1 overflow-y-auto bg-background-light relative">
        <?php $topbarTitle = $pageTitle;
        require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <p class="text-sm text-gray-600">
                The honour roll shown to members at <a href="/member/index.php?page=life-members" class="text-primary-strong underline">Member portal → Life Members</a>,
                listed by year awarded. Honorary members are shown in their own section underneath.
            </p>
            <?php if (!$tableReady): ?>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                    The life members table isn't set up yet. Run <a href="/admin/run-migration.php" class="underline">/admin/run-migration.php</a> (Migration 053).
                </div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if ($tableReady && $canEdit): ?>
                <form method="post" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm grid grid-cols-1 sm:grid-cols-6 gap-4 items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="add">
                    <label class="text-sm font-medium text-gray-700 sm:col-span-3">Full name
                        <input type="text" name="full_name" maxlength="150" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                    </label>
                    <label class="text-sm font-medium text-gray-700 sm:col-span-1">Year
                        <input type="number" name="year_awarded" min="1900" max="<?= (int) date('Y') + 1 ?>" value="<?= (int) date('Y') ?>" required class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
                    </label>
                    <div class="sm:col-span-2 flex flex-col gap-1 text-sm text-gray-700">
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_deceased" value="1"> Deceased</label>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_honorary" value="1"> Honorary member</label>
                    </div>
                    <div class="sm:col-span-6">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900 transition hover:bg-primary/80">Add to roll</button>
                    </div>
                </form>
            <?php endif; ?>

            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500 border-b">
                        <tr>
                            <th class="py-3 px-4">Name</th>
                            <th class="py-3 px-4">Year</th>
                            <th class="py-3 px-4">Status</th>
                            <?php if ($canEdit): ?><th class="py-3 px-4"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr class="border-b border-gray-50">
                                <td class="py-3 px-4 font-medium text-gray-900"><?= e($r['full_name']) ?></td>
                                <td class="py-3 px-4 text-gray-600"><?= (int) $r['year_awarded'] ?></td>
                                <td class="py-3 px-4 text-gray-600">
                                    <?= $r['is_honorary'] ? 'Honorary' : 'Life member' ?><?= $r['is_deceased'] ? ' · Deceased' : '' ?>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button name="action" value="toggle_deceased" class="rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700">
                                                <?= $r['is_deceased'] ? 'Mark as living' : 'Mark as deceased' ?>
                                            </button>
                                            <button name="action" value="delete" onclick="return confirm('Remove <?= e(addslashes($r['full_name'])) ?> from the roll?')" class="rounded-full border border-red-200 px-3 py-1 text-xs font-semibold text-red-700">Remove</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="4" class="py-6 px-4 text-center text-gray-400">No entries yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../../../app/Views/partials/help_button.php'; ?>
