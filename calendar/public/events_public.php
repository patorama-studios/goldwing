<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calendar_occurrences.php';

$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events']);

$filters = [
    'chapter_id' => $_GET['chapter_id'] ?? '',
    'event_type' => $_GET['event_type'] ?? '',
    'paid' => $_GET['paid'] ?? '',
    'timeframe' => $_GET['timeframe'] ?? 'upcoming',
    'search' => trim($_GET['search'] ?? ''),
];

$chapters = [];
try {
    $chapters = $pdo->query('SELECT id, name FROM chapters ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $chapters = [];
}

$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$rangeStart = clone $nowUtc;
$rangeEnd = clone $nowUtc;
if ($filters['timeframe'] === 'past') {
    $rangeStart->modify('-30 days');
} else {
    $rangeEnd->modify('+30 days');
}

$sql = 'SELECT e.*, m.path AS thumbnail_url FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id WHERE e.status IN ("published", "cancelled")';
$params = [];
if ($filters['chapter_id'] !== '') {
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
$sql .= ' ORDER BY e.start_at ASC';
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
            'end' => $occ['end'],
        ];
    }
}

usort($occurrences, function ($a, $b) {
    return $a['start'] <=> $b['start'];
});

if ($filters['timeframe'] === 'past') {
    $occurrences = array_slice(array_reverse($occurrences), 0, 10);
} else {
    $occurrences = array_slice($occurrences, 0, 10);
}

$filterQuery = calendar_build_query($filters);
$feedUrl = 'api_events_feed.php?' . $filterQuery;
$hasAdvancedFilters = $filters['chapter_id'] !== '' || $filters['event_type'] !== '' || $filters['paid'] !== '' || $filters['timeframe'] !== 'upcoming';
$noticeText = $filters['timeframe'] === 'past' ? 'There are no past events.' : 'There are no upcoming events.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ride Calendar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
    <style>
        :root {
            color-scheme: light;
            --ink: #111827;
            --muted: #6b7280;
            --line: #e5e7eb;
            --panel: #ffffff;
            --soft: #f3f4f6;
            --accent: #3b57f0;
            --accent-strong: #2c45d6;
            --shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
        }
        body {
            margin: 0;
            font-family: "Manrope", "Open Sans", sans-serif;
            background: transparent;
            color: var(--ink);
        }
        .calendar-shell {
            max-width: 1120px;
            margin: 0 auto;
            padding: 24px;
            display: grid;
            gap: 18px;
        }
        .calendar-controls {
            display: grid;
            gap: 12px;
        }
        .calendar-controls-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
        }
        .search-field {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 14px;
            background: var(--panel);
            min-height: 44px;
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.02);
        }
        .search-field input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 14px;
            color: var(--ink);
            font-family: inherit;
            background: transparent;
        }
        .calendar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: flex-end;
        }
        .primary-btn {
            border: none;
            border-radius: 8px;
            background: var(--accent);
            color: #ffffff;
            padding: 10px 18px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 20px rgba(59, 87, 240, 0.18);
        }
        .primary-btn:hover {
            background: var(--accent-strong);
        }
        .view-toggle {
            display: inline-flex;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            background: var(--panel);
        }
        .view-toggle button {
            border: none;
            background: transparent;
            padding: 10px 14px;
            font-size: 14px;
            color: var(--muted);
            font-weight: 600;
            cursor: pointer;
            position: relative;
        }
        .view-toggle button.is-active {
            color: var(--ink);
            box-shadow: inset 0 -2px 0 var(--ink);
        }
        .filter-drawer {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 16px;
            background: #fafafa;
        }
        .filter-drawer summary {
            cursor: pointer;
            font-weight: 600;
            color: var(--muted);
            list-style: none;
        }
        .filter-drawer summary::-webkit-details-marker {
            display: none;
        }
        .filter-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            margin-top: 12px;
        }
        .filter-grid label {
            display: grid;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .filter-grid input,
        .filter-grid select {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 14px;
            background: #fff;
            color: var(--ink);
            font-family: inherit;
        }
        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            cursor: pointer;
        }
        .ghost-btn {
            border: 1px solid var(--line);
            background: var(--panel);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            cursor: pointer;
        }
        .month-picker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            background: transparent;
            font-size: 22px;
            font-weight: 600;
            color: var(--ink);
            cursor: pointer;
        }
        .month-picker span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .month-caret {
            width: 14px;
            height: 14px;
            color: var(--muted);
        }
        .month-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 0;
            height: 0;
        }
        .calendar-notice {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            background: var(--soft);
            color: var(--ink);
            font-size: 14px;
        }
        .calendar-panel {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 10px;
            background: var(--panel);
            box-shadow: var(--shadow);
        }
        .calendar-footer {
            display: flex;
            justify-content: flex-end;
        }
        .subscribe-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--accent);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
            background: #ffffff;
        }
        .subscribe-button:hover {
            color: var(--accent-strong);
            border-color: var(--accent-strong);
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }
        .fc {
            --fc-border-color: var(--line);
            --fc-today-bg-color: #f8fafc;
            --fc-page-bg-color: transparent;
            font-size: 14px;
        }
        .fc .fc-daygrid-day-number {
            padding: 10px;
            font-size: 15px;
            font-weight: 600;
            color: #4b5563;
        }
        .fc .fc-day-other .fc-daygrid-day-number {
            color: #9ca3af;
        }
        .fc .fc-col-header-cell-cushion {
            display: inline-block;
            padding: 10px 0;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--muted);
        }
        .fc .fc-daygrid-event {
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 12px;
        }
        .fc .fc-list-day-cushion {
            background: #f9fafb;
        }
        .fc .fc-list-event-title a {
            color: var(--ink);
        }
        @media (max-width: 900px) {
            .calendar-shell {
                padding: 18px;
            }
            .calendar-controls-top {
                grid-template-columns: 1fr;
            }
            .calendar-actions {
                flex-wrap: wrap;
                justify-content: space-between;
            }
            .view-toggle {
                width: 100%;
                justify-content: space-between;
            }
            .calendar-header {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <main class="calendar-shell">
        <form method="get" class="calendar-controls">
            <div class="calendar-controls-top">
                <label class="search-field">
                    <span class="sr-only">Search for events</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M21 21l-4.3-4.3m1.3-5.2a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <input type="text" name="search" value="<?php echo calendar_e($filters['search']); ?>" placeholder="Search for events">
                </label>
                <div class="calendar-actions">
                    <button type="submit" class="primary-btn">Find Events</button>
                    <div class="view-toggle" role="group" aria-label="Calendar view">
                        <button type="button" data-view="listMonth">List</button>
                        <button type="button" data-view="dayGridMonth">Month</button>
                        <button type="button" data-view="timeGridDay">Day</button>
                    </div>
                </div>
            </div>

            <details class="filter-drawer" <?php echo $hasAdvancedFilters ? 'open' : ''; ?>>
                <summary>Filters</summary>
                <div class="filter-grid">
                    <label>Chapter
                        <select name="chapter_id">
                            <option value="">All</option>
                            <?php foreach ($chapters as $chapter) : ?>
                                <option value="<?php echo (int) $chapter['id']; ?>" <?php echo ($filters['chapter_id'] == $chapter['id']) ? 'selected' : ''; ?>>
                                    <?php echo calendar_e($chapter['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Event Type
                        <select name="event_type">
                            <option value="">All</option>
                            <option value="in_person" <?php echo $filters['event_type'] === 'in_person' ? 'selected' : ''; ?>>In-person</option>
                            <option value="online" <?php echo $filters['event_type'] === 'online' ? 'selected' : ''; ?>>Online</option>
                            <option value="hybrid" <?php echo $filters['event_type'] === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                        </select>
                    </label>
                    <label>Paid/Free
                        <select name="paid">
                            <option value="">All</option>
                            <option value="paid" <?php echo $filters['paid'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="free" <?php echo $filters['paid'] === 'free' ? 'selected' : ''; ?>>Free</option>
                        </select>
                    </label>
                    <label>Upcoming/Past
                        <select name="timeframe">
                            <option value="upcoming" <?php echo $filters['timeframe'] === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                            <option value="past" <?php echo $filters['timeframe'] === 'past' ? 'selected' : ''; ?>>Past</option>
                        </select>
                    </label>
                </div>
            </details>
        </form>

        <div class="calendar-header">
            <div class="calendar-nav">
                <button type="button" class="icon-btn" data-action="prev" aria-label="Previous month">
                    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button type="button" class="icon-btn" data-action="next" aria-label="Next month">
                    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button type="button" class="ghost-btn" data-action="today">This Month</button>
            </div>
            <div>
                <button type="button" class="month-picker" id="monthPickerTrigger" aria-label="Choose month">
                    <span id="calendarTitle">Month</span>
                    <svg class="month-caret" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M7 10l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <input type="month" id="monthPicker" class="month-input" aria-hidden="true" tabindex="-1">
            </div>
        </div>

        <?php if (empty($occurrences)) : ?>
            <div class="calendar-notice">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M7 4v4M17 4v4M4 10h16M5 20h14a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span><?php echo calendar_e($noticeText); ?></span>
            </div>
        <?php endif; ?>

        <div class="calendar-panel">
            <div id="calendar"></div>
        </div>

        <div class="calendar-footer">
            <a class="subscribe-button" href="ics_feed.php?<?php echo calendar_e($filterQuery); ?>">
                Subscribe to calendar
                <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M7 10l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
        </div>
    </main>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var titleEl = document.getElementById('calendarTitle');
    var viewButtons = document.querySelectorAll('[data-view]');
    var actionButtons = document.querySelectorAll('[data-action]');
    var monthPicker = document.getElementById('monthPicker');
    var monthTrigger = document.getElementById('monthPickerTrigger');
    var dateFormatter = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' });

    function formatMonthValue(date) {
        var month = String(date.getMonth() + 1).padStart(2, '0');
        return date.getFullYear() + '-' + month;
    }

    function updateTitle() {
        var date = calendar.getDate();
        titleEl.textContent = dateFormatter.format(date);
        monthPicker.value = formatMonthValue(date);
    }

    function updateViewButtons() {
        var currentView = calendar.view.type;
        viewButtons.forEach(function(button) {
            var isActive = button.dataset.view === currentView;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: false,
        firstDay: 1,
        dayHeaderContent: function(arg) {
            return arg.text.charAt(0);
        },
        height: 700,
        events: '<?php echo calendar_e($feedUrl); ?>',
        datesSet: function() {
            updateTitle();
            updateViewButtons();
        },
        eventDidMount: function(info) {
            if (info.event.extendedProps.status === 'cancelled') {
                info.el.style.opacity = '0.6';
            }
        }
    });
    calendar.render();

    actionButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var action = button.dataset.action;
            if (action === 'prev') {
                calendar.prev();
            } else if (action === 'next') {
                calendar.next();
            } else if (action === 'today') {
                calendar.today();
            }
        });
    });

    viewButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var view = button.dataset.view;
            if (view) {
                calendar.changeView(view);
            }
        });
    });

    monthTrigger.addEventListener('click', function() {
        if (monthPicker.showPicker) {
            monthPicker.showPicker();
        } else {
            monthPicker.focus();
            monthPicker.click();
        }
    });

    monthPicker.addEventListener('change', function() {
        if (!monthPicker.value) {
            return;
        }
        var parts = monthPicker.value.split('-');
        if (parts.length !== 2) {
            return;
        }
        var year = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        if (Number.isNaN(year) || Number.isNaN(month)) {
            return;
        }
        calendar.gotoDate(new Date(year, month - 1, 1));
    });
});
</script>
</body>
</html>
