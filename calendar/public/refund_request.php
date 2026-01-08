<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

calendar_require_login();
$user = calendar_current_user();
$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events', 'calendar_event_tickets', 'calendar_refund_requests']);

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM calendar_events WHERE id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM calendar_event_tickets WHERE event_id = :event_id AND user_id = :user_id');
$stmt->execute(['event_id' => $eventId, 'user_id' => $user['id']]);
$tickets = $stmt->fetchAll();

if (empty($tickets)) {
    echo 'No tickets found for this event.';
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    calendar_csrf_verify();
    $reason = trim($_POST['reason'] ?? '');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    if ($reason === '') {
        $error = 'Reason is required.';
    }
    $validOrder = false;
    foreach ($tickets as $ticket) {
        if ((int) $ticket['order_id'] === $orderId) {
            $validOrder = true;
            break;
        }
    }
    if (!$validOrder) {
        $error = 'Invalid order selected.';
    }

    if ($error === '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_refund_requests WHERE order_id = :order_id AND user_id = :user_id');
        $stmt->execute(['order_id' => $orderId, 'user_id' => $user['id']]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Refund request already submitted.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO calendar_refund_requests (event_id, user_id, order_id, reason, status, created_at) VALUES (:event_id, :user_id, :order_id, :reason, "pending", NOW())');
            $stmt->execute([
                'event_id' => $eventId,
                'user_id' => $user['id'],
                'order_id' => $orderId,
                'reason' => $reason,
            ]);
            $message = 'Refund request submitted.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Refund Request</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="calendar-container">
    <h1>Refund Request</h1>
    <p class="muted">Event: <?php echo calendar_e($event['title']); ?></p>

    <?php if ($message) : ?>
        <div class="alert success"><?php echo calendar_e($message); ?></div>
    <?php endif; ?>
    <?php if ($error) : ?>
        <div class="alert danger"><?php echo calendar_e($error); ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?php echo calendar_csrf_field(); ?>
        <label>Order
            <select name="order_id" required>
                <?php foreach ($tickets as $ticket) : ?>
                    <option value="<?php echo (int) $ticket['order_id']; ?>">Order #<?php echo (int) $ticket['order_id']; ?> (qty <?php echo (int) $ticket['qty']; ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Reason
            <textarea name="reason" rows="4" required></textarea>
        </label>
        <button type="submit" class="btn">Submit Request</button>
    </form>
</div>
</body>
</html>
