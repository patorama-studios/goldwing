<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

calendar_require_role(['SUPER_ADMIN', 'ADMIN', 'CHAPTER_LEADER', 'COMMITTEE', 'TREASURER']);
$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events', 'calendar_event_rsvps', 'calendar_event_tickets']);

$eventId = (int) ($_GET['id'] ?? 0);
if (!$eventId) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$mediaItems = [];
try {
    $mediaItems = $pdo->query('SELECT id, path, title, thumbnail_url FROM media ORDER BY id DESC LIMIT 60')->fetchAll();
} catch (Throwable $e) {
    $mediaItems = [];
}

$products = [];
try {
    $products = $pdo->query('SELECT id, name, price_cents, currency FROM products ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $products = [];
}

$chapters = [];
try {
    $chapters = $pdo->query('SELECT id, name FROM chapters ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $chapters = [];
}

$stmt = $pdo->prepare('SELECT e.*, m.path AS thumbnail_url, m.title AS thumbnail_name, c.name AS chapter_name FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id LEFT JOIN chapters c ON c.id = e.chapter_id WHERE e.id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    calendar_csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $cancelMessage = trim($_POST['cancellation_message'] ?? '');
        $stmt = $pdo->prepare('UPDATE calendar_events SET status = "cancelled", cancellation_message = :message WHERE id = :id');
        $stmt->execute(['message' => $cancelMessage, 'id' => $eventId]);
        $message = 'Event cancelled.';
    }

    if ($action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $scope = $_POST['scope'] ?? 'CHAPTER';
        $chapterId = (int) ($_POST['chapter_id'] ?? 0);
        $eventType = $_POST['event_type'] ?? 'in_person';
        $timezone = $_POST['timezone'] ?? calendar_config('timezone_default', 'Australia/Sydney');
        $startAt = $_POST['start_at'] ?? '';
        $endAt = $_POST['end_at'] ?? '';
        $allDay = isset($_POST['all_day']) ? 1 : 0;
        $rsvpEnabled = isset($_POST['rsvp_enabled']) ? 1 : 0;
        $isPaid = isset($_POST['is_paid']) ? 1 : 0;
        $ticketProductId = $isPaid ? (int) ($_POST['ticket_product_id'] ?? 0) : null;
        $capacity = $_POST['capacity'] !== '' ? (int) $_POST['capacity'] : null;
        $salesCloseAt = $_POST['sales_close_at'] ?? null;
        $mapUrl = trim($_POST['map_url'] ?? '');
        $mapZoom = $_POST['map_zoom'] !== '' ? (int) $_POST['map_zoom'] : null;
        $onlineUrl = trim($_POST['online_url'] ?? '');
        $meetingPoint = trim($_POST['meeting_point'] ?? '');
        $destination = trim($_POST['destination'] ?? '');

        $recurrenceFreq = $_POST['recurrence_freq'] ?? '';
        $recurrenceInterval = max(1, (int) ($_POST['recurrence_interval'] ?? 1));
        $recurrenceUntil = $_POST['recurrence_until'] ?? '';
        $recurrenceRule = null;
        if ($recurrenceFreq) {
            $ruleParts = ['FREQ=' . strtoupper($recurrenceFreq), 'INTERVAL=' . $recurrenceInterval];
            if ($recurrenceUntil) {
                $ruleParts[] = 'UNTIL=' . date('Ymd', strtotime($recurrenceUntil));
            }
            if ($recurrenceFreq === 'weekly') {
                $day = strtoupper(date('D', strtotime($startAt)));
                $map = ['MON' => 'MO', 'TUE' => 'TU', 'WED' => 'WE', 'THU' => 'TH', 'FRI' => 'FR', 'SAT' => 'SA', 'SUN' => 'SU'];
                $ruleParts[] = 'BYDAY=' . ($map[$day] ?? 'MO');
            }
            $recurrenceRule = implode(';', $ruleParts);
        }

        if (!empty($_FILES['media_file']['name'])) {
            $file = $_FILES['media_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'Cover image upload failed.';
            } else {
                $maxBytes = 10 * 1024 * 1024;
                if ((int) $file['size'] > $maxBytes) {
                    $error = 'Cover image exceeds 10MB limit.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']) ?: '';
                    if (strpos($mime, 'image/') !== 0) {
                        $error = 'Cover image must be an image file.';
                    } else {
                        $uploadDir = __DIR__ . '/../../public_html/uploads/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                        $safeName = date('Ymd_His') . '_' . $safeName;
                        $targetPath = $uploadDir . $safeName;
                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            $relativePath = '/uploads/' . $safeName;
                            $titleInput = trim($_POST['media_title'] ?? '');
                            $mediaId = calendar_register_media([
                                'path' => $relativePath,
                                'file_type' => $mime,
                                'file_size' => (int) ($file['size'] ?? 0),
                                'type' => 'image',
                                'title' => $titleInput !== '' ? $titleInput : $safeName,
                                'uploaded_by_user_id' => (int) ($user['id'] ?? 0),
                                'source_context' => 'calendar',
                                'source_table' => 'calendar_events',
                                'source_record_id' => (int) $eventId,
                            ]) ?? 0;
                            if ($mediaId <= 0) {
                                $error = 'Cover image registration failed.';
                            }
                        } else {
                            $error = 'Cover image upload failed.';
                        }
                    }
                }
            }
        }

        if ($title === '') {
            $error = 'Title is required.';
        } elseif ($description === '') {
            $error = 'Description is required.';
        } elseif ($scope === 'CHAPTER' && $chapterId === 0) {
            $error = 'Chapter is required for chapter events.';
        } elseif (!$startAt || !$endAt) {
            $error = 'Start and end times are required.';
        } elseif (strtotime($endAt) < strtotime($startAt)) {
            $error = 'End time must be after start time.';
        } elseif ($isPaid && !$ticketProductId) {
            $error = 'Ticket product is required for paid events.';
        }

        if ($error === '') {
            $stmt = $pdo->prepare('UPDATE calendar_events SET title = :title, description = :description, media_id = :media_id, scope = :scope, chapter_id = :chapter_id, event_type = :event_type, timezone = :timezone, start_at = :start_at, end_at = :end_at, all_day = :all_day, recurrence_rule = :recurrence_rule, rsvp_enabled = :rsvp_enabled, is_paid = :is_paid, ticket_product_id = :ticket_product_id, capacity = :capacity, sales_close_at = :sales_close_at, map_url = :map_url, map_zoom = :map_zoom, online_url = :online_url, meeting_point = :meeting_point, destination = :destination WHERE id = :id');
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'media_id' => $mediaId ?: null,
                'scope' => $scope,
                'chapter_id' => $scope === 'CHAPTER' ? $chapterId : null,
                'event_type' => $eventType,
                'timezone' => $timezone,
                'start_at' => date('Y-m-d H:i:s', strtotime($startAt)),
                'end_at' => date('Y-m-d H:i:s', strtotime($endAt)),
                'all_day' => $allDay,
                'recurrence_rule' => $recurrenceRule,
                'rsvp_enabled' => $rsvpEnabled,
                'is_paid' => $isPaid,
                'ticket_product_id' => $ticketProductId,
                'capacity' => $capacity,
                'sales_close_at' => $salesCloseAt ? date('Y-m-d H:i:s', strtotime($salesCloseAt)) : null,
                'map_url' => $mapUrl ?: null,
                'map_zoom' => $mapZoom,
                'online_url' => $onlineUrl ?: null,
                'meeting_point' => $meetingPoint ?: null,
                'destination' => $destination ?: null,
                'id' => $eventId,
            ]);
            $message = 'Event updated.';
        }
    }

    $stmt = $pdo->prepare('SELECT e.*, m.path AS thumbnail_url, m.title AS thumbnail_name, c.name AS chapter_name FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id LEFT JOIN chapters c ON c.id = e.chapter_id WHERE e.id = :id');
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
}

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

$attendeeByUser = [];
$rsvpSql = 'SELECT r.user_id, r.qty, r.status, r.created_at, u.email, u.name AS user_name, m.id AS member_id, m.first_name, m.last_name, ' . $primaryBikeSelect . ', 0 AS paid'
    . ' FROM calendar_event_rsvps r JOIN users u ON u.id = r.user_id LEFT JOIN members m ON m.id = u.member_id '
    . $primaryBikeJoin
    . ' WHERE r.event_id = :event_id ORDER BY r.created_at ASC';
if ((int) $event['is_paid'] === 1) {
    $rsvpSql = 'SELECT r.user_id, r.qty, r.status, r.created_at, u.email, u.name AS user_name, m.id AS member_id, m.first_name, m.last_name, ' . $primaryBikeSelect . ', CASE WHEN t.user_id IS NULL THEN 0 ELSE 1 END AS paid'
        . ' FROM calendar_event_rsvps r JOIN users u ON u.id = r.user_id'
        . ' LEFT JOIN members m ON m.id = u.member_id '
        . ' LEFT JOIN (SELECT DISTINCT user_id, event_id FROM calendar_event_tickets WHERE event_id = :event_id) t ON t.user_id = r.user_id AND t.event_id = r.event_id '
        . $primaryBikeJoin
        . ' WHERE r.event_id = :event_id ORDER BY r.created_at ASC';
}
$stmt = $pdo->prepare($rsvpSql);
$stmt->execute(['event_id' => $eventId]);
$rsvpRows = $stmt->fetchAll();

foreach ($rsvpRows as $row) {
    $userId = (int) $row['user_id'];
    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($fullName === '') {
        $fullName = $row['user_name'] ?? '';
    }
    $attendeeByUser[$userId] = [
        'member_id' => $row['member_id'] ?? null,
        'name' => $fullName !== '' ? $fullName : ($row['email'] ?? ''),
        'email' => $row['email'] ?? '',
        'primary_bike' => $row['primary_bike'] ?? '',
        'paid' => (int) ($row['paid'] ?? 0),
    ];
}

if ((int) $event['is_paid'] === 1) {
    $ticketSql = 'SELECT t.user_id, t.qty, t.created_at, u.email, u.name AS user_name, m.id AS member_id, m.first_name, m.last_name, ' . $primaryBikeSelect . ', 1 AS paid'
        . ' FROM calendar_event_tickets t JOIN users u ON u.id = t.user_id LEFT JOIN members m ON m.id = u.member_id '
        . $primaryBikeJoin
        . ' WHERE t.event_id = :event_id ORDER BY t.created_at ASC';
    $stmt = $pdo->prepare($ticketSql);
    $stmt->execute(['event_id' => $eventId]);
    $ticketRows = $stmt->fetchAll();
    foreach ($ticketRows as $row) {
        $userId = (int) $row['user_id'];
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $row['user_name'] ?? '';
        }
        if (!isset($attendeeByUser[$userId])) {
            $attendeeByUser[$userId] = [
                'member_id' => $row['member_id'] ?? null,
                'name' => $fullName !== '' ? $fullName : ($row['email'] ?? ''),
                'email' => $row['email'] ?? '',
                'primary_bike' => $row['primary_bike'] ?? '',
                'paid' => 1,
            ];
        } else {
            $attendeeByUser[$userId]['paid'] = 1;
        }
    }
}

$attendees = array_values($attendeeByUser);
usort($attendees, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$timezoneOptions = [
    'Australia/Sydney',
    'Australia/Melbourne',
    'Australia/Brisbane',
    'Australia/Adelaide',
    'Australia/Perth',
    'Australia/Hobart',
    'Australia/Darwin',
    'Australia/Canberra',
];

$pageTitle = 'Event Details';
$activePage = 'calendar-events';
require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Event Details'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-sm text-gray-500">Dashboard / Calendar / Event</p>
          <h1 class="font-display text-2xl font-bold text-gray-900"><?php echo calendar_e($event['title']); ?></h1>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700" href="events.php">Back to list</a>
          <button form="event-edit-form" type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold shadow-soft hover:bg-primary-strong transition-colors">
            <span class="material-icons-outlined text-base">save</span>
            Save Changes
          </button>
        </div>
      </div>

      <?php if ($message) : ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <?php echo calendar_e($message); ?>
        </div>
      <?php endif; ?>
      <?php if ($error) : ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <?php echo calendar_e($error); ?>
        </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 lg:grid-cols-[2fr_1fr] gap-6">
        <form id="event-edit-form" method="post" enctype="multipart/form-data" class="space-y-6">
          <?php echo calendar_csrf_field(); ?>
          <input type="hidden" name="action" value="update">

          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">event</span>
              Event Details
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Event Title
              <input type="text" name="title" value="<?php echo calendar_e($event['title']); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
            </label>
            <label class="block text-sm font-medium text-gray-700">
              Description
              <textarea name="description" rows="6" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required><?php echo calendar_e($event['description']); ?></textarea>
            </label>

            <div>
              <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">Cover Image / Thumbnail</span>
                <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600" onclick="openMediaModal()">
                  <span class="material-icons-outlined text-base">photo_library</span>
                  Choose from Media Library
                </button>
              </div>
              <input type="hidden" name="media_id" id="media_id" value="<?php echo calendar_e($event['media_id'] ?? ''); ?>">
              <div class="mt-3 border-2 border-dashed border-gray-200 rounded-2xl p-4 bg-gray-50">
                <div class="flex items-center gap-4">
                  <div id="media_preview" class="h-24 w-40 rounded-xl bg-white border border-gray-200 flex items-center justify-center text-gray-400 text-sm">
                    <?php if (!empty($event['thumbnail_url'])) : ?>
                      <img src="<?php echo calendar_e($event['thumbnail_url']); ?>" alt="<?php echo calendar_e($event['thumbnail_name'] ?? 'Cover'); ?>" class="h-full w-full object-cover rounded-xl">
                    <?php else : ?>
                      No image selected
                    <?php endif; ?>
                  </div>
                  <div>
                    <div id="media_selected" class="text-sm font-medium text-gray-700"><?php echo calendar_e($event['thumbnail_name'] ?? 'Select a cover image'); ?></div>
                    <p class="text-xs text-gray-500">Choose an image from the existing Media Library.</p>
                  </div>
                </div>
              </div>
              <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="block text-sm font-medium text-gray-700">
                  Upload new image
                  <input type="file" name="media_file" accept="image/*" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                </label>
                <label class="block text-sm font-medium text-gray-700">
                  Image title (optional)
                  <input type="text" name="media_title" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                </label>
              </div>
            </div>
          </section>

          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">place</span>
              Location & Meeting
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label class="block text-sm font-medium text-gray-700">
                Meeting Point
                <input type="text" name="meeting_point" value="<?php echo calendar_e($event['meeting_point'] ?? ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Destination
                <input type="text" name="destination" value="<?php echo calendar_e($event['destination'] ?? ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label class="block text-sm font-medium text-gray-700">
                Map URL (Google Maps)
                <input type="text" name="map_url" value="<?php echo calendar_e($event['map_url'] ?? ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Map Zoom (1-20)
                <input type="number" name="map_zoom" min="1" max="20" value="<?php echo calendar_e($event['map_zoom'] ?? ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Online Meeting URL
              <input type="text" name="online_url" value="<?php echo calendar_e($event['online_url'] ?? ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            </label>
          </section>
        </form>

        <div class="space-y-6">
          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">category</span>
              Classification
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Scope
              <select name="scope" form="event-edit-form" id="scope" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" onchange="toggleChapter()">
                <option value="CHAPTER" <?php echo $event['scope'] === 'CHAPTER' ? 'selected' : ''; ?>>Chapter</option>
                <option value="NATIONAL" <?php echo $event['scope'] === 'NATIONAL' ? 'selected' : ''; ?>>National</option>
              </select>
            </label>
            <label id="chapter_wrap" class="block text-sm font-medium text-gray-700">
              Chapter
              <select name="chapter_id" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="">Select chapter</option>
                <?php foreach ($chapters as $chapter) : ?>
                  <option value="<?php echo (int) $chapter['id']; ?>" <?php echo (int) $event['chapter_id'] === (int) $chapter['id'] ? 'selected' : ''; ?>><?php echo calendar_e($chapter['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="block text-sm font-medium text-gray-700">
              Event Type
              <select name="event_type" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="in_person" <?php echo $event['event_type'] === 'in_person' ? 'selected' : ''; ?>>In-person</option>
                <option value="online" <?php echo $event['event_type'] === 'online' ? 'selected' : ''; ?>>Online</option>
                <option value="hybrid" <?php echo $event['event_type'] === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
              </select>
            </label>
          </section>

          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">schedule</span>
              Date & Time
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Timezone
              <select name="timezone" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <?php foreach ($timezoneOptions as $tz) : ?>
                  <option value="<?php echo calendar_e($tz); ?>" <?php echo $event['timezone'] === $tz ? 'selected' : ''; ?>><?php echo calendar_e($tz); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" name="all_day" form="event-edit-form" value="1" class="rounded border-gray-200" <?php echo (int) $event['all_day'] === 1 ? 'checked' : ''; ?>>
              All-day event
            </label>
            <label class="block text-sm font-medium text-gray-700">
              Start
              <input type="datetime-local" name="start_at" form="event-edit-form" value="<?php echo calendar_e(date('Y-m-d\TH:i', strtotime($event['start_at']))); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
            </label>
            <label class="block text-sm font-medium text-gray-700">
              End
              <input type="datetime-local" name="end_at" form="event-edit-form" value="<?php echo calendar_e(date('Y-m-d\TH:i', strtotime($event['end_at']))); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
            </label>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <label class="block text-sm font-medium text-gray-700">
                Recurrence
                <select name="recurrence_freq" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                  <option value="" <?php echo empty($event['recurrence_rule']) ? 'selected' : ''; ?>>None</option>
                  <option value="weekly" <?php echo strpos((string) $event['recurrence_rule'], 'FREQ=WEEKLY') !== false ? 'selected' : ''; ?>>Weekly</option>
                  <option value="monthly" <?php echo strpos((string) $event['recurrence_rule'], 'FREQ=MONTHLY') !== false ? 'selected' : ''; ?>>Monthly</option>
                </select>
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Interval
                <input type="number" name="recurrence_interval" form="event-edit-form" min="1" value="1" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Until
                <input type="date" name="recurrence_until" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
          </section>

          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">tune</span>
              Registration & Settings
            </div>
            <label class="flex items-start justify-between gap-3 text-sm">
              <span>
                <span class="font-medium text-gray-800">Enable RSVP</span>
                <span class="block text-xs text-gray-500">Allow members to register</span>
              </span>
              <input type="checkbox" name="rsvp_enabled" form="event-edit-form" value="1" class="rounded border-gray-200" <?php echo (int) $event['rsvp_enabled'] === 1 ? 'checked' : ''; ?>>
            </label>
            <label class="flex items-start justify-between gap-3 text-sm">
              <span>
                <span class="font-medium text-gray-800">Paid Event</span>
                <span class="block text-xs text-gray-500">Requires payment to join</span>
              </span>
              <input type="checkbox" name="is_paid" form="event-edit-form" value="1" class="rounded border-gray-200" onchange="togglePaid()" <?php echo (int) $event['is_paid'] === 1 ? 'checked' : ''; ?>>
            </label>
            <div id="ticket_product_wrap" class="space-y-2" style="display:none;">
              <label class="block text-sm font-medium text-gray-700">
                Ticket product
                <select name="ticket_product_id" form="event-edit-form" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                  <option value="">Select product</option>
                  <?php foreach ($products as $product) : ?>
                    <option value="<?php echo (int) $product['id']; ?>" <?php echo (int) $event['ticket_product_id'] === (int) $product['id'] ? 'selected' : ''; ?>>
                      <?php echo calendar_e($product['name']); ?> - <?php echo calendar_e($product['currency']); ?> <?php echo number_format($product['price_cents'] / 100, 2); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <label class="block text-sm font-medium text-gray-700">
                Capacity
                <input type="number" name="capacity" form="event-edit-form" min="1" value="<?php echo calendar_e($event['capacity'] ?? ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Unlimited">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Sales close
                <input type="datetime-local" name="sales_close_at" form="event-edit-form" value="<?php echo calendar_e($event['sales_close_at'] ? date('Y-m-d\TH:i', strtotime($event['sales_close_at'])) : ''); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
          </section>

          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">group</span>
              Attendees & Exports
            </div>
            <div class="flex flex-wrap gap-3">
              <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700" href="export_attendees.php?event_id=<?php echo (int) $eventId; ?>&type=rsvp">Export RSVPs</a>
              <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700" href="export_attendees.php?event_id=<?php echo (int) $eventId; ?>&type=tickets">Export Tickets</a>
              <a class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700" href="admin_refunds.php?event_id=<?php echo (int) $eventId; ?>">Refund Requests</a>
            </div>
            <?php if (empty($attendees)) : ?>
              <p class="text-sm text-gray-500">No attendees yet.</p>
            <?php else : ?>
              <div class="text-xs text-gray-500">Total attendees: <?php echo count($attendees); ?></div>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-left text-xs uppercase text-gray-500 border-b">
                    <tr>
                      <th class="py-2 pr-3">Member ID</th>
                      <th class="py-2 pr-3">Name</th>
                      <th class="py-2 pr-3">Email</th>
                      <th class="py-2 pr-3">Primary Bike</th>
                      <?php if ((int) $event['is_paid'] === 1) : ?>
                        <th class="py-2">Paid</th>
                      <?php endif; ?>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <?php foreach ($attendees as $attendee) : ?>
                      <tr>
                        <td class="py-2 pr-3 text-gray-700"><?php echo calendar_e((string) ($attendee['member_id'] ?? '—')); ?></td>
                        <td class="py-2 pr-3 text-gray-900 font-medium"><?php echo calendar_e($attendee['name']); ?></td>
                        <td class="py-2 pr-3 text-gray-700"><?php echo calendar_e($attendee['email']); ?></td>
                        <td class="py-2 pr-3 text-gray-700"><?php echo calendar_e($attendee['primary_bike'] !== '' ? $attendee['primary_bike'] : '—'); ?></td>
                        <?php if ((int) $event['is_paid'] === 1) : ?>
                          <td class="py-2">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo $attendee['paid'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'; ?>">
                              <?php echo $attendee['paid'] ? 'Paid' : 'Unpaid'; ?>
                            </span>
                          </td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">cancel</span>
              Cancel Event
            </div>
            <?php if ($event['status'] === 'cancelled') : ?>
              <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">This event is cancelled. <?php echo calendar_e($event['cancellation_message'] ?? ''); ?></div>
            <?php else : ?>
              <form method="post" class="space-y-3">
                <?php echo calendar_csrf_field(); ?>
                <input type="hidden" name="action" value="cancel">
                <input type="text" name="cancellation_message" placeholder="Optional cancellation message" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold">Cancel Event</button>
              </form>
            <?php endif; ?>
          </section>
        </div>
      </div>
    </div>
  </main>
</div>

<div id="media_modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
  <div class="bg-white rounded-2xl p-6 shadow-card max-w-4xl w-full max-h-[80vh] overflow-y-auto">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-gray-900">Select Cover Image</h2>
      <button type="button" class="text-gray-500" onclick="closeMediaModal()">
        <span class="material-icons-outlined">close</span>
      </button>
    </div>
    <?php if (empty($mediaItems)) : ?>
      <p class="text-sm text-gray-500">No media available.</p>
    <?php else : ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
        <?php foreach ($mediaItems as $media) : ?>
          <?php $thumb = $media['thumbnail_url'] ?: $media['path']; ?>
          <button type="button" class="border border-gray-200 rounded-xl overflow-hidden bg-white text-left hover:shadow-soft" onclick="selectMedia(<?php echo (int) $media['id']; ?>, '<?php echo calendar_e($media['title']); ?>', '<?php echo calendar_e($thumb); ?>')">
            <img src="<?php echo calendar_e($thumb); ?>" alt="<?php echo calendar_e($media['title']); ?>" class="h-24 w-full object-cover">
            <div class="p-2 text-xs text-gray-600 truncate"><?php echo calendar_e($media['title']); ?></div>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleChapter() {
  var scope = document.getElementById('scope').value;
  document.getElementById('chapter_wrap').style.display = scope === 'CHAPTER' ? 'block' : 'none';
}
function togglePaid() {
  var isPaid = document.querySelector('input[name="is_paid"]').checked;
  document.getElementById('ticket_product_wrap').style.display = isPaid ? 'block' : 'none';
}
function openMediaModal() {
  var modal = document.getElementById('media_modal');
  modal.classList.remove('hidden');
  modal.classList.add('flex');
}
function closeMediaModal() {
  var modal = document.getElementById('media_modal');
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}
function selectMedia(id, name, url) {
  document.getElementById('media_id').value = id;
  document.getElementById('media_selected').textContent = name;
  var preview = document.getElementById('media_preview');
  preview.innerHTML = '<img src="' + url + '" alt="' + name + '" class="h-full w-full object-cover rounded-xl">';
  closeMediaModal();
}
(function initForm() {
  toggleChapter();
  togglePaid();
})();
</script>
</html>
