<?php
require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/mailer.php';

$pdo = calendar_db();
calendar_require_tables($pdo, ['calendar_events', 'calendar_event_rsvps', 'calendar_event_tickets']);
$hasChapterId = calendar_events_has_chapter_id($pdo);
$slug = $_GET['slug'] ?? '';
if ($slug === '') {
    http_response_code(404);
    echo 'Event not found';
    exit;
}
$embed = ($_GET['embed'] ?? '') === '1';

$sql = 'SELECT e.*, m.path AS thumbnail_url, m.title AS thumbnail_name, '
    . ($hasChapterId ? calendar_chapter_name_sql($pdo) . ' AS chapter_name' : 'NULL AS chapter_name')
    . ' FROM calendar_events e LEFT JOIN media m ON m.id = e.media_id ';
if ($hasChapterId) {
    $sql .= 'LEFT JOIN chapters c ON c.id = e.chapter_id ';
}
$sql .= 'WHERE e.slug = :slug LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute(['slug' => $slug]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$user = calendar_current_user();
$message = '';
$error = '';

$now = new DateTime('now', new DateTimeZone($event['timezone']));
$salesCloseAt = $event['sales_close_at'] ? new DateTime($event['sales_close_at'], new DateTimeZone($event['timezone'])) : null;
$salesClosed = $salesCloseAt ? ($now >= $salesCloseAt) : false;
$isCancelled = $event['status'] === 'cancelled';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    calendar_csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'rsvp') {
        calendar_require_login();
        $rsvpStatus = $_POST['rsvp_status'] ?? '';
        $validStatuses = ['going', 'maybe', 'not_going'];
        if ($isCancelled || !$event['rsvp_enabled']) {
            $error = 'RSVPs are not available for this event.';
        } elseif ($salesClosed) {
            $error = 'RSVPs are closed for this event.';
        } elseif ($rsvpStatus === 'clear') {
            // Member is withdrawing their response entirely (back to blank).
            $stmt = $pdo->prepare('DELETE FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id = :user_id');
            $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
            $message = 'Your response has been cleared.';
        } elseif (!in_array($rsvpStatus, $validStatuses, true)) {
            $error = 'Please choose a response.';
        } else {
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $notes = trim($_POST['notes'] ?? '');
            // Attending and Maybe both reserve a spot; Not attending never does.
            $consumesSpot = in_array($rsvpStatus, ['going', 'maybe'], true);
            $capacity = $event['capacity'] ? (int) $event['capacity'] : null;
            if ($consumesSpot && $capacity !== null) {
                // Count spots already taken by OTHER members (going + maybe) plus
                // paid tickets, so changing our own qty/status never double-counts.
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id <> :user_id AND status IN ("going","maybe")');
                $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
                $othersCount = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
                $stmt->execute(['event_id' => $event['id']]);
                $ticketCount = (int) $stmt->fetchColumn();
                if ($othersCount + $ticketCount + $qty > $capacity) {
                    $error = 'Not enough remaining capacity for this response.';
                }
            }
            if ($error === '') {
                $stmt = $pdo->prepare('INSERT INTO calendar_event_rsvps (event_id, user_id, qty, notes, status, created_at) VALUES (:event_id, :user_id, :qty, :notes, :status, NOW()) ON DUPLICATE KEY UPDATE qty = VALUES(qty), notes = VALUES(notes), status = VALUES(status)');
                $stmt->execute([
                    'event_id' => $event['id'],
                    'user_id' => $user['id'],
                    'qty' => $qty,
                    'notes' => $notes,
                    'status' => $rsvpStatus,
                ]);
                $statusMessages = ['going' => 'Attending', 'maybe' => 'Maybe', 'not_going' => 'Not attending'];
                $message = 'Your response has been saved: ' . $statusMessages[$rsvpStatus] . '.';
            }
        }
    }

    if ($action === 'buy_ticket') {
        calendar_require_login();
        if ($isCancelled || !$event['is_paid']) {
            $error = 'Tickets are not available for this event.';
        } elseif ($salesClosed) {
            $error = 'Ticket sales are closed for this event.';
        } else {
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $capacity = $event['capacity'] ? (int) $event['capacity'] : null;
            if ($capacity !== null) {
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status IN ("going","maybe")');
                $stmt->execute(['event_id' => $event['id']]);
                $rsvpCount = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
                $stmt->execute(['event_id' => $event['id']]);
                $ticketCount = (int) $stmt->fetchColumn();
                if ($rsvpCount + $ticketCount + $qty > $capacity) {
                    $error = 'Not enough remaining capacity for tickets.';
                }
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
            $stmt->execute(['id' => (int) $event['ticket_product_id']]);
            $product = $stmt->fetch();
            if (!$product) {
                $error = 'Ticket product not found.';
            } else {
                $successUrl = calendar_base_url('event_view.php?slug=' . urlencode($event['slug']) . '&success=1');
                $cancelUrl = calendar_base_url('event_view.php?slug=' . urlencode($event['slug']) . '&cancel=1');
                $payload = [
                    'mode' => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'customer_email' => $user['email'],
                    'line_items[0][price_data][currency]' => $product['currency'],
                    'line_items[0][price_data][unit_amount]' => (int) $product['price_cents'],
                    'line_items[0][price_data][product_data][name]' => $product['name'],
                    'line_items[0][quantity]' => $qty,
                    'metadata[event_id]' => $event['id'],
                    'metadata[user_id]' => $user['id'],
                    'metadata[qty]' => $qty,
                ];

                $secretKey = calendar_config('stripe.secret_key');
                if (!$secretKey) {
                    $error = 'Stripe is not configured.';
                } else {
                    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode >= 200 && $httpCode < 300) {
                        $session = json_decode($response, true);
                        if (!empty($session['url'])) {
                            header('Location: ' . $session['url']);
                            exit;
                        }
                        $error = 'Stripe checkout could not be created.';
                    } else {
                        $error = 'Stripe error: ' . $response;
                    }
                }
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_rsvps WHERE event_id = :event_id AND status IN ("going","maybe")');
$stmt->execute(['event_id' => $event['id']]);
$rsvpCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM calendar_event_tickets WHERE event_id = :event_id');
$stmt->execute(['event_id' => $event['id']]);
$ticketCount = (int) $stmt->fetchColumn();

$userRsvp = null;
$userTickets = null;
if ($user) {
    $stmt = $pdo->prepare('SELECT * FROM calendar_event_rsvps WHERE event_id = :event_id AND user_id = :user_id');
    $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
    $userRsvp = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT * FROM calendar_event_tickets WHERE event_id = :event_id AND user_id = :user_id');
    $stmt->execute(['event_id' => $event['id'], 'user_id' => $user['id']]);
    $userTickets = $stmt->fetchAll();
}

$backUrl = '/calendar/events_public.php';
$formAction = '/calendar/event_view.php?slug=' . urlencode($event['slug']) . ($embed ? '&embed=1' : '');

// ── Presentation-only derived values ─────────────────────────────────────────
$tz = $event['timezone'];
$startDt = new DateTime($event['start_at'], new DateTimeZone($tz));
$endDt = new DateTime($event['end_at'], new DateTimeZone($tz));
$allDay = (int) ($event['all_day'] ?? 0) === 1;
$sameDay = $startDt->format('Y-m-d') === $endDt->format('Y-m-d');

if ($allDay) {
    $whenMain = $startDt->format('l, j F Y');
    $whenSub = $sameDay ? 'All day' : 'Until ' . $endDt->format('l, j F Y');
} elseif ($sameDay) {
    $whenMain = $startDt->format('l, j F Y');
    $whenSub = $startDt->format('g:i A') . ' – ' . $endDt->format('g:i A');
} else {
    $whenMain = $startDt->format('j M Y, g:i A');
    $whenSub = 'until ' . $endDt->format('j M Y, g:i A');
}

$capacity = (int) ($event['capacity'] ?? 0);
$filled = $rsvpCount + $ticketCount;
$capacityPct = $capacity > 0 ? min(100, (int) round($filled / $capacity * 100)) : 0;
$spotsLeft = $capacity > 0 ? max(0, $capacity - $filled) : null;

$mapUrl = trim((string) ($event['map_url'] ?? ''));
$onlineUrl = trim((string) ($event['online_url'] ?? ''));
$meetingPoint = trim((string) ($event['meeting_point'] ?? ''));
$destination = trim((string) ($event['destination'] ?? ''));
$showOnline = in_array($event['event_type'], ['online', 'hybrid'], true) && $onlineUrl !== '';
$showPhysical = $event['event_type'] !== 'online' && ($meetingPoint !== '' || $destination !== '' || $mapUrl !== '');

$isNational = ($event['scope'] ?? '') === 'NATIONAL';
$accent = $isNational ? '#9e9140' : '#2f7d32';
$accentStrong = $isNational ? '#7e7330' : '#25642a';
$hasImage = !empty($event['thumbnail_url']);
// Hero cover goes into a CSS url() inside a style attribute — a double-nested context.
// Percent-encode the characters that could break out of the url('...') string so
// calendar_e() only has to guard the outer HTML-attribute layer.
$heroBg = str_replace(
    ["\\", "'", '"', '(', ')', ' ', "\n", "\r", "\t"],
    ['%5C', '%27', '%22', '%28', '%29', '%20', '', '', ''],
    (string) ($event['thumbnail_url'] ?? '')
);

// Small inline SVG icon helper (self-contained — no icon font dependency).
$icon = function (string $name): string {
    $paths = [
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin' => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'video' => '<rect x="2" y="5" width="14" height="14" rx="2"/><path d="M22 7l-6 5 6 5V7z"/>',
        'ticket' => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2 2 0 0 0 0-4z"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'calplus' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/>',
        'arrow' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg class="gw-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
};
?>
<?php
ob_start();
?>
<article class="gw-event" style="--accent: <?php echo $accent; ?>; --accent-strong: <?php echo $accentStrong; ?>;">
    <header class="gw-hero<?php echo $hasImage ? ' has-image' : ''; ?>"<?php echo $hasImage ? ' style="background-image:url(\'' . calendar_e($heroBg) . '\')"' : ''; ?>>
        <a class="gw-back" href="<?php echo calendar_e($backUrl); ?>" data-calendar-back><?php echo $icon('arrow'); ?><span>Back to events</span></a>
        <div class="gw-hero-body">
            <div class="gw-badges">
                <span class="gw-badge is-scope"><?php echo calendar_e(calendar_human_scope($event['scope'])); ?></span>
                <?php if (!empty($event['chapter_name'])) : ?>
                    <span class="gw-badge"><?php echo calendar_e($event['chapter_name']); ?></span>
                <?php endif; ?>
                <span class="gw-badge"><?php echo calendar_e(calendar_human_type($event['event_type'])); ?></span>
                <span class="gw-badge <?php echo (int) $event['is_paid'] ? 'is-paid' : 'is-free'; ?>"><?php echo calendar_e(calendar_human_paid((int) $event['is_paid'])); ?></span>
                <?php if ($isCancelled) : ?>
                    <span class="gw-badge is-cancelled">Cancelled</span>
                <?php endif; ?>
            </div>
            <h1 class="gw-title"><?php echo calendar_e($event['title']); ?></h1>
            <p class="gw-when-line"><?php echo $icon('calendar'); ?><?php echo calendar_e($whenMain); ?><?php echo $whenSub !== '' ? ' · ' . calendar_e($whenSub) : ''; ?></p>
        </div>
    </header>

    <div class="gw-body">
        <?php if ($isCancelled) : ?>
            <div class="gw-alert is-danger">This event has been cancelled.<?php echo !empty($event['cancellation_message']) ? ' ' . calendar_e($event['cancellation_message']) : ''; ?></div>
        <?php endif; ?>
        <?php if ($message) : ?>
            <div class="gw-alert is-success"><?php echo calendar_e($message); ?></div>
        <?php endif; ?>
        <?php if ($error) : ?>
            <div class="gw-alert is-danger"><?php echo calendar_e($error); ?></div>
        <?php endif; ?>

        <div class="gw-facts">
            <div class="gw-fact">
                <span class="gw-fact-ic"><?php echo $icon('clock'); ?></span>
                <div>
                    <span class="gw-fact-label">When</span>
                    <span class="gw-fact-value"><?php echo calendar_e($whenMain); ?></span>
                    <?php if ($whenSub !== '') : ?><span class="gw-fact-sub"><?php echo calendar_e($whenSub); ?></span><?php endif; ?>
                    <span class="gw-fact-sub">Timezone: <?php echo calendar_e($tz); ?></span>
                </div>
            </div>

            <?php if ($showOnline || $showPhysical) : ?>
            <div class="gw-fact">
                <span class="gw-fact-ic"><?php echo $icon($showOnline && !$showPhysical ? 'video' : 'pin'); ?></span>
                <div>
                    <span class="gw-fact-label">Where</span>
                    <?php if ($showPhysical) : ?>
                        <?php if ($meetingPoint !== '') : ?><span class="gw-fact-value"><?php echo calendar_e($meetingPoint); ?></span><?php endif; ?>
                        <?php if ($destination !== '') : ?><span class="gw-fact-sub">Destination: <?php echo calendar_e($destination); ?></span><?php endif; ?>
                        <?php if ($mapUrl !== '') : ?><a class="gw-fact-link" href="<?php echo calendar_e($mapUrl); ?>" target="_blank" rel="noopener">Open in Maps <?php echo $icon('external'); ?></a><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($showOnline) : ?>
                        <?php if (!$showPhysical) : ?><span class="gw-fact-value">Online event</span><?php endif; ?>
                        <a class="gw-fact-link" href="<?php echo calendar_e($onlineUrl); ?>" target="_blank" rel="noopener">Join link <?php echo $icon('external'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($capacity > 0) : ?>
            <div class="gw-fact">
                <span class="gw-fact-ic"><?php echo $icon('users'); ?></span>
                <div class="gw-fact-cap">
                    <span class="gw-fact-label">Spots</span>
                    <span class="gw-fact-value"><?php echo (int) $filled; ?> of <?php echo (int) $capacity; ?> filled</span>
                    <span class="gw-capbar"><span class="gw-capbar-fill" style="width: <?php echo (int) $capacityPct; ?>%"></span></span>
                    <span class="gw-fact-sub"><?php echo (int) $spotsLeft; ?> <?php echo $spotsLeft === 1 ? 'spot' : 'spots'; ?> left</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php $descHtml = trim((string) calendar_render_description($event['description'])); ?>
        <?php if ($descHtml !== '') : ?>
            <section class="gw-desc"><?php echo $descHtml; ?></section>
        <?php endif; ?>

        <div class="gw-quick-actions">
            <a class="gw-btn is-ghost" href="/calendar/ics.php?event_id=<?php echo (int) $event['id']; ?>"><?php echo $icon('calplus'); ?>Add to calendar</a>
            <?php if (!empty($event['attachment_path'])) : ?>
                <a class="gw-btn is-ghost" href="<?php echo calendar_e($event['attachment_path']); ?>" download="<?php echo calendar_e($event['attachment_name'] ?: ''); ?>"><?php echo $icon('download'); ?>Download event PDF</a>
            <?php endif; ?>
        </div>

        <?php if ($event['rsvp_enabled'] && !$salesClosed && !$isCancelled) : ?>
            <?php if ($user) : ?>
                <?php
                    $currentStatus = $userRsvp['status'] ?? '';
                    $currentQty = isset($userRsvp['qty']) ? max(1, (int) $userRsvp['qty']) : 1;
                    $currentNotes = $userRsvp['notes'] ?? '';
                    $statusLabels = ['going' => 'Attending', 'maybe' => 'Maybe', 'not_going' => 'Not attending'];
                ?>
                <section class="gw-panel">
                    <h2 class="gw-panel-title">Will you be there?</h2>
                    <?php if ($currentStatus !== '') : ?>
                        <p class="gw-panel-note">Your response: <strong><?php echo calendar_e($statusLabels[$currentStatus] ?? 'Saved'); ?></strong> — change or clear it any time.</p>
                    <?php else : ?>
                        <p class="gw-panel-note">Let the organiser know if you're coming along.</p>
                    <?php endif; ?>
                    <form method="post" class="gw-rsvp-form" action="<?php echo calendar_e($formAction); ?>" data-calendar-embed-form="1">
                        <?php echo calendar_csrf_field(); ?>
                        <input type="hidden" name="action" value="rsvp">
                        <div class="gw-rsvp-fields">
                            <label>Number coming
                                <input type="number" name="qty" min="1" value="<?php echo (int) $currentQty; ?>">
                            </label>
                            <label>Notes
                                <input type="text" name="notes" value="<?php echo calendar_e($currentNotes); ?>" placeholder="Optional">
                            </label>
                        </div>
                        <div class="gw-choices">
                            <button type="submit" name="rsvp_status" value="going" class="gw-choice is-going<?php echo $currentStatus === 'going' ? ' is-active' : ''; ?>">Attending</button>
                            <button type="submit" name="rsvp_status" value="maybe" class="gw-choice is-maybe<?php echo $currentStatus === 'maybe' ? ' is-active' : ''; ?>">Maybe</button>
                            <button type="submit" name="rsvp_status" value="not_going" class="gw-choice is-no<?php echo $currentStatus === 'not_going' ? ' is-active' : ''; ?>">Not attending</button>
                        </div>
                        <?php if ($currentStatus !== '') : ?>
                            <button type="submit" name="rsvp_status" value="clear" class="gw-clear">Clear my response</button>
                        <?php endif; ?>
                    </form>
                </section>
            <?php else : ?>
                <div class="gw-alert is-info">Please <a href="<?php echo calendar_e(calendar_config('login_url', '/login.php')); ?>">log in</a> to RSVP.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($event['is_paid'] && !$salesClosed && !$isCancelled) : ?>
            <?php if ($user) : ?>
                <section class="gw-panel">
                    <h2 class="gw-panel-title"><?php echo $icon('ticket'); ?>Get your ticket</h2>
                    <form method="post" class="gw-ticket-form" action="<?php echo calendar_e($formAction); ?>" data-calendar-embed-form="1">
                        <?php echo calendar_csrf_field(); ?>
                        <input type="hidden" name="action" value="buy_ticket">
                        <label>Qty
                            <input type="number" name="qty" min="1" value="1">
                        </label>
                        <button type="submit" class="gw-btn is-primary">Buy ticket</button>
                    </form>
                </section>
            <?php else : ?>
                <div class="gw-alert is-info">Please <a href="<?php echo calendar_e(calendar_config('login_url', '/login.php')); ?>">log in</a> to buy tickets.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($salesClosed && !$isCancelled) : ?>
            <div class="gw-alert is-warning">Sales and RSVPs are closed for this event.</div>
        <?php endif; ?>

        <?php if ($userTickets && !empty($userTickets)) : ?>
            <section class="gw-panel">
                <h2 class="gw-panel-title">Your tickets</h2>
                <ul class="gw-ticket-list">
                    <?php foreach ($userTickets as $ticket) : ?>
                        <li>
                            <span>Ticket code: <strong><?php echo calendar_e($ticket['ticket_code']); ?></strong></span>
                            <?php if (!empty($ticket['ticket_pdf_url'])) : ?>
                                <a href="<?php echo calendar_e($ticket['ticket_pdf_url']); ?>" target="_blank" rel="noopener">Download</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="gw-btn is-ghost" href="/calendar/refund_request.php?event_id=<?php echo (int) $event['id']; ?>">Request refund</a>
            </section>
        <?php endif; ?>
    </div>
</article>
<?php
$content = ob_get_clean();

// Shared component styles — emitted in both the modal (embed) and standalone
// page so the event looks identical in either surface.
ob_start();
?>
<style>
    .gw-event {
        --line: #e8e3d6;
        --ink: #1c1a17;
        --muted: #6b6a63;
        font-family: "Manrope", "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        color: var(--ink);
        background: #fff;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(28, 26, 23, 0.12);
    }
    .gw-event * { box-sizing: border-box; }
    .gw-ic { width: 18px; height: 18px; flex: 0 0 auto; }
    .gw-hero {
        position: relative;
        padding: 22px 26px 20px;
        min-height: 168px;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        background: linear-gradient(135deg, var(--accent), var(--accent-strong));
        color: #fff;
    }
    .gw-hero.has-image { background-size: cover; background-position: center; min-height: 240px; }
    .gw-hero.has-image::after {
        content: ""; position: absolute; inset: 0;
        background: linear-gradient(to top, rgba(15, 14, 12, 0.82) 0%, rgba(15, 14, 12, 0.30) 55%, rgba(15, 14, 12, 0.12) 100%);
    }
    .gw-hero-body { position: relative; z-index: 2; }
    .gw-back {
        position: relative; z-index: 2; align-self: flex-start;
        display: inline-flex; align-items: center; gap: 6px;
        margin-bottom: auto; padding: 6px 12px 6px 8px;
        background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.28);
        color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
        border-radius: 999px; backdrop-filter: blur(4px); transition: background 0.15s ease;
    }
    .gw-back:hover { background: rgba(255, 255, 255, 0.28); }
    .gw-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
    .gw-badge {
        display: inline-flex; align-items: center;
        padding: 4px 11px; border-radius: 999px;
        background: rgba(255, 255, 255, 0.20); border: 1px solid rgba(255, 255, 255, 0.30);
        color: #fff; font-size: 11.5px; font-weight: 700; letter-spacing: 0.02em;
        backdrop-filter: blur(4px);
    }
    .gw-badge.is-paid { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
    .gw-badge.is-free { background: rgba(255, 255, 255, 0.28); }
    .gw-badge.is-cancelled { background: #fecaca; border-color: #f87171; color: #991b1b; }
    .gw-title { margin: 0; font-size: 27px; line-height: 1.15; font-weight: 800; letter-spacing: -0.01em; text-shadow: 0 1px 12px rgba(0,0,0,0.25); }
    .gw-when-line { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; font-size: 14px; font-weight: 600; opacity: 0.95; }

    .gw-body { padding: 22px 26px 26px; display: flex; flex-direction: column; gap: 18px; }

    .gw-alert { padding: 12px 15px; border-radius: 12px; font-size: 14px; line-height: 1.45; }
    .gw-alert a { color: inherit; font-weight: 700; }
    .gw-alert.is-success { background: #eaf5ea; color: #1f5b22; }
    .gw-alert.is-danger { background: #fdecec; color: #b42318; }
    .gw-alert.is-warning { background: #fff5da; color: #8a6d1a; }
    .gw-alert.is-info { background: #eef4f9; color: #1c4f7a; }

    .gw-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
    .gw-fact {
        display: flex; gap: 12px; align-items: flex-start;
        padding: 14px 15px; border: 1px solid var(--line); border-radius: 14px; background: #fbfaf6;
    }
    .gw-fact-ic {
        display: inline-flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; flex: 0 0 auto; border-radius: 10px;
        background: color-mix(in srgb, var(--accent) 14%, #fff); color: var(--accent-strong);
    }
    .gw-fact > div { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .gw-fact-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
    .gw-fact-value { font-size: 15px; font-weight: 700; color: var(--ink); }
    .gw-fact-sub { font-size: 12.5px; color: var(--muted); }
    .gw-fact-link { display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; font-size: 13px; font-weight: 700; color: var(--accent-strong); text-decoration: none; }
    .gw-fact-link:hover { text-decoration: underline; }
    .gw-fact-link .gw-ic { width: 14px; height: 14px; }
    .gw-fact-cap { flex: 1; }
    .gw-capbar { display: block; height: 7px; border-radius: 999px; background: #e7e2d4; overflow: hidden; margin: 6px 0 4px; }
    .gw-capbar-fill { display: block; height: 100%; border-radius: 999px; background: var(--accent); }

    .gw-desc { font-size: 15px; line-height: 1.65; color: #33312c; }
    .gw-desc p { margin: 0 0 12px; }
    .gw-desc p:last-child { margin-bottom: 0; }
    .gw-desc a { color: var(--accent-strong); }
    .gw-desc img { max-width: 100%; height: auto; border-radius: 10px; }
    .gw-desc ul, .gw-desc ol { margin: 0 0 12px; padding-left: 22px; }

    .gw-quick-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .gw-btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 11px 17px; border-radius: 12px; font-size: 14px; font-weight: 700;
        text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: background 0.15s ease, border-color 0.15s ease;
    }
    .gw-btn.is-primary { background: var(--accent); color: #fff; }
    .gw-btn.is-primary:hover { background: var(--accent-strong); }
    .gw-btn.is-ghost { background: #fff; border-color: var(--line); color: var(--ink); }
    .gw-btn.is-ghost:hover { background: #f6f3ec; border-color: #d8d2c2; }

    .gw-panel { border: 1px solid var(--line); border-radius: 16px; padding: 18px; background: #fbfaf6; }
    .gw-panel-title { display: flex; align-items: center; gap: 8px; margin: 0 0 4px; font-size: 17px; font-weight: 800; }
    .gw-panel-note { margin: 0 0 14px; font-size: 14px; color: var(--muted); }
    .gw-rsvp-form, .gw-ticket-form { display: flex; flex-direction: column; gap: 14px; }
    .gw-ticket-form { flex-direction: row; align-items: flex-end; flex-wrap: wrap; }
    .gw-rsvp-fields { display: flex; gap: 12px; flex-wrap: wrap; }
    .gw-rsvp-fields label, .gw-ticket-form label { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
    .gw-rsvp-fields input, .gw-ticket-form input {
        padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; font-weight: 500; color: var(--ink); background: #fff; min-width: 120px;
    }
    .gw-rsvp-fields input:focus, .gw-ticket-form input:focus { outline: 2px solid color-mix(in srgb, var(--accent) 40%, #fff); outline-offset: 1px; border-color: var(--accent); }
    .gw-choices { display: flex; gap: 8px; flex-wrap: wrap; }
    .gw-choice {
        flex: 1; min-width: 110px; padding: 11px 14px; border-radius: 12px; cursor: pointer;
        border: 1.5px solid var(--line); background: #fff; color: var(--ink);
        font-size: 14px; font-weight: 700; transition: all 0.14s ease;
    }
    .gw-choice:hover { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 7%, #fff); }
    .gw-choice.is-going.is-active { background: #2f7d32; border-color: #2f7d32; color: #fff; }
    .gw-choice.is-maybe.is-active { background: #b8860b; border-color: #b8860b; color: #fff; }
    .gw-choice.is-no.is-active { background: #6b7280; border-color: #6b7280; color: #fff; }
    .gw-clear { align-self: flex-start; background: none; border: none; color: #b42318; font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; text-decoration: underline; }
    .gw-ticket-list { list-style: none; margin: 0 0 14px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .gw-ticket-list li { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 12px; background: #fff; border: 1px solid var(--line); border-radius: 10px; font-size: 14px; }
    .gw-ticket-list a { color: var(--accent-strong); font-weight: 700; text-decoration: none; }

    @media (max-width: 560px) {
        .gw-hero { padding: 18px 18px 16px; }
        .gw-body { padding: 18px 18px 22px; }
        .gw-title { font-size: 23px; }
        .gw-choice { min-width: 0; }
    }
</style>
<?php
$styles = ob_get_clean();
?>
<?php if ($embed) : ?>
<?php echo $styles; ?>
<div class="gw-event-embed"><?php echo $content; ?></div>
<style>
    /* In the modal the component provides its own frame — drop the dialog's default box. */
    .gw-event-embed .gw-event { border-radius: 0; box-shadow: none; }
</style>
<?php else : ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo calendar_e($event['title']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; background: #f4f1e8; padding: 0; font-family: "Manrope", sans-serif; }
        .gw-page { max-width: 760px; margin: 0 auto; padding: 26px 18px 48px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/_back_to_site.php'; ?>
<?php echo $styles; ?>
<main class="gw-page"><?php echo $content; ?></main>
</body>
</html>
<?php endif; ?>
