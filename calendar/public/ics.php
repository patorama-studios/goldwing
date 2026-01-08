<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';

$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events']);
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

$tz = $event['timezone'] ?? calendar_config('timezone_default', 'UTC');
$start = new DateTime($event['start_at'], new DateTimeZone($tz));
$end = new DateTime($event['end_at'], new DateTimeZone($tz));

$dtStart = $start->format('Ymd\THis');
$dtEnd = $end->format('Ymd\THis');
$uid = $event['id'] . '@goldwing-events';
$location = $event['online_url'] ?: $event['map_url'];

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
    'PRODID:-//Goldwing Association//Calendar//EN',
    'BEGIN:VEVENT',
    'UID:' . $uid,
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;TZID=' . $tz . ':' . $dtStart,
    'DTEND;TZID=' . $tz . ':' . $dtEnd,
    'SUMMARY:' . ics_escape($event['title']),
    'DESCRIPTION:' . ics_escape($event['description']),
    'LOCATION:' . ics_escape((string) $location),
    'END:VEVENT',
    'END:VCALENDAR',
];

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="event_' . $eventId . '.ics"');
echo implode("\r\n", $lines);
