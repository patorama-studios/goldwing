<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

calendar_require_role(['SUPER_ADMIN', 'ADMIN', 'CHAPTER_LEADER']);
$pdo = calendar_db();
$user = calendar_current_user();
calendar_require_tables($pdo, ['calendar_refund_requests', 'calendar_orders', 'calendar_events']);

$eventId = (int) ($_GET['event_id'] ?? 0);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    calendar_csrf_verify();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    $stmt = $pdo->prepare('SELECT r.*, o.stripe_payment_intent_id FROM calendar_refund_requests r JOIN calendar_orders o ON o.id = r.order_id WHERE r.id = :id');
    $stmt->execute(['id' => $requestId]);
    $refund = $stmt->fetch();

    if (!$refund) {
        $error = 'Refund request not found.';
    } else {
        if ($action === 'approve') {
            $status = 'approved';
            $stripeSecret = calendar_config('stripe.secret_key');
            if ($stripeSecret && !empty($refund['stripe_payment_intent_id'])) {
                $payload = [
                    'payment_intent' => $refund['stripe_payment_intent_id'],
                ];
                $ch = curl_init('https://api.stripe.com/v1/refunds');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, $stripeSecret . ':');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode < 200 || $httpCode >= 300) {
                    $status = 'manual_required';
                }
            } else {
                $status = 'manual_required';
            }

            $stmt = $pdo->prepare('UPDATE calendar_refund_requests SET status = :status, admin_id = :admin_id, admin_notes = :admin_notes, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'admin_id' => $user['id'],
                'admin_notes' => $adminNotes,
                'id' => $requestId,
            ]);
            $message = $status === 'approved' ? 'Refund approved.' : 'Refund marked for manual processing.';
        } elseif ($action === 'decline') {
            $stmt = $pdo->prepare('UPDATE calendar_refund_requests SET status = "declined", admin_id = :admin_id, admin_notes = :admin_notes, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'admin_id' => $user['id'],
                'admin_notes' => $adminNotes,
                'id' => $requestId,
            ]);
            $message = 'Refund declined.';
        }
    }
}

$sql = 'SELECT r.*, u.email, e.title FROM calendar_refund_requests r JOIN users u ON u.id = r.user_id JOIN calendar_events e ON e.id = r.event_id';
$params = [];
if ($eventId) {
    $sql .= ' WHERE r.event_id = :event_id';
    $params['event_id'] = $eventId;
}
$sql .= ' ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Refund Requests</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="calendar-container">
    <h1>Refund Requests</h1>

    <?php if ($message) : ?>
        <div class="alert success"><?php echo calendar_e($message); ?></div>
    <?php endif; ?>
    <?php if ($error) : ?>
        <div class="alert danger"><?php echo calendar_e($error); ?></div>
    <?php endif; ?>

    <?php if (empty($requests)) : ?>
        <p class="muted">No refund requests.</p>
    <?php else : ?>
        <table class="table">
            <thead>
                <tr><th>Event</th><th>Member</th><th>Reason</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request) : ?>
                    <tr>
                        <td><?php echo calendar_e($request['title']); ?></td>
                        <td><?php echo calendar_e($request['email']); ?></td>
                        <td><?php echo calendar_e($request['reason']); ?></td>
                        <td><?php echo calendar_e($request['status']); ?></td>
                        <td>
                            <?php if ($request['status'] === 'pending') : ?>
                                <form method="post" class="inline-form">
                                    <?php echo calendar_csrf_field(); ?>
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                    <input type="text" name="admin_notes" placeholder="Notes">
                                    <button type="submit" name="action" value="approve" class="btn">Approve</button>
                                    <button type="submit" name="action" value="decline" class="btn danger">Decline</button>
                                </form>
                            <?php else : ?>
                                <span class="muted">Reviewed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
