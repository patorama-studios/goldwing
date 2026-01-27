<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/mailer.php';

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret = calendar_config('stripe.webhook_secret');

function stripe_verify_signature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    if (!$secret || !$sigHeader) {
        return false;
    }
    $parts = explode(',', $sigHeader);
    $timestamp = 0;
    $signatures = [];
    foreach ($parts as $part) {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        if ($key === 't') {
            $timestamp = (int) $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }
    if (!$timestamp || empty($signatures)) {
        return false;
    }
    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }
    $signedPayload = $timestamp . '.' . $payload;
    $computed = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($computed, $signature)) {
            return true;
        }
    }
    return false;
}

if (!stripe_verify_signature($payload, $sigHeader, $secret)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!$event || empty($event['type'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

if ($event['type'] === 'checkout.session.completed') {
    $pdo = calendar_db();
    calendar_require_tables($pdo, ['calendar_events', 'calendar_event_tickets', 'calendar_orders']);
    $hasChapterId = calendar_events_has_chapter_id($pdo);
    $session = $event['data']['object'] ?? [];
    $metadata = $session['metadata'] ?? [];
    $eventId = (int) ($metadata['event_id'] ?? 0);
    $userId = (int) ($metadata['user_id'] ?? 0);
    $qty = max(1, (int) ($metadata['qty'] ?? 1));

    if (!$eventId || !$userId) {
        http_response_code(400);
        echo 'Missing metadata';
        exit;
    }

    $selectSql = 'SELECT id, title, start_at, timezone, map_url, online_url, scope, '
        . ($hasChapterId ? 'chapter_id' : 'NULL AS chapter_id')
        . ' FROM calendar_events WHERE id = :id';
    $stmt = $pdo->prepare($selectSql);
    $stmt->execute(['id' => $eventId]);
    $eventRow = $stmt->fetch();
    if (!$eventRow) {
        http_response_code(404);
        echo 'Event not found';
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo 'User not found';
        exit;
    }

    $stripeSessionId = $session['id'] ?? '';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_orders WHERE stripe_session_id = :session_id');
    $stmt->execute(['session_id' => $stripeSessionId]);
    if ($stmt->fetchColumn() > 0) {
        http_response_code(200);
        echo 'Already processed';
        exit;
    }

    $amount = isset($session['amount_total']) ? (int) $session['amount_total'] : 0;
    $paymentIntent = $session['payment_intent'] ?? null;

    $stmt = $pdo->prepare('INSERT INTO calendar_orders (user_id, stripe_session_id, stripe_payment_intent_id, amount_cents, status, created_at) VALUES (:user_id, :session_id, :payment_intent, :amount, "paid", NOW())');
    $stmt->execute([
        'user_id' => $userId,
        'session_id' => $stripeSessionId,
        'payment_intent' => $paymentIntent,
        'amount' => $amount,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $ticketCode = calendar_random_code(16);
    $ticketDir = __DIR__ . '/tickets';
    if (!is_dir($ticketDir)) {
        mkdir($ticketDir, 0755, true);
    }
    $fileName = 'ticket_' . $orderId . '_' . $ticketCode . '.pdf';
    $filePath = $ticketDir . '/' . $fileName;

    $ticketData = [
        'event_title' => $eventRow['title'],
        'user_email' => $user['email'],
        'qty' => $qty,
        'ticket_code' => $ticketCode,
        'start_at' => calendar_format_dt($eventRow['start_at'], $eventRow['timezone']),
        'location' => $eventRow['online_url'] ?: $eventRow['map_url'],
    ];

    calendar_generate_ticket_pdf($filePath, $ticketData);
    $ticketUrl = calendar_base_url('tickets/' . $fileName);

    $stmt = $pdo->prepare('INSERT INTO calendar_event_tickets (event_id, user_id, order_id, qty, ticket_code, ticket_pdf_url, created_at) VALUES (:event_id, :user_id, :order_id, :qty, :ticket_code, :ticket_pdf_url, NOW())');
    $stmt->execute([
        'event_id' => $eventId,
        'user_id' => $userId,
        'order_id' => $orderId,
        'qty' => $qty,
        'ticket_code' => $ticketCode,
        'ticket_pdf_url' => $ticketUrl,
    ]);

    $subject = 'Your ticket for ' . $eventRow['title'];
    $body = '<p>Thanks for your purchase.</p><p>Download your ticket: <a href="' . calendar_e($ticketUrl) . '">' . calendar_e($ticketUrl) . '</a></p>';
    calendar_send_email($user['email'], $subject, $body);

    if ($hasChapterId && $eventRow['scope'] === 'CHAPTER' && !empty($eventRow['chapter_id'])) {
        $stmt = $pdo->prepare('SELECT DISTINCT u.id, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin") OR (r.name = "chapter_leader" AND u.chapter_id = :chapter_id)');
        $stmt->execute(['chapter_id' => (int) $eventRow['chapter_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT DISTINCT u.id, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("admin", "chapter_leader")');
        $stmt->execute();
    }
    $admins = $stmt->fetchAll();
    foreach ($admins as $admin) {
        calendar_send_email($admin['email'], 'Ticket sale: ' . $eventRow['title'], '<p>A ticket sale was completed.</p>');
        $stmtQueue = $pdo->prepare('INSERT INTO calendar_event_notifications_queue (user_id, event_id, type, send_at, payload_json, status, sent_at, created_at) VALUES (:user_id, :event_id, "ticket_sale", NOW(), :payload, "sent", NOW(), NOW())');
        $stmtQueue->execute([
            'user_id' => $admin['id'],
            'event_id' => $eventId,
            'payload' => json_encode(['order_id' => $orderId]),
        ]);
    }
}

http_response_code(200);
echo 'OK';
