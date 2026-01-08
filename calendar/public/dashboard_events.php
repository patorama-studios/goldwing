<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/calendar_occurrences.php';

calendar_require_login();
$user = calendar_current_user();
$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events']);
$hasChapterId = calendar_events_has_chapter_id($pdo);

$sql = 'SELECT e.*, m.path AS thumbnail_url FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id WHERE e.status = "published" AND (e.scope = "NATIONAL"';
$params = [];
if (!empty($user['chapter_id']) && $hasChapterId) {
    $sql .= ' OR e.chapter_id = :chapter_id';
    $params['chapter_id'] = (int) $user['chapter_id'];
}
$sql .= ')';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$rangeStart = new DateTime('now', new DateTimeZone('UTC'));
$rangeEnd = (clone $rangeStart)->modify('+30 days');

$occurrences = [];
foreach ($events as $event) {
    $eventOccurrences = calendar_expand_occurrences($event, $rangeStart, $rangeEnd);
    foreach ($eventOccurrences as $occ) {
        $occurrences[] = [
            'event' => $event,
            'start' => $occ['start'],
        ];
    }
}

usort($occurrences, function ($a, $b) {
    return $a['start'] <=> $b['start'];
});
$occurrences = array_slice($occurrences, 0, 15);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Member Events</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="calendar-container">
    <h1>Upcoming Events for You</h1>
    <p class="muted">Showing your chapter and national events in the next 30 days.</p>

    <?php if (empty($occurrences)) : ?>
        <p class="muted">No upcoming events.</p>
    <?php else : ?>
        <ul class="list">
            <?php foreach ($occurrences as $item) :
                $event = $item['event'];
                $start = $item['start']->format('Y-m-d H:i:s');
                ?>
                <li>
                    <a href="event_view.php?slug=<?php echo calendar_e($event['slug']); ?>">
                        <?php echo calendar_e($event['title']); ?>
                    </a>
                    <div class="muted"><?php echo calendar_format_dt($start, $event['timezone']); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
</body>
</html>
