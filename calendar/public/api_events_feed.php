<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calendar_occurrences.php';

$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events']);
$hasChapterId = calendar_events_has_chapter_id($pdo);
$filters = [
    'chapter_id' => $_GET['chapter_id'] ?? '',
    'event_type' => $_GET['event_type'] ?? '',
    'paid' => $_GET['paid'] ?? '',
    'timeframe' => $_GET['timeframe'] ?? '',
    'search' => trim($_GET['search'] ?? ''),
];

$startParam = $_GET['start'] ?? null;
$endParam = $_GET['end'] ?? null;
$rangeStartUtc = $startParam ? new DateTime($startParam, new DateTimeZone('UTC')) : new DateTime('now', new DateTimeZone('UTC'));
$rangeEndUtc = $endParam ? new DateTime($endParam, new DateTimeZone('UTC')) : (clone $rangeStartUtc)->modify('+90 days');
$capEnd = (new DateTime('now', new DateTimeZone('UTC')))->modify('+90 days');
if ($rangeEndUtc > $capEnd) {
    $rangeEndUtc = $capEnd;
}

$sql = 'SELECT e.*, m.path AS thumbnail_url FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id WHERE e.status IN ("published", "cancelled")';
$params = [];
if ($filters['chapter_id'] !== '' && $hasChapterId) {
    $sql .= ' AND (e.scope = "NATIONAL" OR e.chapter_id = :chapter_id)';
    $params['chapter_id'] = (int) $filters['chapter_id'];
}
if ($filters['event_type'] !== '') {
    $sql .= ' AND e.event_type = :event_type';
    $params['event_type'] = $filters['event_type'];
}
if ($filters['paid'] === 'paid') {
    $sql .= ' AND e.is_paid = 1';
} elseif ($filters['paid'] === 'free') {
    $sql .= ' AND e.is_paid = 0';
}
if ($filters['search'] !== '') {
    $sql .= ' AND (e.title LIKE :search OR e.description LIKE :search)';
    $params['search'] = '%' . $filters['search'] . '%';
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$items = [];
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
foreach ($events as $event) {
    $occurrences = calendar_expand_occurrences($event, $rangeStartUtc, $rangeEndUtc);
    foreach ($occurrences as $occ) {
        $start = $occ['start'];
        $startUtc = clone $start;
        $startUtc->setTimezone(new DateTimeZone('UTC'));
        if ($filters['timeframe'] === 'upcoming' && $startUtc < $nowUtc) {
            continue;
        }
        if ($filters['timeframe'] === 'past' && $startUtc >= $nowUtc) {
            continue;
        }
        $end = $occ['end'];
        $allDay = (int) ($event['all_day'] ?? 0) === 1;
        $endOut = clone $end;
        if ($allDay) {
            $endOut->modify('+1 day');
        }
        $items[] = [
            'id' => $event['id'] . '-' . $start->format('YmdHis'),
            'title' => $event['title'],
            'start' => $start->format('c'),
            'end' => $endOut->format('c'),
            'allDay' => $allDay,
            'url' => 'event_view.php?slug=' . urlencode($event['slug']),
            'backgroundColor' => $event['status'] === 'cancelled' ? '#9aa0a6' : '#2f5f8d',
            'borderColor' => $event['status'] === 'cancelled' ? '#9aa0a6' : '#2f5f8d',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'status' => $event['status'],
                'eventType' => $event['event_type'],
                'scope' => $event['scope'],
                'isPaid' => (int) $event['is_paid'],
            ],
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($items);
