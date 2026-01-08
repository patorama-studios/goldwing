<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calendar_occurrences.php';
require_once __DIR__ . '/../lib/mailer.php';

$pdo = calendar_db();
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$rangeEnd = (clone $nowUtc)->modify('+7 days');

$events = $pdo->query('SELECT * FROM calendar_events WHERE status = "published"')->fetchAll();

function reminder_already_sent(PDO $pdo, int $userId, int $eventId, string $type, string $sendAt): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_event_notifications_queue WHERE user_id = :user_id AND event_id = :event_id AND type = :type AND send_at = :send_at');
    $stmt->execute([
        'user_id' => $userId,
        'event_id' => $eventId,
        'type' => $type,
        'send_at' => $sendAt,
    ]);
    return $stmt->fetchColumn() > 0;
}

foreach ($events as $event) {
    $occurrences = calendar_expand_occurrences($event, $nowUtc, $rangeEnd);
    if (empty($occurrences)) {
        continue;
    }

    $stmt = $pdo->prepare('SELECT r.user_id, u.email FROM calendar_event_rsvps r JOIN users u ON u.id = r.user_id WHERE r.event_id = :event_id AND r.status = "going"');
    $stmt->execute(['event_id' => $event['id']]);
    $rsvpUsers = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT t.user_id, u.email FROM calendar_event_tickets t JOIN users u ON u.id = t.user_id WHERE t.event_id = :event_id');
    $stmt->execute(['event_id' => $event['id']]);
    $ticketUsers = $stmt->fetchAll();

    $attendees = array_merge($rsvpUsers, $ticketUsers);
    if (empty($attendees)) {
        continue;
    }

    foreach ($occurrences as $occ) {
        $startLocal = $occ['start'];
        $startUtc = clone $startLocal;
        $startUtc->setTimezone(new DateTimeZone('UTC'));
        $diffSeconds = $startUtc->getTimestamp() - $nowUtc->getTimestamp();

        $reminderType = null;
        if (abs($diffSeconds - (7 * 24 * 3600)) <= 3600) {
            $reminderType = 'reminder_7d';
        } elseif (abs($diffSeconds - (24 * 3600)) <= 3600) {
            $reminderType = 'reminder_24h';
        }

        if (!$reminderType) {
            continue;
        }

        $sendAt = $startUtc->format('Y-m-d H:i:s');

        foreach ($attendees as $attendee) {
            if (empty($attendee['email'])) {
                continue;
            }
            if (reminder_already_sent($pdo, (int) $attendee['user_id'], (int) $event['id'], $reminderType, $sendAt)) {
                continue;
            }

            $subject = 'Event reminder: ' . $event['title'];
            $body = '<p>Reminder for your upcoming event:</p>';
            $body .= '<p><strong>' . calendar_e($event['title']) . '</strong><br>';
            $body .= calendar_format_dt($event['start_at'], $event['timezone']) . '</p>';

            calendar_send_email($attendee['email'], $subject, $body);

            $stmtQueue = $pdo->prepare('INSERT INTO calendar_event_notifications_queue (user_id, event_id, type, send_at, payload_json, status, sent_at, created_at) VALUES (:user_id, :event_id, :type, :send_at, :payload, "sent", NOW(), NOW())');
            $stmtQueue->execute([
                'user_id' => $attendee['user_id'],
                'event_id' => $event['id'],
                'type' => $reminderType,
                'send_at' => $sendAt,
                'payload' => json_encode(['start' => $sendAt]),
            ]);
        }
    }
}
