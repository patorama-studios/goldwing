<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calendar_occurrences.php';
require_once __DIR__ . '/../lib/mailer.php';

$pdo = calendar_db();

function digest_decode_setting(?string $json, $default = null)
{
    if ($json === null || $json === '') {
        return $default;
    }
    $decoded = json_decode($json, true);
    return $decoded !== null ? $decoded : $default;
}

function digest_get_global(PDO $pdo, string $category, string $key, $default = null)
{
    $stmt = $pdo->prepare('SELECT value_json FROM settings_global WHERE category = :category AND key_name = :key LIMIT 1');
    $stmt->execute(['category' => $category, 'key' => $key]);
    $row = $stmt->fetch();
    return digest_decode_setting($row['value_json'] ?? null, $default);
}

function digest_get_user_prefs(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT value_json FROM settings_user WHERE user_id = :user_id AND key_name = :key LIMIT 1');
    $stmt->execute(['user_id' => $userId, 'key' => 'notification_preferences']);
    $row = $stmt->fetch();
    $prefs = digest_decode_setting($row['value_json'] ?? null, []);
    if (!is_array($prefs)) {
        $prefs = [];
    }
    $defaults = [
        'email_notifications' => true,
        'weekly_digest' => true,
        'system_alerts' => true,
    ];
    return array_merge($defaults, $prefs);
}

$globalDigestEnabled = (bool) digest_get_global($pdo, 'notifications', 'weekly_digest_enabled', false);
if (!$globalDigestEnabled) {
    exit;
}

$users = $pdo->query('SELECT u.id, u.email, m.chapter_id, m.state FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.is_active = 1')->fetchAll();
$hasChapterId = calendar_events_has_chapter_id($pdo);

$rangeStart = new DateTime('now', new DateTimeZone('UTC'));
$rangeEnd = (clone $rangeStart)->modify('+14 days');
$noticeCutoff = (clone $rangeStart)->modify('-7 days')->format('Y-m-d H:i:s');

foreach ($users as $user) {
    if (empty($user['email'])) {
        continue;
    }

    $prefs = digest_get_user_prefs($pdo, (int) $user['id']);
    if (empty($prefs['email_notifications']) || empty($prefs['weekly_digest'])) {
        continue;
    }

    $sql = 'SELECT * FROM calendar_events WHERE status = "published" AND (scope = "NATIONAL"';
    $params = [];
    if (!empty($user['chapter_id']) && $hasChapterId) {
        $sql .= ' OR chapter_id = :chapter_id';
        $params['chapter_id'] = (int) $user['chapter_id'];
    }
    $sql .= ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

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

    $eventLines = [];
    foreach ($occurrences as $item) {
        $event = $item['event'];
        $start = $item['start']->format('Y-m-d H:i:s');
        $eventLines[] = calendar_e($event['title']) . ' - ' . calendar_format_dt($start, $event['timezone']);
    }

    $noticeSql = 'SELECT title, content, published_at FROM notices WHERE visibility IN ("member", "public") AND published_at >= :cutoff'
        . ' AND (audience_scope = "all" OR (audience_scope = "state" AND audience_state = :state) OR (audience_scope = "chapter" AND audience_chapter_id = :chapter))'
        . ' ORDER BY published_at DESC';
    $stateNameMap = [
        'AUSTRALIAN CAPITAL TERRITORY' => 'ACT',
        'NEW SOUTH WALES' => 'NSW',
        'NORTHERN TERRITORY' => 'NT',
        'QUEENSLAND' => 'QLD',
        'SOUTH AUSTRALIA' => 'SA',
        'TASMANIA' => 'TAS',
        'VICTORIA' => 'VIC',
        'WESTERN AUSTRALIA' => 'WA',
    ];
    $memberStateRaw = strtoupper(trim($user['state'] ?? ''));
    $memberState = $stateNameMap[$memberStateRaw] ?? $memberStateRaw;

    $noticeStmt = $pdo->prepare($noticeSql);
    $noticeStmt->execute([
        'cutoff' => $noticeCutoff,
        'state' => $memberState,
        'chapter' => $user['chapter_id'] ?? 0,
    ]);
    $notices = $noticeStmt->fetchAll();

    if (empty($eventLines) && empty($notices)) {
        continue;
    }

    $subject = 'Weekly Member Digest';
    $body = '<p>Here is your weekly update.</p>';

    if (!empty($eventLines)) {
        $body .= '<h3 style="margin:16px 0 8px;">Upcoming events (next 14 days)</h3><ul>';
        foreach ($eventLines as $line) {
            $body .= '<li>' . $line . '</li>';
        }
        $body .= '</ul>';
    }

    if (!empty($notices)) {
        $body .= '<h3 style="margin:16px 0 8px;">New notices</h3><ul>';
        foreach ($notices as $notice) {
            $title = calendar_e($notice['title'] ?? 'Notice');
            $body .= '<li>' . $title . '</li>';
        }
        $body .= '</ul>';
    }

    calendar_send_email($user['email'], $subject, $body);

    $stmtQueue = $pdo->prepare('INSERT INTO calendar_event_notifications_queue (user_id, event_id, type, send_at, payload_json, status, sent_at, created_at) VALUES (:user_id, NULL, "weekly_digest", NOW(), :payload, "sent", NOW(), NOW())');
    $stmtQueue->execute([
        'user_id' => $user['id'],
        'payload' => json_encode(['events' => count($eventLines), 'notices' => count($notices)]),
    ]);
}
