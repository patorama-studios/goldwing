<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /member/index.php');
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
}

$impersonation = impersonation_context();
if (!$impersonation || empty($impersonation['admin_user'])) {
    http_response_code(403);
    echo 'Not impersonating.';
    exit;
}

$adminUser = $impersonation['admin_user'];
$memberId = (int) ($impersonation['member_id'] ?? 0);
$memberUserId = (int) ($impersonation['member_user_id'] ?? 0);
$startedAt = (int) ($impersonation['started_at'] ?? 0);

$_SESSION['user'] = $adminUser;
unset($_SESSION['impersonation']);

if (!empty($adminUser['id'])) {
    $metadata = ['member_user_id' => $memberUserId];
    if ($startedAt > 0) {
        $metadata['duration_seconds'] = max(0, time() - $startedAt);
    }
    ActivityLogger::log('admin', (int) $adminUser['id'], $memberId ?: null, 'impersonation.ended', $metadata);
}

$returnMemberId = (int) ($impersonation['return_member_id'] ?? $memberId);
$returnTab = (string) ($impersonation['return_tab'] ?? 'overview');
if ($returnMemberId > 0) {
    header('Location: /admin/members/view.php?' . http_build_query(['id' => $returnMemberId, 'tab' => $returnTab]));
    exit;
}

header('Location: /admin/members');
exit;
