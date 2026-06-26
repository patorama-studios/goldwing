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
    . ($hasChapterId ? calendar_chapter_name_sql($pdo) . ' AS chapter_name' : 'NULL AS chapter_name')
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
        $rsvpStatus = $_POST['rsvp_status'] ?? '';
        $validStatuses = ['going', 'maybe', 'not_going'];
        if ($isCancelled || !$event['rsvp_enabled']) {
            $error = 'RSVPs are not available for this event.';
        } elseif ($salesClosed) {
            $error = 'RSVPs are closed for this event.';
        } elseif ($rsvpStatus === 'clear') {
            // Member is withdrawing their response entirely (back to blank).
            $stmt = $pdo->prepare('DELETE FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id = :user_id');
            $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
            $message = 'Your response has been cleared.';
        } elseif (!in_array($rsvpStatus, $validStatuses, true)) {
            $error = 'Please choose a response.';
        } else {
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $notes = trim($_POST['notes'] ?? '');
            // Attending and Maybe both reserve a spot; Not attending never does.
            $consumesSpot = in_array($rsvpStatus, ['going', 'maybe'], true);
            $capacity = $event['capacity'] ? (int) $event['capacity'] : null;
            if ($consumesSpot && $capacity !== null) {
                // Count spots already taken by OTHER members (going + maybe) plus
                // paid tickets, so changing our own qty/status never double-counts.
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id <> :user_id AND status IN ("going","maybe")');
                $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
                $othersCount = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
                $stmt->execute(['event_id' => $event['id']]);
                $ticketCount = (int) $stmt->fetchColumn();
                if ($othersCount + $ticketCount + $qty > $capacity) {
                    $error = 'Not enough remaining capacity for this response.';
                }
            }
            if ($error === '') {
                $stmt = $pdo->prepare('INSERT INTO calendar_event_rsvps (event_id, user_id, qty, notes, status, created_at) VALUES (:event_id, :user_id, :qty, :notes, :status, NOW()) ON DUPLICATE KEY UPDATE qty = VALUES(qty), notes = VALUES(notes), status = VALUES(status)');
                $stmt->execute([
                    'event_id' => $event['id'],
                    'user_id' => $user['id'],
                    'qty' => $qty,
                    'notes' => $notes,
                    'status' => $rsvpStatus,
                ]);
                $statusMessages = ['going' => 'Attending', 'maybe' => 'Maybe', 'not_going' => 'Not attending'];
                $message = 'Your response has been saved: ' . $statusMessages[$rsvpStatus] . '.';
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
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status IN ("going","maybe")');
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

$stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status IN ("going","maybe")');
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
$backUrl = '/calendar/events_public.php';
$formAction = '/calendar/event_view.php?slug=' . urlencode($event['slug']) . ($embed ? '&embed=1' : '');
?>
<?php
ob_start();
?>
<style>
    .rsvp-box { margin-top: 8px; }
    .rsvp-current { margin: 0 0 12px; font-size: 15px; }
    .rsvp-form { display: grid; gap: 12px; }
    .rsvp-fields { display: flex; gap: 12px; flex-wrap: wrap; }
    .rsvp-fields label { display: grid; gap: 4px; font-size: 13px; color: #475569; }
    .rsvp-fields input { padding: 8px 10px; border: 1px solid #d9dee6; border-radius: 6px; font-size: 14px; }
    .rsvp-choices { display: flex; gap: 8px; flex-wrap: wrap; }
    .rsvp-choice { background: #fff; border: 1px solid #2f7d32; color: #2f7d32; padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 600; }
    .rsvp-choice:hover { background: #eef7ec; }
    .rsvp-choice.is-active { background: #2f7d32; color: #fff; }
    .rsvp-choice.is-active:hover { background: #25642a; }
    .rsvp-clear { background: none; border: none; color: #b42318; cursor: pointer; font-size: 13px; text-decoration: underline; padding: 0; justify-self: start; }
</style>
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
        <?php echo calendar_render_description($event['description']); ?>
    </section>

    <section class="event-actions">
        <a class="btn secondary" href="/calendar/ics.php?event_id=<?php echo (int) $event['id']; ?>">Add to calendar (.ics)</a>

        <?php if ($event['rsvp_enabled'] && !$salesClosed && !$isCancelled) : ?>
            <?php if ($user) : ?>
                <?php
                    $currentStatus = $userRsvp['status'] ?? '';
                    $currentQty = isset($userRsvp['qty']) ? max(1, (int) $userRsvp['qty']) : 1;
                    $currentNotes = $userRsvp['notes'] ?? '';
                    $statusLabels = ['going' => 'Attending', 'maybe' => 'Maybe', 'not_going' => 'Not attending'];
                ?>
                <div class="rsvp-box">
                    <?php if ($currentStatus !== '') : ?>
                        <p class="rsvp-current">Your response: <strong><?php echo calendar_e($statusLabels[$currentStatus] ?? 'Saved'); ?></strong>
                            <span class="muted">— change it or clear it any time.</span></p>
                    <?php else : ?>
                        <p class="rsvp-current">Are you coming along? Let the organiser know.</p>
                    <?php endif; ?>
                    <form method="post" class="rsvp-form" action="<?php echo calendar_e($formAction); ?>" data-calendar-embed-form="1">
                        <?php echo calendar_csrf_field(); ?>
                        <input type="hidden" name="action" value="rsvp">
                        <div class="rsvp-fields">
                            <label>
                                Number coming
                                <input type="number" name="qty" min="1" value="<?php echo (int) $currentQty; ?>">
                            </label>
                            <label>
                                Notes
                                <input type="text" name="notes" value="<?php echo calendar_e($currentNotes); ?>" placeholder="Optional">
                            </label>
                        </div>
                        <div class="rsvp-choices">
                            <button type="submit" name="rsvp_status" value="going" class="rsvp-choice<?php echo $currentStatus === 'going' ? ' is-active' : ''; ?>">Attending</button>
                            <button type="submit" name="rsvp_status" value="maybe" class="rsvp-choice<?php echo $currentStatus === 'maybe' ? ' is-active' : ''; ?>">Maybe</button>
                            <button type="submit" name="rsvp_status" value="not_going" class="rsvp-choice<?php echo $currentStatus === 'not_going' ? ' is-active' : ''; ?>">Not attending</button>
                        </div>
                        <?php if ($currentStatus !== '') : ?>
                            <button type="submit" name="rsvp_status" value="clear" class="rsvp-clear">Clear my response</button>
                        <?php endif; ?>
                    </form>
                </div>
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
            <a class="btn secondary" href="/calendar/refund_request.php?event_id=<?php echo (int) $event['id']; ?>">Request refund</a>
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
            font-family: "Manrope", "Inter", -apple-system, sans-serif;
            color: #1c1a17;
        }
        .calendar-event-embed .calendar-container {
            background: #ffffff;
            padding: 0 0 20px;
            border-radius: 20px;
            border: none;
            box-shadow: none;
        }
        .calendar-event-embed h1 {
            font-size: 22px;
            line-height: 1.2;
            margin: 0 0 8px;
        }
        .calendar-event-embed h2 {
            font-size: 17px;
            margin: 0 0 8px;
        }
        .calendar-event-embed .link {
            display: inline-block;
            margin: 14px 20px 4px;
            color: #2f7d32;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .calendar-event-embed .link:hover {
            color: #25642a;
        }
        .calendar-event-embed .btn {
            background: #2f7d32;
            color: #fff;
            padding: 11px 18px;
            border: none;
            border-radius: 11px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 600;
        }
        .calendar-event-embed .btn:hover {
            background: #25642a;
        }
        .calendar-event-embed .btn.secondary {
            background: #fff;
            border: 1px solid #d8d2c2;
            color: #1c1a17;
        }
        .calendar-event-embed .btn.secondary:hover {
            background: #f4f1e8;
        }
        .calendar-event-embed .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin: 0 20px 14px;
        }
        .calendar-event-embed .alert.success {
            background: #eaf3e6;
            color: #1f5b22;
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
            background: #eef4ea;
            color: #1f5b22;
        }
        .calendar-event-embed .muted {
            color: #5a5a55;
            font-size: 14px;
        }
        .calendar-event-embed .event-meta {
            font-size: 15px;
            font-weight: 600;
            color: #1c1a17;
            margin: 10px 0;
        }
        .calendar-event-embed .inline-form {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }
        .calendar-event-embed .inline-form input {
            padding: 8px 10px;
            border: 1px solid #d8d2c2;
            border-radius: 8px;
            font-size: 14px;
        }
        .calendar-event-embed .badge {
            background: #f4f1e8;
            border: 1px solid #e3ded0;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            color: #5a5a55;
            margin-right: 0;
        }
        .calendar-event-embed .event-detail {
            display: block;
            padding: 0 20px;
        }
        .calendar-event-embed .event-thumb {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 14px;
            border: 1px solid #e3ded0;
            margin-bottom: 14px;
            display: block;
        }
        .calendar-event-embed .event-badges {
            margin: 8px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .calendar-event-embed .event-description {
            margin: 16px 20px 0;
            line-height: 1.6;
            font-size: 14.5px;
        }
        .calendar-event-embed .event-actions {
            padding: 0 20px;
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .calendar-event-embed .event-actions .rsvp-box,
        .calendar-event-embed .event-actions form.rsvp-form {
            width: 100%;
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
<?php include __DIR__ . '/_back_to_site.php'; ?>
<?php echo $content; ?>
</body>
</html>
<?php endif; ?>
