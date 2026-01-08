<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

calendar_require_role(['SUPER_ADMIN', 'ADMIN', 'CHAPTER_LEADER']);
$pdo = calendar_db();

$eventId = (int) ($_GET['event_id'] ?? 0);
$type = $_GET['type'] ?? 'rsvp';
if (!$eventId || !in_array($type, ['rsvp', 'tickets'], true)) {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

if ($type === 'rsvp') {
    $stmt = $pdo->prepare('SELECT u.email, r.qty, r.notes, r.created_at FROM calendar_event_rsvps r JOIN users u ON u.id = r.user_id WHERE r.event_id = :event_id');
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll();
    $headers = ['email', 'qty', 'notes', 'created_at'];
} else {
    $stmt = $pdo->prepare('SELECT u.email, t.qty, t.ticket_code, t.created_at FROM calendar_event_tickets t JOIN users u ON u.id = t.user_id WHERE t.event_id = :event_id');
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll();
    $headers = ['email', 'qty', 'ticket_code', 'created_at'];
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="event_' . $eventId . '_' . $type . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $header) {
        $line[] = $row[$header] ?? '';
    }
    fputcsv($output, $line);
}
fclose($output);
