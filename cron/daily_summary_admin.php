<?php
/**
 * Daily admin summary — the club's morning briefing.
 *
 * Replaces the original three-bullet version (active / pending / due soon),
 * which was true but told nobody anything they could act on.
 *
 * Design rules, so this stays readable at 6am:
 *   - Every block is wrapped in q(). A missing table or column on prod hides
 *     one section instead of killing the whole email.
 *   - Sections that come back empty are omitted entirely, so a quiet Tuesday
 *     is a short email and a busy Monday is a long one. The length itself is
 *     the signal.
 *   - Action items sit at the top; the fun sits underneath; health sits in
 *     the footer where you only read it when something looks off.
 *
 * Schedule: 0 7 * * *  /usr/bin/php /home2/goldwing/cron/daily_summary_admin.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ChapterRepository;
use App\Services\EmailService;
use App\Services\MembershipService;
use App\Services\NotificationService;
use App\Services\PendingRequestsService;
use App\Services\BaseUrlService;

$pdo = db();

/** Run a query block; on any failure return $default so one bad section can't sink the email. */
function q(callable $fn, $default = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[daily_summary] ' . $e->getMessage());
        return $default;
    }
}

// ── Recipients ────────────────────────────────────────────────────────────
$recipients = q(fn() => NotificationService::getAdminEmails(), []) ?: [];
if (!$recipients) {
    // Fall back to the first admin user, same as the original script did.
    $row = q(fn() => $pdo->query(
        "SELECT u.email FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         WHERE r.name = 'admin' AND u.is_active = 1
         ORDER BY u.id ASC LIMIT 1"
    )->fetch());
    if (!empty($row['email'])) {
        $recipients = [$row['email']];
    }
}
if (!$recipients) {
    exit;
}

$chapterSql = q(fn() => ChapterRepository::displayNameSql($pdo, 'c'), 'c.name');
$link = fn(string $path) => BaseUrlService::emailLink($path);

// ── Headline numbers ──────────────────────────────────────────────────────
$scalar = fn(string $sql) => (int) q(fn() => $pdo->query($sql)->fetchColumn(), 0);

// members.status carries the legacy UPPERCASE values on prod (ACTIVE/LAPSED)
// while the checked-in schema declares a lowercase enum that uses "expired"
// for the same state. UPPER() plus matching both spellings reads correctly
// either way — see MembershipStatusService::normalizeStatus.
$active     = $scalar("SELECT COUNT(*) FROM members WHERE UPPER(status) = 'ACTIVE'");
$lapsed     = $scalar("SELECT COUNT(*) FROM members WHERE UPPER(status) IN ('LAPSED', 'EXPIRED')");
$pendingApp = $scalar("SELECT COUNT(*) FROM membership_applications WHERE UPPER(status) = 'PENDING'");
$dueSoon    = $scalar("SELECT COUNT(*) FROM membership_periods
                       WHERE UPPER(status) = 'ACTIVE'
                         AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)");

// ── Movement this week: who renewed, who joined ───────────────────────────
// A period counts as a renewal when the member already had an earlier one;
// otherwise it's a brand-new membership. COALESCE because admin-activated
// periods never get a paid_at.
$movement = q(fn() => $pdo->query(
    "SELECT m.first_name, m.last_name, m.member_number_base, m.member_number_suffix,
            p.term, p.end_date,
            COALESCE(p.paid_at, p.created_at) AS activated_at,
            (SELECT COUNT(*) FROM membership_periods p2
              WHERE p2.member_id = p.member_id AND p2.start_date < p.start_date) AS prior_periods
     FROM membership_periods p
     JOIN members m ON m.id = p.member_id
     WHERE UPPER(p.status) = 'ACTIVE'
       AND COALESCE(p.paid_at, p.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY activated_at DESC
     LIMIT 30"
)->fetchAll(), []) ?: [];

$renewed = array_values(array_filter($movement, fn($r) => (int) $r['prior_periods'] > 0));
$joined  = array_values(array_filter($movement, fn($r) => (int) $r['prior_periods'] === 0));

// ── Notification hub ──────────────────────────────────────────────────────
$hubItems  = q(fn() => PendingRequestsService::all(null, 'pending'), []) ?: [];
$hubTypes  = q(fn() => PendingRequestsService::types(), []) ?: [];
$hubByType = [];
$hubOldest = null;
foreach ($hubItems as $item) {
    $type = $item['type'] ?? 'other';
    $hubByType[$type] = ($hubByType[$type] ?? 0) + 1;
    $at = $item['submitted_at'] ?? null;
    if ($at && ($hubOldest === null || $at < $hubOldest)) {
        $hubOldest = $at;
    }
}
arsort($hubByType);

// ── Store ─────────────────────────────────────────────────────────────────
$storeWeek = q(fn() => $pdo->query(
    "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
     FROM store_orders
     WHERE payment_status = 'paid'
       AND voided_at IS NULL
       AND COALESCE(paid_at, created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetch(), null);

$awaitingDespatch = q(fn() => $pdo->query(
    "SELECT so.order_number, so.total, so.created_at,
            COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), so.customer_name, 'Guest') AS who
     FROM store_orders so
     LEFT JOIN members m ON m.id = so.member_id
     WHERE so.payment_status = 'paid'
       AND so.fulfillment_status <> 'fulfilled'
       AND so.order_status NOT IN ('cancelled', 'completed')
       AND so.voided_at IS NULL
     ORDER BY so.created_at ASC
     LIMIT 8"
)->fetchAll(), []) ?: [];

// ── Leaderboards ──────────────────────────────────────────────────────────
// Admin accounts are excluded — a board Pat wins every day isn't a board.
$topMembers = q(fn() => $pdo->query(
    "SELECT COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), u.name) AS who,
            COUNT(*) AS hits
     FROM user_logins ul
     JOIN users u ON u.id = ul.user_id
     LEFT JOIN members m ON m.id = u.member_id
     WHERE ul.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
       AND u.id NOT IN (
           SELECT ur.user_id FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id WHERE r.name = 'admin'
       )
     GROUP BY u.id
     ORDER BY hits DESC
     LIMIT 3"
)->fetchAll(), []) ?: [];

// Wings reads come from activity_log (see read_wings.php / download_wings.php).
// The board only fills from the day that logging deploys — before then it's empty.
// NB: don't alias this COUNT as `reads` — it's a reserved word in MySQL 8 and
// the resulting syntax error is invisible behind q().
$topWings = q(fn() => $pdo->query(
    "SELECT COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), 'Unknown') AS who,
            COUNT(*) AS read_count
     FROM activity_log al
     JOIN members m ON m.id = al.member_id
     WHERE al.action IN ('wings.read', 'wings.download')
       AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY m.id
     ORDER BY read_count DESC
     LIMIT 3"
)->fetchAll(), []) ?: [];

$wingsReadsWeek = $scalar(
    "SELECT COUNT(*) FROM activity_log
     WHERE action IN ('wings.read', 'wings.download')
       AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);

$latestWings = q(fn() => $pdo->query(
    "SELECT title, downloads FROM wings_issues ORDER BY is_latest DESC, published_at DESC LIMIT 1"
)->fetch(), null);

// ── Follow-up: longest-lapsed members ─────────────────────────────────────
// Ordered by how long ago their last period ended. Members with no period on
// record sort last so the list stays actionable.
$lapsedFollowUp = q(fn() => $pdo->query(
    "SELECT m.id, m.first_name, m.last_name, m.email, m.phone,
            m.member_number_base, m.member_number_suffix,
            {$chapterSql} AS chapter_name,
            MAX(p.end_date) AS last_end
     FROM members m
     LEFT JOIN membership_periods p ON p.member_id = m.id
     LEFT JOIN chapters c ON c.id = m.chapter_id
     WHERE UPPER(m.status) IN ('LAPSED', 'EXPIRED')
     GROUP BY m.id
     ORDER BY last_end IS NULL, last_end ASC
     LIMIT 5"
)->fetchAll(), []) ?: [];

// ── What's on ─────────────────────────────────────────────────────────────
// ponytail: start_at only, so recurring events show their next stored instance
// rather than every expansion. Upgrade to calendar_occurrences if that bites.
$events = q(fn() => $pdo->query(
    "SELECT e.title, e.start_at, e.scope,
            (SELECT COUNT(*) FROM calendar_event_rsvps r
              WHERE r.event_id = e.id AND r.status = 'going') AS going
     FROM calendar_events e
     WHERE e.status = 'published'
       AND e.start_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)
     ORDER BY e.start_at ASC
     LIMIT 6"
)->fetchAll(), []) ?: [];

// ── Celebrations ──────────────────────────────────────────────────────────
// Month-day matching over the next 7 days, computed in PHP so the year
// boundary and leap years look after themselves.
$weekMonthDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekMonthDays[] = date('md', strtotime("+{$i} day"));
}
$mdList = "'" . implode("','", $weekMonthDays) . "'";

$birthdays = q(fn() => $pdo->query(
    "SELECT first_name, last_name, date_of_birth
     FROM members
     WHERE UPPER(status) = 'ACTIVE' AND date_of_birth IS NOT NULL
       AND DATE_FORMAT(date_of_birth, '%m%d') IN ({$mdList})
     ORDER BY DATE_FORMAT(date_of_birth, '%m%d') ASC
     LIMIT 10"
)->fetchAll(), []) ?: [];

$anniversaryRows = q(fn() => $pdo->query(
    "SELECT first_name, last_name, join_date
     FROM members
     WHERE UPPER(status) = 'ACTIVE' AND join_date IS NOT NULL
       AND DATE_FORMAT(join_date, '%m%d') IN ({$mdList})
     ORDER BY join_date ASC
     LIMIT 40"
)->fetchAll(), []) ?: [];

// Only the round milestones — 5, 10, 15 years and up. Everything else is noise.
$milestones = [];
foreach ($anniversaryRows as $row) {
    $years = (int) date('Y') - (int) date('Y', strtotime($row['join_date']));
    if ($years >= 5 && $years % 5 === 0) {
        $milestones[] = $row + ['years' => $years];
    }
}

// ── Health ────────────────────────────────────────────────────────────────
$emailsSent   = $scalar("SELECT COUNT(*) FROM email_log WHERE sent = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$emailsFailed = $scalar("SELECT COUNT(*) FROM email_log WHERE sent = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$failedLogins = $scalar("SELECT COUNT(*) FROM activity_log WHERE action = 'security.login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$stripeErrors = $scalar("SELECT COUNT(*) FROM stripe_errors WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");

// ── Rendering helpers ─────────────────────────────────────────────────────
$GOLD  = '#9e9140';
$CREAM = '#f4f1e8';
$LINE  = '#e8e3d7';

$memberNo = function (array $r): string {
    if (!isset($r['member_number_base'])) {
        return '';
    }
    return q(fn() => MembershipService::displayMembershipNumber(
        (int) $r['member_number_base'],
        (int) ($r['member_number_suffix'] ?? 0)
    ), (string) $r['member_number_base']) ?: '';
};

$name = fn(array $r) => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

/** "1 order" / "3 orders" — plural only handles the regular -s case, which is all we need here. */
$plural = fn(int $n, string $word, ?string $many = null) => $n . ' ' . ($n === 1 ? $word : ($many ?? $word . 's'));

/** "3 days", "2 months" — how long ago a timestamp was, in plain words. */
$ago = function (?string $when): string {
    if (!$when) {
        return 'unknown';
    }
    $days = (int) floor((time() - strtotime($when)) / 86400);
    if ($days < 1)   return 'today';
    if ($days === 1) return '1 day';
    if ($days < 60)  return $days . ' days';
    $months = (int) round($days / 30);
    if ($months < 24) return $months . ' months';
    return round($days / 365, 1) . ' years';
};

$tile = function (string $label, $value, string $tone = '#374151') use ($CREAM, $LINE) {
    return '<td width="33%" style="padding:4px;" valign="top">'
        . '<div style="background:' . $CREAM . ';border:1px solid ' . $LINE . ';border-radius:10px;padding:14px 10px;text-align:center;">'
        . '<div style="font-size:26px;font-weight:700;line-height:1.1;color:' . $tone . ';">' . e((string) $value) . '</div>'
        . '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;margin-top:4px;">' . e($label) . '</div>'
        . '</div></td>';
};

$heading = fn(string $text) => '<h3 style="margin:28px 0 10px;font-size:15px;color:' . $GOLD
    . ';border-bottom:2px solid ' . $LINE . ';padding-bottom:6px;">' . $text . '</h3>';

$li = fn(string $html) => '<li style="margin-bottom:5px;">' . $html . '</li>';

// ── Body ──────────────────────────────────────────────────────────────────
$today  = date('l j F Y');
$body   = [];

$body[] = '<p style="margin:0 0 4px;font-size:19px;font-weight:600;color:#111827;">Morning briefing</p>';
$body[] = '<p style="margin:0 0 20px;color:#6b7280;font-size:14px;">' . e($today) . '</p>';

// Stat tiles
$body[] = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:4px;"><tr>'
    . $tile('Active members', $active)
    . $tile('Renewed (7d)', count($renewed), count($renewed) ? '#15803d' : '#374151')
    . $tile('New members (7d)', count($joined), count($joined) ? '#15803d' : '#374151')
    . '</tr><tr>'
    . $tile('Awaiting action', count($hubItems), count($hubItems) ? '#b45309' : '#374151')
    . $tile('Due in 60 days', $dueSoon)
    . $tile('Lapsed', $lapsed, $lapsed ? '#b91c1c' : '#374151')
    . '</tr></table>';

// ── Needs you ─────────────────────────────────────────────────────────────
$actions = [];

if ($hubItems) {
    $breakdown = [];
    foreach ($hubByType as $type => $count) {
        $label = $hubTypes[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
        $breakdown[] = e($label) . ' &times;' . $count;
    }
    $actions[] = $li(
        '<strong>' . $plural(count($hubItems), 'item') . ' in the notification hub</strong>'
        . ($hubOldest ? ' <span style="color:#6b7280;">— oldest waiting ' . e($ago($hubOldest)) . '</span>' : '')
        . '<br><span style="font-size:13px;color:#6b7280;">' . implode(' &middot; ', $breakdown) . '</span>'
        . ($link('/admin/requests/index.php')
            ? '<br><a href="' . e($link('/admin/requests/index.php')) . '" style="font-size:13px;color:' . $GOLD . ';">Open the hub &rarr;</a>'
            : '')
    );
}

if ($awaitingDespatch) {
    $oldest = $awaitingDespatch[0];
    $actions[] = $li(
        '<strong>' . $plural(count($awaitingDespatch), 'store order') . ' paid but not sent</strong>'
        . ' <span style="color:#6b7280;">— oldest has been waiting ' . e($ago($oldest['created_at'])) . '</span>'
        . ($link('/admin/store/orders.php')
            ? '<br><a href="' . e($link('/admin/store/orders.php')) . '" style="font-size:13px;color:' . $GOLD . ';">Open store orders &rarr;</a>'
            : '')
    );
}

// Pending applications deliberately aren't listed here — the notification hub
// already surfaces them as "Membership Application", and saying it twice made
// the action list look longer than the actual workload.

if ($stripeErrors > 0) {
    $actions[] = $li('<strong style="color:#b91c1c;">' . $plural($stripeErrors, 'Stripe error')
        . ' in the last 24 hours</strong>'
        . ' <span style="color:#6b7280;">— a payment may have failed silently</span>');
}

if ($actions) {
    $body[] = $heading('&#9889; Needs you');
    $body[] = '<ul style="margin:0;padding-left:20px;">' . implode('', $actions) . '</ul>';
} else {
    $body[] = $heading('&#9989; Nothing needs you');
    $body[] = '<p style="margin:0;color:#6b7280;">Empty hub, no unsent orders, no payment errors. Go for a ride.</p>';
}

// ── Good news ─────────────────────────────────────────────────────────────
if ($renewed || $joined) {
    $body[] = $heading('&#127881; This week');

    if ($renewed) {
        $names = [];
        foreach ($renewed as $r) {
            $no = $memberNo($r);
            $names[] = e($name($r))
                . ($no ? ' <span style="color:#9ca3af;">#' . e($no) . '</span>' : '')
                . ' <span style="color:#6b7280;">(' . e((string) $r['term']) . ')</span>';
        }
        $body[] = '<p style="margin:0 0 8px;"><strong>Renewed (' . count($renewed) . '):</strong> '
            . implode(', ', $names) . '</p>';
    }

    if ($joined) {
        $names = [];
        foreach ($joined as $r) {
            $no = $memberNo($r);
            $names[] = e($name($r)) . ($no ? ' <span style="color:#9ca3af;">#' . e($no) . '</span>' : '');
        }
        $body[] = '<p style="margin:0 0 8px;"><strong>Joined (' . count($joined) . '):</strong> '
            . implode(', ', $names) . '</p>';
    }
}

// ── Leaderboard ───────────────────────────────────────────────────────────
if ($topMembers || $topWings || $wingsReadsWeek) {
    $body[] = $heading('&#127942; Leaderboard');
    $rows = [];

    if ($topMembers) {
        $medals = ['&#129351;', '&#129352;', '&#129353;'];
        $parts = [];
        foreach ($topMembers as $i => $m) {
            $parts[] = ($medals[$i] ?? '') . ' ' . e($m['who']) . ' <span style="color:#6b7280;">(' . (int) $m['hits'] . ')</span>';
        }
        $rows[] = $li('<strong>Most active members</strong> <span style="color:#9ca3af;font-size:13px;">— logins, 7 days</span><br>'
            . implode(' &nbsp; ', $parts));
    }

    if ($topWings) {
        $parts = [];
        foreach ($topWings as $w) {
            $parts[] = e($w['who']) . ' <span style="color:#6b7280;">(' . (int) $w['read_count'] . ')</span>';
        }
        $rows[] = $li('<strong>Top Wings readers</strong> <span style="color:#9ca3af;font-size:13px;">— 30 days</span><br>'
            . implode(' &nbsp; ', $parts));
    }

    if ($wingsReadsWeek || $latestWings) {
        $line = '<strong>Wings</strong> — ' . $plural($wingsReadsWeek, 'read') . ' this week';
        if ($latestWings) {
            $line .= '; latest issue <em>' . e((string) $latestWings['title']) . '</em> on '
                . (int) ($latestWings['downloads'] ?? 0) . ' downloads';
        }
        $rows[] = $li($line);
    }

    $body[] = '<ul style="margin:0;padding-left:20px;">' . implode('', $rows) . '</ul>';
}

// ── Store ─────────────────────────────────────────────────────────────────
if ($storeWeek && (int) $storeWeek['orders'] > 0) {
    $body[] = $heading('&#128722; Store');
    $body[] = '<p style="margin:0;"><strong>' . $plural((int) $storeWeek['orders'], 'order')
        . '</strong> paid in the last 7 days, totalling <strong>$'
        . e(number_format((float) $storeWeek['revenue'], 2)) . '</strong>'
        . ($awaitingDespatch ? ' &middot; <span style="color:#b45309;">' . count($awaitingDespatch) . ' still to send</span>' : '')
        . '</p>';
}

// ── Follow-up ─────────────────────────────────────────────────────────────
if ($lapsedFollowUp) {
    $body[] = $heading('&#128222; Longest-lapsed members to chase');
    $body[] = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;border-collapse:collapse;">';
    $body[] = '<tr style="background:' . $CREAM . ';">'
        . '<th align="left" style="padding:6px 8px;font-size:11px;text-transform:uppercase;color:#6b7280;">Member</th>'
        . '<th align="left" style="padding:6px 8px;font-size:11px;text-transform:uppercase;color:#6b7280;">Chapter</th>'
        . '<th align="left" style="padding:6px 8px;font-size:11px;text-transform:uppercase;color:#6b7280;">Lapsed</th>'
        . '<th align="left" style="padding:6px 8px;font-size:11px;text-transform:uppercase;color:#6b7280;">Contact</th>'
        . '</tr>';
    foreach ($lapsedFollowUp as $m) {
        $no = $memberNo($m);
        $contact = trim((string) ($m['phone'] ?? '')) ?: trim((string) ($m['email'] ?? '')) ?: '—';
        $body[] = '<tr style="border-bottom:1px solid ' . $LINE . ';">'
            . '<td style="padding:7px 8px;">' . e($name($m))
            . ($no ? ' <span style="color:#9ca3af;">#' . e($no) . '</span>' : '') . '</td>'
            . '<td style="padding:7px 8px;color:#6b7280;">' . e((string) ($m['chapter_name'] ?? '—')) . '</td>'
            . '<td style="padding:7px 8px;color:#6b7280;">' . e($ago($m['last_end'])) . '</td>'
            . '<td style="padding:7px 8px;color:#6b7280;">' . e($contact) . '</td>'
            . '</tr>';
    }
    $body[] = '</table>';
}

// ── What's on ─────────────────────────────────────────────────────────────
if ($events) {
    $body[] = $heading('&#128197; Coming up');
    $rows = [];
    foreach ($events as $ev) {
        $when = date('D j M, g:ia', strtotime((string) $ev['start_at']));
        $rows[] = $li('<strong>' . e((string) $ev['title']) . '</strong> — ' . e($when)
            . ((int) $ev['going'] > 0 ? ' <span style="color:#6b7280;">(' . (int) $ev['going'] . ' going)</span>' : ''));
    }
    $body[] = '<ul style="margin:0;padding-left:20px;">' . implode('', $rows) . '</ul>';
}

// ── Celebrations ──────────────────────────────────────────────────────────
if ($birthdays || $milestones) {
    $body[] = $heading('&#127874; Worth a mention');
    $rows = [];
    if ($birthdays) {
        $parts = [];
        foreach ($birthdays as $b) {
            $parts[] = e($name($b)) . ' <span style="color:#6b7280;">(' . e(date('j M', strtotime($b['date_of_birth']))) . ')</span>';
        }
        $rows[] = $li('<strong>Birthdays this week:</strong> ' . implode(', ', $parts));
    }
    if ($milestones) {
        $parts = [];
        foreach ($milestones as $m) {
            $parts[] = e($name($m)) . ' <span style="color:#6b7280;">(' . (int) $m['years'] . ' years)</span>';
        }
        $rows[] = $li('<strong>Membership milestones:</strong> ' . implode(', ', $parts));
    }
    $body[] = '<ul style="margin:0;padding-left:20px;">' . implode('', $rows) . '</ul>';
}

// ── Health footer ─────────────────────────────────────────────────────────
$health = $plural($emailsSent, 'email') . ' sent'
    . ($emailsFailed ? ', <strong style="color:#b91c1c;">' . $emailsFailed . ' failed</strong>' : '')
    . ' &middot; ' . $plural($failedLogins, 'failed login')
    . ' &middot; ' . $plural($pendingApp, 'pending application');

$body[] = '<p style="margin:28px 0 0;padding-top:14px;border-top:1px solid ' . $LINE
    . ';font-size:12px;color:#9ca3af;">Last 24 hours: ' . $health . '</p>';

// ── Send ──────────────────────────────────────────────────────────────────
// Subject stays ASCII on purpose: SmtpMailer writes the Subject header raw,
// with no MIME encoding, so anything non-ASCII risks being mangled in transit.
$subjectBits = [];
if (count($hubItems)) $subjectBits[] = count($hubItems) . ' to action';
if (count($renewed))  $subjectBits[] = count($renewed) . ' renewed';
if (count($joined))   $subjectBits[] = count($joined) . ' joined';
if (!$subjectBits)    $subjectBits[] = 'all quiet';

$subject = 'Goldwing Daily - ' . date('D j M') . ' - ' . implode(', ', $subjectBits);
$html    = implode('', $body);

foreach ($recipients as $to) {
    EmailService::send($to, $subject, $html, ['is_mandatory' => true]);
}

q(fn() => $pdo->prepare(
    "INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_daily_summary_run', NOW())
     ON DUPLICATE KEY UPDATE setting_value = NOW()"
)->execute());
