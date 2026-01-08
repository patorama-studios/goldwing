<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/mailer.php';

calendar_require_role(['SUPER_ADMIN', 'ADMIN', 'CHAPTER_LEADER', 'COMMITTEE', 'TREASURER']);
$user = calendar_current_user();
$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events']);

$chapters = [];
try {
    $chapters = $pdo->query('SELECT id, name FROM chapters ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $chapters = [];
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

$errors = [];
$success = '';
$uploadedMediaId = null;

$old = function (string $key, $default = '') {
    return $_POST[$key] ?? $default;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    calendar_csrf_verify();

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
            $errors[] = 'Cover image upload failed.';
        } else {
            $maxBytes = 10 * 1024 * 1024;
            if ((int) $file['size'] > $maxBytes) {
                $errors[] = 'Cover image exceeds 10MB limit.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']) ?: '';
                if (strpos($mime, 'image/') !== 0) {
                    $errors[] = 'Cover image must be an image file.';
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
                        $stmt = $pdo->prepare('INSERT INTO media (type, title, path, tags, visibility, uploaded_by, created_at) VALUES (:type, :title, :path, :tags, :visibility, :uploaded_by, NOW())');
                        $stmt->execute([
                            'type' => 'image',
                            'title' => $titleInput !== '' ? $titleInput : $safeName,
                            'path' => $relativePath,
                            'tags' => '',
                            'visibility' => 'member',
                            'uploaded_by' => $user['id'],
                        ]);
                        $uploadedMediaId = (int) $pdo->lastInsertId();
                        $mediaId = $uploadedMediaId;
                    } else {
                        $errors[] = 'Cover image upload failed.';
                    }
                }
            }
        }
    }

    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($description === '') {
        $errors[] = 'Description is required.';
    }
    if ($scope === 'CHAPTER' && $chapterId === 0) {
        $errors[] = 'Chapter is required for chapter events.';
    }
    if (!$startAt || !$endAt) {
        $errors[] = 'Start and end times are required.';
    } elseif (strtotime($endAt) < strtotime($startAt)) {
        $errors[] = 'End time must be after start time.';
    }
    if ($isPaid && !$ticketProductId) {
        $errors[] = 'Ticket product is required for paid events.';
    }

    if (empty($errors)) {
        $slugBase = calendar_slugify($title) . '-' . date('Ymd', strtotime($startAt));
        $slug = $slugBase;
        $suffix = 1;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_events WHERE slug = :slug');
        while (true) {
            $stmt->execute(['slug' => $slug]);
            if ($stmt->fetchColumn() == 0) {
                break;
            }
            $suffix++;
            $slug = $slugBase . '-' . $suffix;
        }

        $stmt = $pdo->prepare('INSERT INTO calendar_events (title, slug, description, media_id, scope, chapter_id, event_type, timezone, start_at, end_at, all_day, recurrence_rule, rsvp_enabled, is_paid, ticket_product_id, capacity, sales_close_at, map_url, map_zoom, online_url, meeting_point, destination, status, created_by, created_at)
            VALUES (:title, :slug, :description, :media_id, :scope, :chapter_id, :event_type, :timezone, :start_at, :end_at, :all_day, :recurrence_rule, :rsvp_enabled, :is_paid, :ticket_product_id, :capacity, :sales_close_at, :map_url, :map_zoom, :online_url, :meeting_point, :destination, "published", :created_by, NOW())');
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
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
            'created_by' => $user['id'],
        ]);

        $eventId = (int) $pdo->lastInsertId();
        $subject = 'New event created: ' . $title;
        $body = '<p>A new event has been created.</p><p><strong>' . calendar_e($title) . '</strong></p>';
        if ($scope === 'CHAPTER') {
            $stmt = $pdo->prepare('SELECT DISTINCT u.id, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("super_admin", "admin") OR (r.name = "chapter_leader" AND u.chapter_id = :chapter_id)');
            $stmt->execute(['chapter_id' => $chapterId]);
        } else {
            $stmt = $pdo->prepare('SELECT DISTINCT u.id, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.name IN ("super_admin", "admin", "chapter_leader")');
            $stmt->execute();
        }
        $admins = $stmt->fetchAll();
        foreach ($admins as $admin) {
            calendar_send_email($admin['email'], $subject, $body);
            $stmtQueue = $pdo->prepare('INSERT INTO calendar_event_notifications_queue (user_id, event_id, type, send_at, payload_json, status, sent_at, created_at) VALUES (:user_id, :event_id, "admin_new_event", NOW(), :payload, "sent", NOW(), NOW())');
            $stmtQueue->execute([
                'user_id' => $admin['id'],
                'event_id' => $eventId,
                'payload' => json_encode(['title' => $title]),
            ]);
        }

        $success = 'Event created successfully.';
    }
}

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

$pageTitle = 'Create Calendar Event';
$activePage = 'calendar-create';
require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Create Calendar Event'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-sm text-gray-500">Dashboard / Calendar Events / Create New</p>
          <h1 class="text-2xl font-display font-bold text-gray-900">Create Calendar Event</h1>
        </div>
        <div class="flex items-center gap-3">
          <a href="admin_events.php" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-200 text-sm font-semibold text-gray-700">Discard</a>
          <button form="calendar-event-form" type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold shadow-soft hover:bg-primary-strong transition-colors">
            <span class="material-icons-outlined text-base">publish</span>
            Publish Event
          </button>
        </div>
      </div>

      <?php if ($success) : ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <?php echo calendar_e($success); ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($errors)) : ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 space-y-1">
          <?php foreach ($errors as $err) : ?>
            <div><?php echo calendar_e($err); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <form id="calendar-event-form" method="post" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-[2fr_1fr] gap-6">
        <?php echo calendar_csrf_field(); ?>

        <div class="space-y-6">
          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">event</span>
              Event Details
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Event Title
              <input type="text" name="title" value="<?php echo calendar_e($old('title')); ?>" placeholder="e.g. Summer Goldwing Rally 2024" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
            </label>
            <label class="block text-sm font-medium text-gray-700">
              Description
              <textarea name="description" rows="6" placeholder="Describe the event details, itinerary, and requirements..." class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required><?php echo calendar_e($old('description')); ?></textarea>
            </label>

            <div>
              <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">Cover Image / Thumbnail</span>
                <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600" onclick="openMediaModal()">
                  <span class="material-icons-outlined text-base">photo_library</span>
                  Choose from Media Library
                </button>
              </div>
              <input type="hidden" name="media_id" id="media_id" value="<?php echo calendar_e($old('media_id')); ?>">
              <div class="mt-3 border-2 border-dashed border-gray-200 rounded-2xl p-4 bg-gray-50">
                <div class="flex items-center gap-4">
                  <div id="media_preview" class="h-24 w-40 rounded-xl bg-white border border-gray-200 flex items-center justify-center text-gray-400 text-sm">
                    No image selected
                  </div>
                  <div>
                    <div id="media_selected" class="text-sm font-medium text-gray-700">Select a cover image</div>
                    <p class="text-xs text-gray-500">Choose an image from the existing Media Library.</p>
                  </div>
                </div>
              </div>
              <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="block text-sm font-medium text-gray-700">
                  Upload new image
                  <input type="file" name="media_file" accept="image/*" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                </label>
                <label class="block text-sm font-medium text-gray-700">
                  Image title (optional)
                  <input type="text" name="media_title" value="<?php echo calendar_e($old('media_title')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
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
                <input type="text" name="meeting_point" value="<?php echo calendar_e($old('meeting_point')); ?>" placeholder="e.g. Ace Cafe" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Destination
                <input type="text" name="destination" value="<?php echo calendar_e($old('destination')); ?>" placeholder="e.g. Brighton Pier" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label class="block text-sm font-medium text-gray-700">
                Map URL (Google Maps)
                <input type="text" name="map_url" value="<?php echo calendar_e($old('map_url')); ?>" placeholder="https://maps.google.com/..." class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Map Zoom (1-20)
                <input type="number" name="map_zoom" min="1" max="20" value="<?php echo calendar_e($old('map_zoom')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Online Meeting URL
              <input type="text" name="online_url" value="<?php echo calendar_e($old('online_url')); ?>" placeholder="https://zoom.us/..." class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            </label>
          </section>
        </div>

        <div class="space-y-6">
          <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
            <div class="flex items-center gap-2 text-gray-900 font-semibold">
              <span class="material-icons-outlined text-primary">category</span>
              Classification
            </div>
            <label class="block text-sm font-medium text-gray-700">
              Scope
              <select name="scope" id="scope" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" onchange="toggleChapter()">
                <option value="CHAPTER" <?php echo $old('scope', 'CHAPTER') === 'CHAPTER' ? 'selected' : ''; ?>>Chapter</option>
                <option value="NATIONAL" <?php echo $old('scope') === 'NATIONAL' ? 'selected' : ''; ?>>National</option>
              </select>
            </label>
            <label id="chapter_wrap" class="block text-sm font-medium text-gray-700">
              Chapter
              <select name="chapter_id" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="">Select chapter</option>
                <?php foreach ($chapters as $chapter) : ?>
                  <option value="<?php echo (int) $chapter['id']; ?>" <?php echo $old('chapter_id') == $chapter['id'] ? 'selected' : ''; ?>><?php echo calendar_e($chapter['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="block text-sm font-medium text-gray-700">
              Event Type
              <select name="event_type" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <option value="in_person" <?php echo $old('event_type') === 'in_person' ? 'selected' : ''; ?>>In-person</option>
                <option value="online" <?php echo $old('event_type') === 'online' ? 'selected' : ''; ?>>Online</option>
                <option value="hybrid" <?php echo $old('event_type') === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
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
              <select name="timezone" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                <?php foreach ($timezoneOptions as $tz) : ?>
                  <option value="<?php echo calendar_e($tz); ?>" <?php echo $old('timezone', 'Australia/Sydney') === $tz ? 'selected' : ''; ?>><?php echo calendar_e($tz); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" name="all_day" value="1" class="rounded border-gray-200" <?php echo $old('all_day') ? 'checked' : ''; ?>>
              All-day event
            </label>
            <label class="block text-sm font-medium text-gray-700">
              Start
              <input type="datetime-local" name="start_at" value="<?php echo calendar_e($old('start_at')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
            </label>
            <label class="block text-sm font-medium text-gray-700">
              End
              <input type="datetime-local" name="end_at" value="<?php echo calendar_e($old('end_at')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" required>
            </label>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <label class="block text-sm font-medium text-gray-700">
                Recurrence
                <select name="recurrence_freq" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                  <option value="" <?php echo $old('recurrence_freq') === '' ? 'selected' : ''; ?>>None</option>
                  <option value="weekly" <?php echo $old('recurrence_freq') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                  <option value="monthly" <?php echo $old('recurrence_freq') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                </select>
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Interval
                <input type="number" name="recurrence_interval" min="1" value="<?php echo calendar_e($old('recurrence_interval', '1')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Until
                <input type="date" name="recurrence_until" value="<?php echo calendar_e($old('recurrence_until')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
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
              <input type="checkbox" name="rsvp_enabled" value="1" class="rounded border-gray-200" <?php echo $old('rsvp_enabled') ? 'checked' : ''; ?>>
            </label>
            <label class="flex items-start justify-between gap-3 text-sm">
              <span>
                <span class="font-medium text-gray-800">Paid Event</span>
                <span class="block text-xs text-gray-500">Requires payment to join</span>
              </span>
              <input type="checkbox" name="is_paid" value="1" class="rounded border-gray-200" onchange="togglePaid()" <?php echo $old('is_paid') ? 'checked' : ''; ?>>
            </label>
            <div id="ticket_product_wrap" class="space-y-2" style="display:none;">
              <label class="block text-sm font-medium text-gray-700">
                Ticket product
                <select name="ticket_product_id" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                  <option value="">Select product</option>
                  <?php foreach ($products as $product) : ?>
                    <option value="<?php echo (int) $product['id']; ?>" <?php echo $old('ticket_product_id') == $product['id'] ? 'selected' : ''; ?>>
                      <?php echo calendar_e($product['name']); ?> - <?php echo calendar_e($product['currency']); ?> <?php echo number_format($product['price_cents'] / 100, 2); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <label class="block text-sm font-medium text-gray-700">
                Capacity
                <input type="number" name="capacity" min="1" value="<?php echo calendar_e($old('capacity')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Unlimited">
              </label>
              <label class="block text-sm font-medium text-gray-700">
                Sales close
                <input type="datetime-local" name="sales_close_at" value="<?php echo calendar_e($old('sales_close_at')); ?>" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              </label>
            </div>
          </section>
        </div>
      </form>
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
  var selectedId = document.getElementById('media_id').value;
  if (selectedId) {
    var items = <?php echo json_encode($mediaItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var match = items.find(function(item) { return String(item.id) === String(selectedId); });
  if (match) {
      selectMedia(match.id, match.title, match.path);
  }
  }
})();
</script>
</html>
