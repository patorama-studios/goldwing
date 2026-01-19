<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;

require_permission('admin.member_of_year.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/member-of-the-year');
    exit;
}

$pdo = db();
$tableReady = false;
try {
    $tableReady = (bool) $pdo->query("SHOW TABLES LIKE 'member_of_year_nominations'")->fetch();
} catch (Throwable $e) {
    $tableReady = false;
}

if (!$tableReady) {
    $_SESSION['member_of_year_flash'] = [
        'type' => 'error',
        'message' => 'Member of the Year nominations are not available yet. Run the migration first.',
    ];
    header('Location: /admin/member-of-the-year');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    $_SESSION['member_of_year_flash'] = [
        'type' => 'error',
        'message' => 'Invalid CSRF token.',
    ];
    header('Location: /admin/member-of-the-year');
    exit;
}

$action = $_POST['action'] ?? '';
$allowedStatuses = ['new', 'reviewed', 'shortlisted', 'winner'];

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));
    $adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));

    if ($id <= 0) {
        $_SESSION['member_of_year_flash'] = [
            'type' => 'error',
            'message' => 'Invalid submission selected.',
        ];
        header('Location: /admin/member-of-the-year');
        exit;
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $_SESSION['member_of_year_flash'] = [
            'type' => 'error',
            'message' => 'Invalid status selected.',
        ];
        header('Location: /admin/member-of-the-year/view.php?id=' . $id);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE member_of_year_nominations SET status = :status, admin_notes = :admin_notes WHERE id = :id');
    $stmt->execute([
        'status' => $status,
        'admin_notes' => $adminNotes,
        'id' => $id,
    ]);

    $_SESSION['member_of_year_flash'] = [
        'type' => 'success',
        'message' => 'Submission updated.',
    ];

    header('Location: /admin/member-of-the-year/view.php?id=' . $id);
    exit;
}

$_SESSION['member_of_year_flash'] = [
    'type' => 'error',
    'message' => 'Invalid action requested.',
];
header('Location: /admin/member-of-the-year');
exit;
