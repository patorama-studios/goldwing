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

$stmtMyEvents = $pdo->prepare('SELECT * FROM calendar_events WHERE created_by = :user_id AND status IN ("pending", "rejected") ORDER BY created_at DESC');
$stmtMyEvents->execute(['user_id' => $user['id']]);
$myEvents = $stmtMyEvents->fetchAll();
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
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h1 style="margin: 0;">Upcoming Events for You</h1>
        <a href="member_event_submit.php" style="padding: 8px 16px; background: #111; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; white-space: nowrap;">Submit Event</a>
    </div>
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
    <?php endif; ?>

    <?php if (!empty($myEvents)) : ?>
        <h2 style="margin-top: 2rem; margin-bottom: 1rem; font-size: 1.25rem;">My Submitted Events</h2>
        <ul class="list">
            <?php foreach ($myEvents as $myEvent) : ?>
                <li style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong><?php echo calendar_e($myEvent['title']); ?></strong>
                        <div class="muted" style="margin-top: 4px;">Submitted on <?php echo date('M j, Y', strtotime($myEvent['created_at'])); ?></div>
                    </div>
                    <div>
                        <span style="font-size: 11px; font-weight: bold; padding: 4px 8px; border-radius: 9999px; text-transform: uppercase; <?php echo $myEvent['status'] === 'pending' ? 'background: #fef08a; color: #854d0e;' : 'background: #fecaca; color: #991b1b;'; ?>">
                            <?php echo calendar_e($myEvent['status']); ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
</body>
</html>
