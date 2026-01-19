<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/mailer.php';

$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events', 'calendar_event_rsvps', 'calendar_event_tickets']);
$hasChapterId = calendar_events_has_chapter_id($pdo);
$slug = $_GET['slug'] ?? '';
if ($slug === '') {
    http_response_code(404);
    echo 'Event not found';
    exit;
}
$embed = ($_GET['embed'] ?? '') === '1';

$sql = 'SELECT e.*, m.path AS thumbnail_url, m.title AS thumbnail_name, '
    . ($hasChapterId ? 'c.name AS chapter_name' : 'NULL AS chapter_name')
    . ' FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id ';
if ($hasChapterId) {
    $sql .= 'LEFT JOIN chapters c ON c.id = e.chapter_id ';
}
$sql .= 'WHERE e.slug = :slug LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute(['slug' => $slug]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$user = calendar_current_user();
$message = '';
$error = '';

$now = new DateTime('now', new DateTimeZone($event['timezone']));
$salesCloseAt = $event['sales_close_at'] ? new DateTime($event['sales_close_at'], new DateTimeZone($event['timezone'])) : null;
$salesClosed = $salesCloseAt ? ($now >= $salesCloseAt) : false;
$isCancelled = $event['status'] === 'cancelled';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    calendar_csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'rsvp') {
        calendar_require_login();
        if ($isCancelled || !$event['rsvp_enabled']) {
            $error = 'RSVPs are not available for this event.';
        } elseif ($salesClosed) {
            $error = 'RSVPs are closed for this event.';
        } else {
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id = :user_id');
            $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'You have already RSVP\'d for this event.';
            } else {
                $capacity = $event['capacity'] ? (int) $event['capacity'] : null;
                if ($capacity !== null) {
                    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status = "going"');
                    $stmt->execute(['event_id' => $event['id']]);
                    $rsvpCount = (int) $stmt->fetchColumn();
                    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
                    $stmt->execute(['event_id' => $event['id']]);
                    $ticketCount = (int) $stmt->fetchColumn();
                    if ($rsvpCount + $ticketCount + $qty > $capacity) {
                        $error = 'Not enough remaining capacity for this RSVP.';
                    }
                }
                if ($error === '') {
                    $stmt = $pdo->prepare('INSERT INTO calendar_event_rsvps (event_id, user_id, qty, notes, status, created_at) VALUES (:event_id, :user_id, :qty, :notes, "going", NOW())');
                    $stmt->execute([
                        'event_id' => $event['id'],
                        'user_id' => $user['id'],
                        'qty' => $qty,
                        'notes' => $notes,
                    ]);
                    $message = 'RSVP confirmed.';
                }
            }
        }
    }

    if ($action === 'buy_ticket') {
        calendar_require_login();
        if ($isCancelled || !$event['is_paid']) {
            $error = 'Tickets are not available for this event.';
        } elseif ($salesClosed) {
            $error = 'Ticket sales are closed for this event.';
        } else {
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $capacity = $event['capacity'] ? (int) $event['capacity'] : null;
            if ($capacity !== null) {
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status = "going"');
                $stmt->execute(['event_id' => $event['id']]);
                $rsvpCount = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
                $stmt->execute(['event_id' => $event['id']]);
                $ticketCount = (int) $stmt->fetchColumn();
                if ($rsvpCount + $ticketCount + $qty > $capacity) {
                    $error = 'Not enough remaining capacity for tickets.';
                }
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
            $stmt->execute(['id' => (int) $event['ticket_product_id']]);
            $product = $stmt->fetch();
            if (!$product) {
                $error = 'Ticket product not found.';
            } else {
                $successUrl = calendar_base_url('event_view.php?slug=' . urlencode($event['slug']) . '&success=1');
                $cancelUrl = calendar_base_url('event_view.php?slug=' . urlencode($event['slug']) . '&cancel=1');
                $payload = [
                    'mode' => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'customer_email' => $user['email'],
                    'line_items[0][price_data][currency]' => $product['currency'],
                    'line_items[0][price_data][unit_amount]' => (int) $product['price_cents'],
                    'line_items[0][price_data][product_data][name]' => $product['name'],
                    'line_items[0][quantity]' => $qty,
                    'metadata[event_id]' => $event['id'],
                    'metadata[user_id]' => $user['id'],
                    'metadata[qty]' => $qty,
                ];

                $secretKey = calendar_config('stripe.secret_key');
                if (!$secretKey) {
                    $error = 'Stripe is not configured.';
                } else {
                    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode >= 200 && $httpCode < 300) {
                        $session = json_decode($response, true);
                        if (!empty($session['url'])) {
                            header('Location: ' . $session['url']);
                            exit;
                        }
                        $error = 'Stripe checkout could not be created.';
                    } else {
                        $error = 'Stripe error: ' . $response;
                    }
                }
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status = "going"');
$stmt->execute(['event_id' => $event['id']]);
$rsvpCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
$stmt->execute(['event_id' => $event['id']]);
$ticketCount = (int) $stmt->fetchColumn();

$userRsvp = null;
$userTickets = null;
if ($user) {
    $stmt = $pdo->prepare('SELECT * FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id = :user_id');
    $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
    $userRsvp = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT * FROM calendar_event_tickets WHERE event_id = :event_id AND user_id = :user_id');
    $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
    $userTickets = $stmt->fetchAll();
}

$capacityText = '';
if ($event['capacity']) {
    $capacityText = ($rsvpCount + $ticketCount) . ' of ' . (int) $event['capacity'] . ' spots filled';
}

$locationText = $event['event_type'] === 'online' ? ($event['online_url'] ?? '') : ($event['map_url'] ?? '');
$backUrl = 'events_public.php';
$formAction = 'event_view.php?slug=' . urlencode($event['slug']) . ($embed ? '&embed=1' : '');
?>
<?php
ob_start();
?>
<div class="calendar-container">
    <a class="link" href="<?php echo calendar_e($backUrl); ?>" data-calendar-back>&larr; Back to events</a>

    <?php if ($isCancelled) : ?>
        <div class="alert danger">This event has been cancelled. <?php echo calendar_e($event['cancellation_message'] ?? ''); ?></div>
    <?php endif; ?>

    <?php if ($message) : ?>
        <div class="alert success"><?php echo calendar_e($message); ?></div>
    <?php endif; ?>
    <?php if ($error) : ?>
        <div class="alert danger"><?php echo calendar_e($error); ?></div>
    <?php endif; ?>

    <div class="event-detail">
        <?php if (!empty($event['thumbnail_url'])) : ?>
            <img class="event-thumb" src="<?php echo calendar_e($event['thumbnail_url']); ?>" alt="<?php echo calendar_e($event['thumbnail_name'] ?? $event['title']); ?>">
        <?php endif; ?>
        <div>
            <h1><?php echo calendar_e($event['title']); ?></h1>
            <div class="event-badges">
                <?php echo calendar_render_badge(calendar_human_scope($event['scope'])); ?>
                <?php if (!empty($event['chapter_name'])) : ?>
                    <?php echo calendar_render_badge($event['chapter_name']); ?>
                <?php endif; ?>
                <?php echo calendar_render_badge(calendar_human_type($event['event_type'])); ?>
                <?php echo calendar_render_badge(calendar_human_paid((int) $event['is_paid'])); ?>
                <span class="badge">TZ: <?php echo calendar_e($event['timezone']); ?></span>
            </div>
            <p class="event-meta">
                <?php echo calendar_format_dt($event['start_at'], $event['timezone']); ?> - <?php echo calendar_format_dt($event['end_at'], $event['timezone']); ?>
            </p>
            <?php if ($capacityText) : ?>
                <p class="muted"><?php echo calendar_e($capacityText); ?></p>
            <?php endif; ?>
            <?php if (!empty($event['meeting_point'])) : ?>
                <p><strong>Meeting point:</strong> <?php echo calendar_e($event['meeting_point']); ?></p>
            <?php endif; ?>
            <?php if (!empty($event['destination'])) : ?>
                <p><strong>Destination:</strong> <?php echo calendar_e($event['destination']); ?></p>
            <?php endif; ?>
            <?php if ($locationText) : ?>
                <p><a href="<?php echo calendar_e($locationText); ?>" target="_blank" rel="noopener">Open location</a></p>
            <?php endif; ?>
        </div>
    </div>

    <section class="event-description">
        <?php echo nl2br(calendar_e($event['description'])); ?>
    </section>

    <section class="event-actions">
        <a class="btn secondary" href="ics.php?event_id=<?php echo (int) $event['id']; ?>">Add to calendar (.ics)</a>

        <?php if ($event['rsvp_enabled'] && !$salesClosed && !$isCancelled) : ?>
            <?php if ($user) : ?>
                <?php if ($userRsvp) : ?>
                    <div class="alert info">You are RSVP'd for this event.</div>
                <?php else : ?>
                    <form method="post" class="inline-form" action="<?php echo calendar_e($formAction); ?>" data-calendar-embed-form="1">
                        <?php echo calendar_csrf_field(); ?>
                        <input type="hidden" name="action" value="rsvp">
                        <label>
                            Qty
                            <input type="number" name="qty" min="1" value="1">
                        </label>
                        <label>
                            Notes
                            <input type="text" name="notes" placeholder="Optional">
                        </label>
                        <button type="submit" class="btn">RSVP</button>
                    </form>
                <?php endif; ?>
            <?php else : ?>
                <p class="muted">Please <a href="<?php echo calendar_e(calendar_config('login_url', '/login.php')); ?>">log in</a> to RSVP.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($event['is_paid'] && !$salesClosed && !$isCancelled) : ?>
            <?php if ($user) : ?>
                <form method="post" class="inline-form" action="<?php echo calendar_e($formAction); ?>" data-calendar-embed-form="1">
                    <?php echo calendar_csrf_field(); ?>
                    <input type="hidden" name="action" value="buy_ticket">
                    <label>
                        Qty
                        <input type="number" name="qty" min="1" value="1">
                    </label>
                    <button type="submit" class="btn">Buy Ticket</button>
                </form>
            <?php else : ?>
                <p class="muted">Please <a href="<?php echo calendar_e(calendar_config('login_url', '/login.php')); ?>">log in</a> to buy tickets.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($salesClosed) : ?>
            <div class="alert warning">Sales/RSVPs are closed for this event.</div>
        <?php endif; ?>
    </section>

    <?php if ($userTickets && !empty($userTickets)) : ?>
        <section class="event-actions">
            <h2>Your tickets</h2>
            <ul>
                <?php foreach ($userTickets as $ticket) : ?>
                    <li>
                        Ticket code: <?php echo calendar_e($ticket['ticket_code']); ?>
                        <?php if (!empty($ticket['ticket_pdf_url'])) : ?>
                            - <a href="<?php echo calendar_e($ticket['ticket_pdf_url']); ?>" target="_blank" rel="noopener">Download</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="btn secondary" href="refund_request.php?event_id=<?php echo (int) $event['id']; ?>">Request refund</a>
        </section>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
?>
<?php if ($embed) : ?>
<div class="calendar-event-embed">
    <style>
        .calendar-event-embed {
            font-family: "Trebuchet MS", "Lucida Sans Unicode", "Lucida Grande", sans-serif;
            color: #1c2b39;
        }
        .calendar-event-embed .calendar-container {
            background: #ffffff;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        .calendar-event-embed h1,
        .calendar-event-embed h2 {
            margin-top: 0;
        }
        .calendar-event-embed .link {
            display: inline-block;
            margin-bottom: 16px;
            color: #2f5f8d;
            text-decoration: none;
        }
        .calendar-event-embed .btn {
            background: #2f5f8d;
            color: #fff;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .calendar-event-embed .btn.secondary {
            background: #fff;
            border: 1px solid #2f5f8d;
            color: #2f5f8d;
        }
        .calendar-event-embed .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .calendar-event-embed .alert.success {
            background: #e7f5ee;
            color: #146c43;
        }
        .calendar-event-embed .alert.danger {
            background: #fdecea;
            color: #b42318;
        }
        .calendar-event-embed .alert.warning {
            background: #fff3cd;
            color: #856404;
        }
        .calendar-event-embed .alert.info {
            background: #e8f4fd;
            color: #0f5132;
        }
        .calendar-event-embed .muted {
            color: #667085;
            font-size: 14px;
        }
        .calendar-event-embed .inline-form {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }
        .calendar-event-embed .inline-form input {
            padding: 8px 10px;
            border: 1px solid #d9dee6;
            border-radius: 6px;
            font-size: 14px;
        }
        .calendar-event-embed .badge {
            background: #f5f7fa;
            border: 1px solid #d9dee6;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-right: 6px;
        }
        .calendar-event-embed .event-detail {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .calendar-event-embed .event-thumb {
            width: 260px;
            height: 180px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #d9dee6;
        }
        .calendar-event-embed .event-badges {
            margin: 8px 0;
        }
        .calendar-event-embed .event-description {
            margin-top: 16px;
            line-height: 1.6;
        }
    </style>
    <?php echo $content; ?>
</div>
<?php else : ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo calendar_e($event['title']); ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php echo $content; ?>
</body>
</html>
<?php endif; ?>
