<?php
// JSON save endpoint for the Committee & Leadership Role settings page.
// Actions:
//   assign       — add role_id to member_id
//   unassign     — remove role_id from member_id
//   set_privacy  — toggle members.committee_private for member_id
//
// All actions are CSRF-protected and gated by admin.members.view (same
// permission that gates the settings page itself).

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\CommitteeService;
use App\Services\Csrf;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Force JSON content negotiation so require_permission returns a JSON 401/403
// rather than a 302 to /login.php (which fetch() would follow into HTML).
$_SERVER['HTTP_ACCEPT'] = 'application/json';

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

require_permission('admin.members.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$memberId = (int) ($_POST['member_id'] ?? 0);
$roleId   = (int) ($_POST['role_id'] ?? 0);

if ($memberId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_member']);
    exit;
}

try {
    $pdo = db();

    if ($action === 'assign' || $action === 'unassign') {
        if ($roleId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'missing_role']);
            exit;
        }
        $current = CommitteeService::roleIdsForMember($memberId);
        $set = array_fill_keys($current, true);
        if ($action === 'assign') {
            $set[$roleId] = true;
        } else {
            unset($set[$roleId]);
        }
        CommitteeService::syncAssignments($memberId, array_keys($set));
    } elseif ($action === 'set_privacy') {
        $private = !empty($_POST['private']) ? 1 : 0;
        $upd = $pdo->prepare('UPDATE members SET committee_private = :p WHERE id = :m');
        $upd->execute([':p' => $private, ':m' => $memberId]);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'unknown_action']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

echo json_encode(['ok' => true]);
