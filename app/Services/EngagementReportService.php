<?php
namespace App\Services;

use DateTime;
use PDO;
use Throwable;

/**
 * Read-only queries behind /admin/reports/ (Member Engagement).
 *
 * Every section runs inside q(): a missing table or column on the live DB
 * blanks that one section and records the error for the page banner instead
 * of taking the whole report down (same rule as the Sunday summary cron and
 * the Audit Hub). Admin-role accounts are excluded from member figures and
 * leaderboards — a board Pat wins isn't a board.
 *
 * Everything is computed on demand. At a few hundred members that is a few
 * milliseconds of SQL; there is no rollup table and no cron dependency.
 */
class EngagementReportService
{
    public const RANGES = [
        '7d'     => ['label' => 'Last 7 days',    'days' => 7],
        '30d'    => ['label' => 'Last 30 days',   'days' => 30],
        '90d'    => ['label' => 'Last 90 days',   'days' => 90],
        '365d'   => ['label' => 'Last 12 months', 'days' => 365],
        'launch' => ['label' => 'Since launch',   'days' => null],
    ];

    /** Page views by one user closer together than this belong to one visit. */
    public const VISIT_GAP_SECONDS = 1800;
    public const NEWCOMER_DAYS = 90;
    public const DORMANT_DAYS = 90;
    public const RETENTION_MONTHS = 13;

    private const NOT_ADMIN = "u.id NOT IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.name = 'admin')";
    private const WHO = "COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), u.name)";

    /** @var array<string, string> section => error message, reset per build() */
    private static array $errors = [];
    private static array $schemaCache = [];

    public static function build(string $rangeKey): array
    {
        self::$errors = [];
        $range = self::RANGES[$rangeKey] ?? self::RANGES['30d'];
        $days = $range['days'];
        $since = $days ? date('Y-m-d H:i:s', strtotime("-{$days} days")) : null;
        $prevSince = $days ? date('Y-m-d H:i:s', strtotime('-' . ($days * 2) . ' days')) : null;
        $hasPageViews = self::tableExists('page_views');

        $out = [
            'range'          => ['key' => $rangeKey, 'label' => $range['label'], 'days' => $days, 'since' => $since],
            'has_page_views' => $hasPageViews,
            'headline'       => self::headline($since, $prevSince, $hasPageViews),
            'logins_series'  => self::loginsSeries($since, $days),
            'areas'          => $hasPageViews ? self::areas($since) : [],
            'hours'          => self::hours($since, $hasPageViews),
            'leaderboards'   => self::leaderboards($since),
            'newcomers'      => self::newcomers($hasPageViews),
            'since_launch'   => self::sinceLaunch(),
            'dormant'        => self::dormant(),
            'chapters'       => self::chapters($since ?? '1970-01-01 00:00:00'),
        ];
        $out['errors'] = self::$errors;
        return $out;
    }

    /** Drop page views older than RETENTION_MONTHS. Runs on report load — no cron needed at this volume. */
    public static function prune(): void
    {
        if (!self::tableExists('page_views')) {
            return;
        }
        self::q('prune', function () {
            Database::connection()->exec('DELETE FROM page_views WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_MONTHS . ' MONTH)');
            return true;
        }, false);
    }

    // ── Pure helpers (unit-tested in tests/test_engagement_report.php) ───────

    /**
     * Group page views into visits: consecutive views by one user with no gap
     * over $gap seconds. Rows must be sorted by user_id then ts.
     * @param array<int, array{user_id:int|string, ts:int|string}> $rows
     * @return array{visits:int, pages:int, durations:int[]} durations in seconds, multi-page visits only
     */
    public static function stitchVisits(array $rows, int $gap = self::VISIT_GAP_SECONDS): array
    {
        $visits = 0;
        $pages = 0;
        $durations = [];
        $user = null;
        $start = 0;
        $last = null;
        $count = 0;

        $close = function () use (&$visits, &$durations, &$start, &$last, &$count): void {
            if ($count === 0) {
                return;
            }
            $visits++;
            if ($count > 1) {
                $durations[] = $last - $start;
            }
            $count = 0;
        };

        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $ts = (int) $row['ts'];
            if ($uid !== $user || $last === null || $ts - $last > $gap) {
                $close();
                $user = $uid;
                $start = $ts;
            }
            $last = $ts;
            $count++;
            $pages++;
        }
        $close();

        return ['visits' => $visits, 'pages' => $pages, 'durations' => $durations];
    }

    public static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Current run of consecutive ISO weeks with a login, from a list of
     * (member, ISO yearweek) pairs. A streak must reach this week or last
     * week to count as "current". Returns the top $limit, min 2 weeks.
     * @param array<int, array{member_id:int|string, who:string, wk:int|string}> $rows
     */
    public static function streaksFromWeeks(array $rows, ?int $nowYearWeek = null, int $limit = 5): array
    {
        $index = fn(int $yw): int => intdiv(strtotime(sprintf('%04dW%02d', intdiv($yw, 100), $yw % 100)), 604800);
        $nowIdx = $index($nowYearWeek ?? (int) date('oW'));

        $byMember = [];
        foreach ($rows as $r) {
            $byMember[(int) $r['member_id']]['who'] = $r['who'];
            $byMember[(int) $r['member_id']]['weeks'][] = $index((int) $r['wk']);
        }

        $out = [];
        foreach ($byMember as $memberId => $data) {
            $weeks = array_values(array_unique($data['weeks']));
            rsort($weeks);
            if (!$weeks || $weeks[0] < $nowIdx - 1) {
                continue;
            }
            $streak = 1;
            $prev = $weeks[0];
            foreach (array_slice($weeks, 1) as $wk) {
                if ($wk !== $prev - 1) {
                    break;
                }
                $streak++;
                $prev = $wk;
            }
            if ($streak >= 2) {
                $out[] = ['member_id' => $memberId, 'who' => $data['who'], 'n' => $streak];
            }
        }
        usort($out, fn($a, $b) => [$b['n'], $a['who']] <=> [$a['n'], $b['who']]);
        return array_slice($out, 0, $limit);
    }

    /** Fill missing days/weeks with zero rows so the chart has no holes. */
    public static function fillSeries(array $rows, bool $daily, ?string $since, ?int $now = null): array
    {
        if (!$rows && !$since) {
            return [];
        }
        $now = $now ?? time();
        $byBucket = array_column($rows, null, 'bucket');
        $start = $since ? strtotime(date('Y-m-d', strtotime($since))) : strtotime((string) $rows[0]['bucket']);
        if (!$daily) {
            $start -= ((int) date('N', $start) - 1) * 86400; // back to Monday
            $start = strtotime(date('Y-m-d', $start));
        }
        $out = [];
        for ($t = $start; $t <= $now; $t = strtotime($daily ? '+1 day' : '+1 week', $t)) {
            $key = date('Y-m-d', $t);
            $out[] = [
                'bucket'  => $key,
                'logins'  => (int) ($byBucket[$key]['logins'] ?? 0),
                'members' => (int) ($byBucket[$key]['members'] ?? 0),
            ];
        }
        return $out;
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    private static function headline(?string $since, ?string $prevSince, bool $hasPageViews): array
    {
        $pdo = Database::connection();
        $h = [
            'active_members' => 0, 'members_logged_in' => 0, 'members_logged_in_prev' => null,
            'visits' => 0, 'pages_per_visit' => null, 'median_visit_minutes' => null,
            'wings_reads' => 0, 'online_now' => 0, 'mobile_share' => null, 'first_view_at' => null,
        ];

        $h['active_members'] = (int) self::q('active_members', fn() => $pdo->query("SELECT COUNT(*) FROM members WHERE UPPER(status) = 'ACTIVE'")->fetchColumn(), 0);

        $loggedIn = function (?string $from, ?string $to) use ($pdo): int {
            $sql = "SELECT COUNT(DISTINCT m.id) FROM user_logins ul JOIN users u ON u.id = ul.user_id JOIN members m ON m.id = u.member_id WHERE UPPER(m.status) = 'ACTIVE' AND " . self::NOT_ADMIN;
            $params = [];
            if ($from) {
                $sql .= ' AND ul.created_at >= :from';
                $params['from'] = $from;
            }
            if ($to) {
                $sql .= ' AND ul.created_at < :to';
                $params['to'] = $to;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        $h['members_logged_in'] = self::q('members_logged_in', fn() => $loggedIn($since, null), 0);
        if ($since && $prevSince) {
            $h['members_logged_in_prev'] = self::q('members_logged_in_prev', fn() => $loggedIn($prevSince, $since), null);
        }

        $h['wings_reads'] = (int) self::q('wings_reads', function () use ($pdo, $since) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action IN ('wings.read', 'wings.download')" . ($since ? ' AND created_at >= :since' : ''));
            $stmt->execute($since ? ['since' => $since] : []);
            return $stmt->fetchColumn();
        }, 0);

        $h['online_now'] = (int) self::q('online_now', fn() => $pdo->query('SELECT COUNT(DISTINCT user_id) FROM sessions WHERE user_id IS NOT NULL AND last_activity_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)')->fetchColumn(), 0);

        $h['mobile_share'] = self::q('mobile_share', function () use ($pdo, $since) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(user_agent LIKE '%Mobi%' OR user_agent LIKE '%Android%') AS mobile FROM activity_log WHERE action = 'security.login_success' AND actor_type = 'member'" . ($since ? ' AND created_at >= :since' : ''));
            $stmt->execute($since ? ['since' => $since] : []);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($row && (int) $row['total'] > 0) ? (int) round(100 * (int) $row['mobile'] / (int) $row['total']) : null;
        }, null);

        if ($hasPageViews) {
            $visits = self::q('visits', function () use ($pdo, $since) {
                $stmt = $pdo->prepare('SELECT user_id, UNIX_TIMESTAMP(created_at) AS ts FROM page_views WHERE is_admin = 0' . ($since ? ' AND created_at >= :since' : '') . ' ORDER BY user_id, created_at');
                $stmt->execute($since ? ['since' => $since] : []);
                return self::stitchVisits($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }, ['visits' => 0, 'pages' => 0, 'durations' => []]);
            $median = self::median($visits['durations']);
            $h['visits'] = $visits['visits'];
            $h['pages_per_visit'] = $visits['visits'] > 0 ? round($visits['pages'] / $visits['visits'], 1) : null;
            $h['median_visit_minutes'] = $median !== null ? round($median / 60, 1) : null;
            $h['first_view_at'] = self::q('first_view_at', fn() => $pdo->query('SELECT MIN(created_at) FROM page_views')->fetchColumn() ?: null, null);
        }
        return $h;
    }

    private static function loginsSeries(?string $since, ?int $days): array
    {
        $daily = $days !== null && $days <= 30;
        $bucket = $daily ? 'DATE(ul.created_at)' : 'DATE(DATE_SUB(ul.created_at, INTERVAL WEEKDAY(ul.created_at) DAY))';
        $rows = self::q('logins_series', function () use ($since, $bucket) {
            $stmt = Database::connection()->prepare("SELECT {$bucket} AS bucket, COUNT(*) AS logins, COUNT(DISTINCT ul.user_id) AS members FROM user_logins ul JOIN users u ON u.id = ul.user_id WHERE u.member_id IS NOT NULL AND " . self::NOT_ADMIN . ($since ? ' AND ul.created_at >= :since' : '') . ' GROUP BY bucket ORDER BY bucket');
            $stmt->execute($since ? ['since' => $since] : []);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }, []);
        return ['daily' => $daily, 'rows' => self::fillSeries($rows, $daily, $since)];
    }

    private static function areas(?string $since): array
    {
        return self::q('areas', function () use ($since) {
            $stmt = Database::connection()->prepare("SELECT area, COUNT(*) AS views, COUNT(DISTINCT user_id) AS members FROM page_views WHERE is_admin = 0 AND area <> 'admin'" . ($since ? ' AND created_at >= :since' : '') . ' GROUP BY area ORDER BY views DESC');
            $stmt->execute($since ? ['since' => $since] : []);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['label'] = PageViewLogger::label((string) $r['area']);
                $r['views'] = (int) $r['views'];
                $r['members'] = (int) $r['members'];
            }
            unset($r);
            return $rows;
        }, []);
    }

    /** Busiest hours of the day in the site's timezone (page views when there are any, logins until then). */
    private static function hours(?string $since, bool $hasPageViews): array
    {
        return self::q('hours', function () use ($since, $hasPageViews) {
            $pdo = Database::connection();
            $shift = self::hourShiftMinutes();
            $counts = array_fill(0, 24, 0);
            $source = 'logins';
            $params = $since ? ['since' => $since] : [];

            if ($hasPageViews) {
                $stmt = $pdo->prepare("SELECT HOUR(DATE_ADD(created_at, INTERVAL {$shift} MINUTE)) AS h, COUNT(*) AS c FROM page_views WHERE is_admin = 0" . ($since ? ' AND created_at >= :since' : '') . ' GROUP BY h');
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $counts[(int) $r['h']] = (int) $r['c'];
                }
                if (array_sum($counts) > 0) {
                    $source = 'page views';
                }
            }
            if ($source === 'logins') {
                $stmt = $pdo->prepare("SELECT HOUR(DATE_ADD(ul.created_at, INTERVAL {$shift} MINUTE)) AS h, COUNT(*) AS c FROM user_logins ul JOIN users u ON u.id = ul.user_id WHERE u.member_id IS NOT NULL AND " . self::NOT_ADMIN . ($since ? ' AND ul.created_at >= :since' : '') . ' GROUP BY h');
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $counts[(int) $r['h']] = (int) $r['c'];
                }
            }
            return ['source' => $source, 'counts' => $counts];
        }, ['source' => 'logins', 'counts' => array_fill(0, 24, 0)]);
    }

    private static function leaderboards(?string $since): array
    {
        $pdo = Database::connection();
        $params = $since ? ['since' => $since] : [];
        $sinceSql = fn(string $col): string => $since ? " AND {$col} >= :since" : '';
        $run = function (string $section, string $sql, array $p) use ($pdo): array {
            return self::q($section, function () use ($pdo, $sql, $p) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }, []);
        };
        // GROUP BY both primary keys so ONLY_FULL_GROUP_BY is happy with the
        // name expression, which mixes members.* and users.name.
        return [
            'logins' => ['title' => 'Most active', 'unit' => 'logins', 'rows' => $run('lb_logins',
                'SELECT m.id AS member_id, ' . self::WHO . ' AS who, COUNT(*) AS n FROM user_logins ul JOIN users u ON u.id = ul.user_id JOIN members m ON m.id = u.member_id WHERE ' . self::NOT_ADMIN . $sinceSql('ul.created_at') . ' GROUP BY m.id, u.id ORDER BY n DESC, who ASC LIMIT 5', $params)],
            'wings' => ['title' => 'Bookworms', 'unit' => 'Wings reads', 'rows' => $run('lb_wings',
                "SELECT m.id AS member_id, " . self::WHO . " AS who, COUNT(*) AS n FROM activity_log al JOIN members m ON m.id = al.member_id JOIN users u ON u.member_id = m.id WHERE al.action IN ('wings.read', 'wings.download') AND " . self::NOT_ADMIN . $sinceSql('al.created_at') . ' GROUP BY m.id, u.id ORDER BY n DESC, who ASC LIMIT 5', $params)],
            'rsvps' => ['title' => 'Road captains', 'unit' => 'ride RSVPs', 'rows' => $run('lb_rsvps',
                "SELECT m.id AS member_id, " . self::WHO . " AS who, COUNT(*) AS n FROM calendar_event_rsvps r JOIN users u ON u.id = r.user_id JOIN members m ON m.id = u.member_id WHERE r.status = 'going' AND " . self::NOT_ADMIN . $sinceSql('r.created_at') . ' GROUP BY m.id, u.id ORDER BY n DESC, who ASC LIMIT 5', $params)],
            'garage' => ['title' => 'Garage kings', 'unit' => 'bikes listed', 'rows' => $run('lb_garage',
                'SELECT m.id AS member_id, ' . self::WHO . ' AS who, COUNT(*) AS n FROM member_bikes b JOIN members m ON m.id = b.member_id LEFT JOIN users u ON u.member_id = m.id GROUP BY m.id, u.id ORDER BY n DESC, who ASC LIMIT 5', [])],
            'streaks' => ['title' => 'Longest streak', 'unit' => 'weeks in a row', 'rows' => self::q('lb_streaks', function () use ($pdo) {
                $rows = $pdo->query('SELECT m.id AS member_id, ' . self::WHO . ' AS who, YEARWEEK(ul.created_at, 3) AS wk FROM user_logins ul JOIN users u ON u.id = ul.user_id JOIN members m ON m.id = u.member_id WHERE ' . self::NOT_ADMIN . ' AND ul.created_at >= DATE_SUB(NOW(), INTERVAL 53 WEEK) GROUP BY m.id, u.id, wk')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return self::streaksFromWeeks($rows);
            }, [])],
        ];
    }

    private static function newcomers(bool $hasPageViews): array
    {
        $days = self::NEWCOMER_DAYS;
        $out = ['days' => $days, 'joined' => 0, 'logged_in' => 0, 'never' => [], 'median_days_to_first_login' => null, 'first_stops' => []];

        $rows = self::q('newcomers', function () use ($days) {
            $stmt = Database::connection()->prepare('SELECT m.id AS member_id, ' . self::WHO . ' AS who, m.email, m.created_at, u.id AS user_id,
                    (SELECT MIN(ul.created_at) FROM user_logins ul WHERE ul.user_id = u.id) AS first_login,
                    (SELECT MAX(a.approved_at) FROM membership_applications a WHERE a.member_id = m.id) AS approved_at
                FROM members m LEFT JOIN users u ON u.member_id = m.id
                WHERE UPPER(m.status) = \'ACTIVE\' AND m.created_at >= :since
                ORDER BY m.created_at DESC');
            $stmt->execute(['since' => date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }, []);

        $delays = [];
        foreach ($rows as $r) {
            $out['joined']++;
            if (!empty($r['first_login'])) {
                $out['logged_in']++;
                $from = strtotime($r['approved_at'] ?: $r['created_at']);
                $delays[] = max(0, (strtotime($r['first_login']) - $from) / 86400);
            } else {
                $out['never'][] = [
                    'member_id'   => (int) $r['member_id'],
                    'who'         => $r['who'],
                    'email'       => $r['email'],
                    'joined'      => $r['approved_at'] ?: $r['created_at'],
                    'has_account' => !empty($r['user_id']),
                ];
            }
        }
        $out['median_days_to_first_login'] = $delays ? round((float) self::median($delays), 1) : null;

        if ($hasPageViews && $out['logged_in'] > 0) {
            $out['first_stops'] = self::q('first_stops', function () use ($rows) {
                $stmt = Database::connection()->prepare("SELECT area FROM page_views WHERE user_id = :uid AND area NOT IN ('dashboard', 'public', 'other', 'admin') ORDER BY id LIMIT 1");
                $counts = [];
                foreach ($rows as $r) {
                    if (empty($r['user_id']) || empty($r['first_login'])) {
                        continue;
                    }
                    $stmt->execute(['uid' => (int) $r['user_id']]);
                    $area = $stmt->fetchColumn();
                    if ($area) {
                        $counts[$area] = ($counts[$area] ?? 0) + 1;
                    }
                }
                arsort($counts);
                $stops = [];
                foreach ($counts as $area => $n) {
                    $stops[] = ['label' => PageViewLogger::label((string) $area), 'count' => $n];
                }
                return $stops;
            }, []);
        }
        return $out;
    }

    private static function sinceLaunch(): array
    {
        $pdo = Database::connection();
        $scalar = fn(string $section, string $sql) => self::q($section, fn() => $pdo->query($sql)->fetchColumn(), null);
        $row = fn(string $section, string $sql) => self::q($section, fn() => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null, null);
        $int = fn($v): ?int => ($v === null || $v === false) ? null : (int) $v;

        $renewals = $row('renewals_online', "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM orders WHERE order_type = 'membership' AND payment_status = 'accepted' AND payment_method IN ('stripe', 'card')");
        $store = $row('store_online', "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM store_orders WHERE payment_status = 'paid' AND voided_at IS NULL");

        return [
            'launched'              => $scalar('launched', 'SELECT MIN(created_at) FROM user_logins') ?: null,
            'active_members'        => (int) $scalar('active_members_launch', "SELECT COUNT(*) FROM members WHERE UPPER(status) = 'ACTIVE'"),
            'active_ever_logged_in' => (int) $scalar('active_ever_logged_in', "SELECT COUNT(DISTINCT m.id) FROM members m JOIN users u ON u.member_id = m.id JOIN user_logins ul ON ul.user_id = u.id WHERE UPPER(m.status) = 'ACTIVE' AND " . self::NOT_ADMIN),
            'total_logins'          => (int) $scalar('total_logins', 'SELECT COUNT(*) FROM user_logins ul JOIN users u ON u.id = ul.user_id WHERE u.member_id IS NOT NULL AND ' . self::NOT_ADMIN),
            'renewals_online'       => $renewals ? (int) $renewals['n'] : null,
            'renewals_online_total' => $renewals ? (float) $renewals['total'] : null,
            'store_orders'          => $store ? (int) $store['n'] : null,
            'store_total'           => $store ? (float) $store['total'] : null,
            'rsvps'                 => $int($scalar('rsvps_total', "SELECT COUNT(*) FROM calendar_event_rsvps WHERE status = 'going'")),
            'wings_reads'           => $int($scalar('wings_total', "SELECT COUNT(*) FROM activity_log WHERE action IN ('wings.read', 'wings.download')")),
            'profile_self_updates'  => $int($scalar('profile_self_updates', "SELECT COUNT(*) FROM member_profile_updates WHERE source = 'member'")),
            'applications_online'   => $int($scalar('applications_online', 'SELECT COUNT(*) FROM membership_applications')),
            'tours_completed'       => $int($scalar('tours_completed', 'SELECT COUNT(*) FROM tour_completions')),
        ];
    }

    /** Active members with no login in DORMANT_DAYS, plus the subset whose renewal is due within 120 days. */
    private static function dormant(): array
    {
        $days = self::DORMANT_DAYS;
        return self::q('dormant', function () use ($days) {
            $pdo = Database::connection();
            $lastLogin = '(SELECT MAX(ul.created_at) FROM user_logins ul JOIN users u ON u.id = ul.user_id WHERE u.member_id = m.id)';
            $dnr = self::columnExists('members', 'do_not_renew') ? ' AND COALESCE(m.do_not_renew, 0) = 0' : '';
            $base = "FROM members m LEFT JOIN chapters c ON c.id = m.chapter_id WHERE UPPER(m.status) = 'ACTIVE'{$dnr} AND COALESCE({$lastLogin}, '1970-01-01') < DATE_SUB(NOW(), INTERVAL {$days} DAY)";
            $total = (int) $pdo->query("SELECT COUNT(*) {$base}")->fetchColumn();
            $chase = $pdo->query("SELECT m.id AS member_id, TRIM(CONCAT(m.first_name, ' ', m.last_name)) AS who, m.email, c.name AS chapter, {$lastLogin} AS last_login,
                    (SELECT MAX(p.end_date) FROM membership_periods p WHERE p.member_id = m.id) AS renewal_due
                {$base}
                HAVING renewal_due IS NOT NULL AND renewal_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)
                ORDER BY renewal_due ASC, who ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return ['days' => $days, 'total' => $total, 'chase' => $chase];
        }, ['days' => $days, 'total' => 0, 'chase' => []]);
    }

    /** Share of each chapter's active members who logged in since $since. */
    private static function chapters(string $since): array
    {
        return self::q('chapters', function () use ($since) {
            $stmt = Database::connection()->prepare("SELECT c.name, COUNT(DISTINCT m.id) AS members, COUNT(DISTINCT CASE WHEN ul.id IS NOT NULL THEN m.id END) AS active
                FROM chapters c
                JOIN members m ON m.chapter_id = c.id AND UPPER(m.status) = 'ACTIVE'
                LEFT JOIN users u ON u.member_id = m.id
                LEFT JOIN user_logins ul ON ul.user_id = u.id AND ul.created_at >= :since
                WHERE c.is_active = 1
                GROUP BY c.id, c.name");
            $stmt->execute(['since' => $since]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['members'] = (int) $r['members'];
                $r['active'] = (int) $r['active'];
                $r['pct'] = $r['members'] > 0 ? (int) round(100 * $r['active'] / $r['members']) : 0;
            }
            unset($r);
            usort($rows, fn($a, $b) => [$b['pct'], $b['members']] <=> [$a['pct'], $a['members']]);
            return $rows;
        }, []);
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    private static function q(string $section, callable $fn, $default)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            self::$errors[$section] = $e->getMessage();
            error_log('[EngagementReport] ' . $section . ': ' . $e->getMessage());
            return $default;
        }
    }

    /** Minutes to add to DB timestamps (NOW()-based, DB server zone) to land in the site's timezone. */
    private static function hourShiftMinutes(): int
    {
        $dbOffset = 0;
        try {
            $diff = (string) Database::connection()->query('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP())')->fetchColumn();
            $sign = str_starts_with($diff, '-') ? -1 : 1;
            $parts = array_map('intval', explode(':', ltrim($diff, '-')));
            $dbOffset = $sign * (($parts[0] ?? 0) * 60 + ($parts[1] ?? 0));
        } catch (Throwable $e) {
            // Fall back to "no shift" rather than failing the section.
        }
        $siteOffset = intdiv((new DateTime('now'))->getOffset(), 60);
        return $siteOffset - $dbOffset;
    }

    private static function tableExists(string $table): bool
    {
        $key = 'table:' . $table;
        if (!array_key_exists($key, self::$schemaCache)) {
            try {
                // SHOW statements refuse bound parameters under native prepares.
                $pdo = Database::connection();
                self::$schemaCache[$key] = (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
            } catch (Throwable $e) {
                self::$schemaCache[$key] = false;
            }
        }
        return self::$schemaCache[$key];
    }

    private static function columnExists(string $table, string $column): bool
    {
        $key = 'column:' . $table . '.' . $column;
        if (!array_key_exists($key, self::$schemaCache)) {
            try {
                $pdo = Database::connection();
                self::$schemaCache[$key] = (bool) $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column))->fetchColumn();
            } catch (Throwable $e) {
                self::$schemaCache[$key] = false;
            }
        }
        return self::$schemaCache[$key];
    }
}
