<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

calendar_require_role(['ADMIN', 'AREA_REP']);
$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events', 'calendar_event_rsvps', 'calendar_event_tickets', 'calendar_refund_requests']);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['delete', 'approve', 'reject'])) {
        calendar_csrf_verify();
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId) {
            if ($action === 'delete') {
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
            } elseif ($action === 'approve') {
                try {
                    $stmt = $pdo->prepare('UPDATE calendar_events SET status = "published" WHERE id = :event_id');
                    $stmt->execute(['event_id' => $eventId]);
                    $success = 'Event approved and published.';
                } catch (Throwable $e) {
                    $error = 'Unable to approve event.';
                }
            } elseif ($action === 'reject') {
                try {
                    $stmt = $pdo->prepare('UPDATE calendar_events SET status = "rejected" WHERE id = :event_id');
                    $stmt->execute(['event_id' => $eventId]);
                    $success = 'Event rejected.';
                } catch (Throwable $e) {
                    $error = 'Unable to reject event.';
                }
            }
        }
    }
}

$viewMode = ($_GET['view'] ?? 'list') === 'month' ? 'month' : 'list';
$chapterFilter = $_GET['chapter_id'] ?? '';
$hasChapterId = calendar_events_has_chapter_id($pdo);
$chapters = [];
try {
    $chapters = calendar_list_chapters_for_dropdown($pdo);
} catch (Throwable $e) {
    $chapters = [];
}

$listSql = 'SELECT e.*, ' . calendar_chapter_name_sql($pdo) . ' AS chapter_name FROM calendar_events e LEFT JOIN chapters c ON c.id = e.chapter_id';
$listParams = [];
if ($chapterFilter !== '' && $hasChapterId) {
    $listSql .= ' WHERE (e.scope = "NATIONAL" OR e.chapter_id = :chapter_id)';
    $listParams['chapter_id'] = (int) $chapterFilter;
}
$listSql .= ' ORDER BY e.start_at DESC';
$stmt = $pdo->prepare($listSql);
$stmt->execute($listParams);
$events = $stmt->fetchAll();
$chapterQuery = $chapterFilter !== '' ? '&chapter_id=' . urlencode((string) $chapterFilter) : '';

$pageTitle = 'Calendar Events';
$activePage = 'calendar-events';
require __DIR__ . '/../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Calendar Events'; require __DIR__ . '/../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <div data-tour="book-event-header" class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-sm text-gray-500">Dashboard / Calendar</p>
          <h1 class="font-display text-2xl font-bold text-gray-900">Calendar Events</h1>
        </div>
        <a data-tour="manage-events-create" href="admin_event_create.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-ink text-white text-sm font-semibold shadow-soft hover:bg-primary-strong transition-colors">
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

      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
          <a href="events.php?view=list<?php echo $chapterQuery; ?>" class="px-4 py-2 text-sm font-semibold <?php echo $viewMode === 'list' ? 'bg-ink text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">List</a>
          <a href="events.php?view=month<?php echo $chapterQuery; ?>" class="px-4 py-2 text-sm font-semibold <?php echo $viewMode === 'month' ? 'bg-ink text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">Month</a>
        </div>
        <form method="get" class="flex items-center gap-2">
          <input type="hidden" name="view" value="<?php echo calendar_e($viewMode); ?>">
          <label class="text-sm text-gray-500" for="chapter_id">Chapter</label>
          <select id="chapter_id" name="chapter_id" onchange="this.form.submit()" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            <option value="">All chapters</option>
            <?php foreach ($chapters as $chapter) : ?>
              <option value="<?php echo (int) $chapter['id']; ?>" <?php echo ((string) $chapterFilter === (string) $chapter['id']) ? 'selected' : ''; ?>>
                <?php echo calendar_e($chapter['display_label'] ?? $chapter['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="rounded-lg border border-gray-200 px-3 py-2 text-sm">Apply</button></noscript>
        </form>
      </div>

      <?php if ($viewMode === 'month') : ?>
      <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
        <p class="text-sm text-gray-500 mb-3 flex items-center gap-2">
          <span class="material-icons-outlined text-base text-amber-500">touch_app</span>
          Click any day to add a ride on that date, or click an event to open it.
        </p>
        <div id="admin-calendar"></div>
      </section>
      <style>
        #admin-calendar{--fc-border-color:#e8e6df;--fc-today-bg-color:#fbf7e8;--fc-page-bg-color:transparent;font-size:14px}
        #admin-calendar .fc-toolbar-title{font-family:'Playfair Display',serif;font-weight:700;color:#111827}
        #admin-calendar .fc .fc-button{background:#fff;border:1px solid #e8e6df;color:#374151;font-weight:600;text-transform:capitalize;box-shadow:none}
        #admin-calendar .fc .fc-button:hover{background:#fbf7e8;border-color:#cfa032}
        #admin-calendar .fc .fc-button-primary:not(:disabled).fc-button-active{background:#111827;border-color:#111827;color:#fff}
        #admin-calendar .fc .fc-daygrid-day-number{font-weight:600;color:#4b5563}
        #admin-calendar .fc .fc-day-today .fc-daygrid-day-number{color:#2f7d32}
        #admin-calendar .fc-daygrid-day{transition:background .12s;cursor:pointer}
        #admin-calendar .fc-daygrid-day:hover{background:#fffdf5}
        #admin-calendar .fc-daygrid-event{border-radius:999px;padding:2px 9px;margin-top:2px;border:none}
        #admin-calendar .gw-ev-time{font-weight:700;margin-right:4px}
        #admin-calendar .gw-ev-title{font-weight:500}
      </style>
      <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
      <script>
        function gwFormatTime(d) {
          return new Intl.DateTimeFormat('en-AU', { hour: 'numeric', minute: '2-digit', hour12: true }).format(d).toUpperCase();
        }
        function gwEventContent(arg) {
          var wrap = document.createElement('div');
          wrap.className = 'gw-ev-chip';
          if (!arg.event.allDay && arg.event.start) {
            var t = document.createElement('span');
            t.className = 'gw-ev-time';
            t.textContent = gwFormatTime(arg.event.start);
            wrap.appendChild(t);
          }
          var title = document.createElement('span');
          title.className = 'gw-ev-title';
          title.textContent = arg.event.title;
          wrap.appendChild(title);
          return { domNodes: [wrap] };
        }
        document.addEventListener('DOMContentLoaded', function () {
          var el = document.getElementById('admin-calendar');
          if (!el || typeof FullCalendar === 'undefined') { return; }
          var calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            firstDay: 1,
            height: 760,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            events: 'admin_events_feed.php?chapter_id=<?php echo urlencode((string) $chapterFilter); ?>',
            eventContent: gwEventContent,
            dateClick: function (info) {
              window.location.href = 'admin_event_create.php?date=' + encodeURIComponent(info.dateStr);
            },
            eventDidMount: function (info) {
              if (info.event.extendedProps.status === 'cancelled') {
                info.el.style.opacity = '0.6';
              }
            }
          });
          calendar.render();
        });
      </script>
      <?php else : ?>
      <section data-tour="manage-events-list book-event-list" class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100">
        <?php if (empty($events)) : ?>
          <p class="text-sm text-gray-500">No events found.</p>
        <?php else : ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead data-tour="manage-events-columns" class="text-left text-xs uppercase text-gray-500 border-b">
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
                <?php $eventRowIndex = 0; foreach ($events as $event) : $eventRowIndex++; ?>
                  <tr<?php echo $eventRowIndex === 1 ? ' data-tour="manage-events-row book-event-row"' : ''; ?> class="<?php echo $event['status'] === 'pending' ? 'bg-amber-50' : ''; ?>">
                    <td class="py-2 pr-3 text-gray-900 font-medium"><?php echo calendar_e($event['title']); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e(calendar_human_scope($event['scope'])); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e($event['chapter_name'] ?? '-'); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e(calendar_format_dt($event['start_at'], $event['timezone'])); ?></td>
                    <td class="py-2 pr-3 text-gray-600"><?php echo calendar_e(calendar_format_dt($event['end_at'], $event['timezone'])); ?></td>
                    <td class="py-2 pr-3 text-gray-600">
                      <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold <?php
                        echo $event['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' :
                            ($event['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800');
                      ?>">
                        <?php echo calendar_e($event['status']); ?>
                      </span>
                    </td>
                    <td class="py-2">
                      <div class="flex flex-wrap items-center gap-3">
                        <a<?php echo $eventRowIndex === 1 ? ' data-tour="manage-events-view book-event-view"' : ''; ?> class="text-secondary font-semibold" href="admin_event_view.php?id=<?php echo (int) $event['id']; ?>">View</a>
                        
                        <?php if ($event['status'] === 'pending') : ?>
                          <form method="post" class="inline">
                            <?php echo calendar_csrf_field(); ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                            <button type="submit" class="text-emerald-600 font-semibold hover:text-emerald-800">Approve</button>
                          </form>
                          <form method="post" class="inline" onsubmit="return confirm('Refuse this event?');">
                            <?php echo calendar_csrf_field(); ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                            <button type="submit" class="text-orange-600 font-semibold hover:text-orange-800">Reject</button>
                          </form>
                        <?php endif; ?>

                        <form<?php echo $eventRowIndex === 1 ? ' data-tour="manage-events-delete"' : ''; ?> method="post" onsubmit="return confirm('Delete this event? This cannot be undone.');">
                          <?php echo calendar_csrf_field(); ?>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                          <button type="submit" class="text-red-600 font-semibold hover:text-red-800">Delete</button>
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
      <?php endif; ?>
    </div>
  </main>
</div>
</html>
