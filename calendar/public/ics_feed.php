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
    'timeframe' => $_GET['timeframe'] ?? 'upcoming',
    'search' => trim($_GET['search'] ?? ''),
];

$startParam = $_GET['start'] ?? null;
$endParam = $_GET['end'] ?? null;
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));

if ($filters['timeframe'] === 'past') {
    $rangeEndUtc = $endParam ? new DateTime($endParam, new DateTimeZone('UTC')) : clone $nowUtc;
    $rangeStartUtc = $startParam ? new DateTime($startParam, new DateTimeZone('UTC')) : (clone $rangeEndUtc)->modify('-90 days');
} else {
    $rangeStartUtc = $startParam ? new DateTime($startParam, new DateTimeZone('UTC')) : clone $nowUtc;
    $rangeEndUtc = $endParam ? new DateTime($endParam, new DateTimeZone('UTC')) : (clone $rangeStartUtc)->modify('+90 days');
}

$maxRangeEnd = (clone $rangeStartUtc)->modify('+180 days');
if ($rangeEndUtc > $maxRangeEnd) {
    $rangeEndUtc = $maxRangeEnd;
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
foreach ($events as $event) {
    $occurrences = calendar_expand_occurrences($event, $rangeStartUtc, $rangeEndUtc);
    foreach ($occurrences as $occ) {
        $startUtc = clone $occ['start'];
        $startUtc->setTimezone(new DateTimeZone('UTC'));
        if ($filters['timeframe'] === 'upcoming' && $startUtc < $nowUtc) {
            continue;
        }
        if ($filters['timeframe'] === 'past' && $startUtc >= $nowUtc) {
            continue;
        }
        $items[] = [
            'event' => $event,
            'start' => $occ['start'],
            'end' => $occ['end'],
        ];
    }
}

usort($items, function ($a, $b) {
    return $a['start'] <=> $b['start'];
});

if (count($items) > 200) {
    $items = array_slice($items, 0, 200);
}

function ics_escape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(';', '\\;', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace("\n", '\\n', $text);
    return $text;
}

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'CALSCALE:GREGORIAN',
    'PRODID:-//Goldwing Association//Calendar Feed//EN',
];

foreach ($items as $item) {
    $event = $item['event'];
    $tz = $event['timezone'] ?? calendar_config('timezone_default', 'UTC');
    $start = $item['start'];
    $end = $item['end'];
    $allDay = (int) ($event['all_day'] ?? 0) === 1;
    $uid = $event['id'] . '-' . $start->format('YmdHis') . '@goldwing-events';
    $location = $event['online_url'] ?: $event['map_url'];
    $status = $event['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED';
    $url = calendar_base_url('calendar/event_view.php?slug=' . urlencode($event['slug']));

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($allDay) {
        $endOut = clone $end;
        $endOut->modify('+1 day');
        $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
        $lines[] = 'DTEND;VALUE=DATE:' . $endOut->format('Ymd');
    } else {
        $lines[] = 'DTSTART;TZID=' . $tz . ':' . $start->format('Ymd\THis');
        $lines[] = 'DTEND;TZID=' . $tz . ':' . $end->format('Ymd\THis');
    }
    $lines[] = 'SUMMARY:' . ics_escape((string) $event['title']);
    $lines[] = 'DESCRIPTION:' . ics_escape((string) ($event['description'] ?? ''));
    $lines[] = 'LOCATION:' . ics_escape((string) $location);
    $lines[] = 'STATUS:' . $status;
    if ($url !== '') {
        $lines[] = 'URL:' . ics_escape($url);
    }
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="goldwing-calendar.ics"');
echo implode("\r\n", $lines);
