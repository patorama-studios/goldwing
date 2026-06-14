<?php
// Admin JSON feed for the FullCalendar month view on the Calendar Events page.
// Unlike the public api_events_feed.php this requires an admin/area-rep session,
// shows EVERY status (draft, pending, published, rejected, cancelled) and links
// each occurrence to the admin edit page rather than the public event page.
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/calendar_occurrences.php';

calendar_require_role(['ADMIN', 'AREA_REP']);

$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events']);
$hasChapterId = calendar_events_has_chapter_id($pdo);

$chapterId = $_GET['chapter_id'] ?? '';

$startParam = $_GET['start'] ?? null;
$endParam = $_GET['end'] ?? null;
$rangeStartUtc = $startParam ? new DateTime($startParam, new DateTimeZone('UTC')) : new DateTime('now', new DateTimeZone('UTC'));
$rangeEndUtc = $endParam ? new DateTime($endParam, new DateTimeZone('UTC')) : (clone $rangeStartUtc)->modify('+90 days');
$capEnd = (new DateTime('now', new DateTimeZone('UTC')))->modify('+365 days');
if ($rangeEndUtc > $capEnd) {
    $rangeEndUtc = $capEnd;
}

$sql = 'SELECT e.* FROM calendar_events e';
$params = [];
$where = [];
if ($chapterId !== '' && $hasChapterId) {
    $where[] = '(e.scope = "NATIONAL" OR e.chapter_id = :chapter_id)';
    $params['chapter_id'] = (int) $chapterId;
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Colour by status so admins can see pending/cancelled at a glance.
$statusColours = [
    'published' => '#2f5f8d',
    'cancelled' => '#9aa0a6',
    'pending'   => '#d97706',
    'draft'     => '#6b7280',
    'rejected'  => '#b42318',
];
$statusLabels = [
    'cancelled' => 'Cancelled',
    'pending'   => 'Pending',
    'draft'     => 'Draft',
    'rejected'  => 'Rejected',
];

$items = [];
foreach ($events as $event) {
    $occurrences = calendar_expand_occurrences($event, $rangeStartUtc, $rangeEndUtc);
    $status = $event['status'] ?? 'published';
    $colour = $statusColours[$status] ?? '#2f5f8d';
    $suffix = isset($statusLabels[$status]) ? ' — ' . $statusLabels[$status] : '';
    foreach ($occurrences as $occ) {
        $start = $occ['start'];
        $end = $occ['end'];
        $allDay = (int) ($event['all_day'] ?? 0) === 1;
        $endOut = clone $end;
        if ($allDay) {
            $endOut->modify('+1 day');
        }
        $items[] = [
            'id' => $event['id'] . '-' . $start->format('YmdHis'),
            'title' => $event['title'] . $suffix,
            'start' => $start->format('c'),
            'end' => $endOut->format('c'),
            'allDay' => $allDay,
            'url' => 'admin_event_view.php?id=' . (int) $event['id'],
            'backgroundColor' => $colour,
            'borderColor' => $colour,
            'textColor' => '#ffffff',
            'extendedProps' => [
                'status' => $status,
                'eventType' => $event['event_type'],
                'scope' => $event['scope'],
            ],
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($items);
