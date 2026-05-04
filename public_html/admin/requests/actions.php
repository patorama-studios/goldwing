<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\Csrf;
use App\Services\NotificationService;
use App\Services\PendingRequestsService;
use App\Services\EmailService;

require_permission('admin.requests.action');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/requests/');
    exit;
}

$user = current_user();
$type = trim((string) ($_POST['type'] ?? ''));
$id = (int) ($_POST['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$redirect = '/admin/requests/view.php?type=' . urlencode($type) . '&id=' . $id;

if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    $_SESSION['requests_flash'] = ['type' => 'error', 'message' => 'Invalid CSRF token. Please try again.'];
    header('Location: ' . $redirect);
    exit;
}

if (!in_array($action, ['approve', 'reject', 'feedback', 'archive'], true)) {
    $_SESSION['requests_flash'] = ['type' => 'error', 'message' => 'Unknown action.'];
    header('Location: ' . $redirect);
    exit;
}

if (in_array($action, ['reject', 'feedback'], true) && $message === '') {
    $_SESSION['requests_flash'] = ['type' => 'error', 'message' => 'A message is required to deny or send feedback.'];
    header('Location: ' . $redirect);
    exit;
}

$result = PendingRequestsService::applyAction($type, $id, $action, $message, (int) ($user['id'] ?? 0));

if (!$result['ok']) {
    $_SESSION['requests_flash'] = ['type' => 'error', 'message' => $result['message'] ?? 'Action failed.'];
    header('Location: ' . $redirect);
    exit;
}

// Save a ticket_message entry for every action that carries a message or is a status change.
$adminName = $user['name'] ?? ('Admin #' . ($user['id'] ?? 0));
$threadMessage = $message;
if ($threadMessage === '' && in_array($action, ['approve', 'reject', 'archive'], true)) {
    // Record status-change actions even without a custom message so the thread shows history.
    $threadMessage = match ($action) {
        'approve' => '✔ Request approved.',
        'reject'  => '✗ Request denied.',
        'archive' => '📦 Request archived.',
    };
}
PendingRequestsService::addMessage($type, $id, 'admin', (int) ($user['id'] ?? 0), $adminName, $threadMessage);

// Dispatch email notification to submitter (skip for archive — housekeeping action).
$submitterEmail = $result['submitter_email'] ?? '';
if ($submitterEmail && $action !== 'archive') {
    $notificationKey = match ($action) {
        'approve'  => 'request_approved',
        'reject'   => 'request_denied',
        'feedback' => 'request_feedback',
        default    => null,
    };
    if ($notificationKey) {
        NotificationService::dispatch($notificationKey, [
            'primary_email'  => $submitterEmail,
            'submitter_name' => $result['submitter_name'] ?? 'Member',
            'request_type'   => $result['type_label'] ?? $type,
            'request_title'  => $result['title'] ?? '',
            'message'        => $message !== '' ? nl2br(NotificationService::escape($message)) : '',
            'site_name'      => 'Australian Goldwing Association',
            'portal_url'     => '/member/notifications.php?type=' . urlencode($type) . '&id=' . $id,
        ]);
    }
}

$_SESSION['requests_flash'] = [
    'type'    => 'success',
    'message' => match ($action) {
        'approve'  => 'Request approved' . ($submitterEmail ? ' and submitter notified.' : '.'),
        'reject'   => 'Request denied' . ($submitterEmail ? ' and submitter notified.' : '.'),
        'feedback' => 'Feedback sent' . ($submitterEmail ? ' to submitter.' : '.'),
        'archive'  => 'Request archived.',
    },
];

// Feedback stays on the detail page; everything else goes back to the hub.
header('Location: ' . ($action === 'feedback' ? $redirect : '/admin/requests/'));
exit;
