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

$stmt = $pdo->prepare('SELECT is_paid FROM calendar_events WHERE id = :event_id');
$stmt->execute(['event_id' => $eventId]);
$eventRow = $stmt->fetch();
$eventIsPaid = $eventRow ? (int) ($eventRow['is_paid'] ?? 0) === 1 : false;

$hasMemberVehicles = calendar_table_exists($pdo, 'member_vehicles');
$hasMemberBikes = calendar_table_exists($pdo, 'member_bikes');
$primaryBikeSelect = 'NULL AS primary_bike';
$primaryBikeJoin = '';
if ($hasMemberVehicles) {
    $primaryBikeSelect = 'pb.primary_bike';
    $primaryBikeJoin = 'LEFT JOIN (SELECT member_id, MAX(CONCAT_WS(" ", CASE WHEN year_exact IS NOT NULL THEN year_exact WHEN year_from IS NOT NULL AND year_to IS NOT NULL THEN CONCAT(year_from, "-", year_to) WHEN year_from IS NOT NULL THEN year_from WHEN year_to IS NOT NULL THEN year_to ELSE NULL END, make, model)) AS primary_bike FROM member_vehicles WHERE is_primary = 1 GROUP BY member_id) pb ON pb.member_id = m.id';
} elseif ($hasMemberBikes) {
    $primaryBikeSelect = 'pb.primary_bike';
    $primaryBikeJoin = 'LEFT JOIN (SELECT member_id, MAX(CONCAT_WS(" ", year, make, model, colour)) AS primary_bike FROM member_bikes WHERE is_primary = 1 GROUP BY member_id) pb ON pb.member_id = m.id';
}

if ($type === 'rsvp') {
    $paidSelect = $eventIsPaid ? 'CASE WHEN t.user_id IS NULL THEN "No" ELSE "Yes" END AS paid' : '"N/A" AS paid';
    $sql = 'SELECT m.id AS member_id, m.first_name, m.last_name, u.name AS user_name, u.email, '
        . $primaryBikeSelect . ', r.qty, r.notes, r.created_at, ' . $paidSelect
        . ' FROM calendar_event_rsvps r'
        . ' JOIN users u ON u.id = r.user_id'
        . ' LEFT JOIN members m ON m.id = u.member_id';
    if ($eventIsPaid) {
        $sql .= ' LEFT JOIN (SELECT DISTINCT user_id, event_id FROM calendar_event_tickets WHERE event_id = :event_id) t ON t.user_id = r.user_id AND t.event_id = r.event_id';
    }
    $sql .= ' ' . $primaryBikeJoin . ' WHERE r.event_id = :event_id ORDER BY r.created_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll();
    $headers = ['member_id', 'name', 'email', 'primary_bike', 'qty', 'notes', 'paid', 'created_at'];
} else {
    $sql = 'SELECT m.id AS member_id, m.first_name, m.last_name, u.name AS user_name, u.email, '
        . $primaryBikeSelect . ', t.qty, t.ticket_code, t.created_at, "Yes" AS paid'
        . ' FROM calendar_event_tickets t'
        . ' JOIN users u ON u.id = t.user_id'
        . ' LEFT JOIN members m ON m.id = u.member_id '
        . $primaryBikeJoin
        . ' WHERE t.event_id = :event_id ORDER BY t.created_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll();
    $headers = ['member_id', 'name', 'email', 'primary_bike', 'qty', 'ticket_code', 'paid', 'created_at'];
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="event_' . $eventId . '_' . $type . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($rows as $row) {
    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($fullName === '') {
        $fullName = $row['user_name'] ?? '';
    }
    $row['name'] = $fullName !== '' ? $fullName : ($row['email'] ?? '');
    $line = [];
    foreach ($headers as $header) {
        $line[] = $row[$header] ?? '';
    }
    fputcsv($output, $line);
}
fclose($output);
