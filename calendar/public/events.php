<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

calendar_require_role(['ADMIN', 'CHAPTER_LEADER', 'COMMITTEE', 'TREASURER']);
$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events', 'calendar_event_rsvps', 'calendar_event_tickets', 'calendar_refund_requests']);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    calendar_csrf_verify();
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM calendar_event_rsvps WHERE event_id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $stmt = $pdo->prepare('DELETE FROM calendar_event_tickets WHERE event_id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $stmt = $pdo->prepare('DELETE FROM calendar_refund_requests WHERE event_id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $stmt = $pdo->prepare('DELETE FROM calendar_events WHERE id = :event_id');
            $stmt->execute(['event_id' => $eventId]);
            $pdo->commit();
            $success = 'Event deleted.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Unable to delete event.';
        }
    }
}

$stmt = $pdo->query('SELECT e.*, c.name AS chapter_name FROM calendar_events e LEFT JOIN chapters c ON c.id = e.chapter_id ORDER BY e.start_at DESC');
$events = $stmt->fetchAll();

$pageTitle = 'Calendar Events';
$activePage = 'calendar-events';
require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Calendar Events'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-sm text-gray-500">Dashboard / Calendar</p>
          <h1 class="font-display text-2xl font-bold text-gray-900">Calendar Events</h1>
        </div>
        <a href="admin_event_create.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold shadow-soft hover:bg-primary-strong transition-colors">
          <span class="material-icons-outlined text-base">add</span>
          Create Event
        </a>
      </div>

      <?php if ($success) : ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <?php echo calendar_e($success); ?>
        </div>
      <?php endif; ?>
      <?php if ($error) : ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <?php echo calendar_e($error); ?>
        </div>
      <?php endif; ?>

      <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
        <?php if (empty($events)) : ?>
          <p class="text-sm text-gray-500">No events found.</p>
        <?php else : ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs uppercase text-gray-500 border-b">
                <tr>
                  <th class="py-2 pr-3">Title</th>
                  <th class="py-2 pr-3">Scope</th>
                  <th class="py-2 pr-3">Chapter</th>
                  <th class="py-2 pr-3">Start</th>
                  <th class="py-2 pr-3">End</th>
                  <th class="py-2 pr-3">Status</th>
                  <th class="py-2">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($events as $event) : ?>
                  <tr>
                    <td class="py-2 pr-3 text-gray-900 font-medium"><?php echo calendar_e($event['title']); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e(calendar_human_scope($event['scope'])); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e($event['chapter_name'] ?? '-'); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e(calendar_format_dt($event['start_at'], $event['timezone'])); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e(calendar_format_dt($event['end_at'], $event['timezone'])); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e($event['status']); ?></td>
                    <td class="py-2">
                      <div class="flex flex-wrap items-center gap-3">
                        <a class="text-secondary font-semibold" href="admin_event_view.php?id=<?php echo (int) $event['id']; ?>">View</a>
                        <form method="post" onsubmit="return confirm('Delete this event? This cannot be undone.');">
                          <?php echo calendar_csrf_field(); ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                          <button type="submit" class="text-red-600 font-semibold">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
</div>
</html>
