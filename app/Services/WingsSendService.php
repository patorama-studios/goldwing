<?php
namespace App\Services;

use PDO;

/**
 * Monthly Wings magazine email. An admin reviews the list on
 * /admin/wings-send.php, unticks anyone who shouldn't get it, adds an optional
 * note and sends. Each recipient gets a personal link
 * (wings_send_recipients.token) that opens the issue without logging in, since
 * many members have never set a portal password. Tables: Migration 056.
 */
class WingsSendService
{
    // Recipients per AJAX batch: keeps every request well inside the host's time limit.
    public const BATCH_SIZE = 10;
    // Resend's free plan allows 100 emails a day for the whole site (paid plans
    // have no daily cap), so by default Wings stops at 80 per 24 hours, leaving
    // room for receipts, password resets and login codes. 0 = no limit.
    public const DEFAULT_DAILY_LIMIT = 80;

    public static function dailyLimit(): int
    {
        return max(0, (int) SettingsService::getGlobal('wings.daily_send_limit', self::DEFAULT_DAILY_LIMIT));
    }

    // Wings emails handed to the mailer in the last 24 hours. A rolling window,
    // so no calendar day (Resend resets at midnight UTC) can ever exceed the limit.
    public static function sentLast24h(): int
    {
        return (int) Database::connection()
            ->query("SELECT COUNT(*) FROM wings_send_recipients WHERE status = 'sent' AND sent_at >= NOW() - INTERVAL 24 HOUR")
            ->fetchColumn();
    }

    // Unix time when the daily limit next lets an email out, or null if it can go now.
    public static function nextSendAt(): ?int
    {
        $limit = self::dailyLimit();
        if ($limit === 0 || self::sentLast24h() < $limit) {
            return null;
        }
        // Counted in seconds-from-now so the DB server's clock zone can't skew it.
        $wait = (int) Database::connection()
            ->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(sent_at) + INTERVAL 24 HOUR) FROM wings_send_recipients WHERE status = 'sent' AND sent_at >= NOW() - INTERVAL 24 HOUR")
            ->fetchColumn();
        return time() + max(60, $wait);
    }

    public static function tablesReady(): bool
    {
        try {
            return (bool) Database::connection()->query("SHOW TABLES LIKE 'wings_send_recipients'")->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function issue(int $issueId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM wings_issues WHERE id = :id');
        $stmt->execute(['id' => $issueId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Same rule as the Wings admin page: the issue flagged latest, else the newest.
    public static function latestIssue(): ?array
    {
        $row = Database::connection()
            ->query('SELECT * FROM wings_issues ORDER BY is_latest DESC, published_at DESC, id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function defaultSubject(array $issue): string
    {
        $title = trim((string) ($issue['title'] ?? ''));
        return 'Your copy of ' . (stripos($title, 'wings') === false ? 'Wings ' . $title : $title) . ' is here';
    }

    /**
     * Who gets the email: every ACTIVE member with a usable address, whatever
     * their print/digital preference (club policy: everyone is emailed the PDF,
     * same list as admin/members/export.php?list=email_pdf). Members sharing an
     * address (a couple) collapse into one entry so the inbox gets one copy.
     * Entries are keyed by their first member id, full members ahead of partners.
     */
    public static function audience(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT m.*, ' . ChapterRepository::displayNameSql($pdo) . ' AS chapter_name
             FROM members m LEFT JOIN chapters c ON c.id = m.chapter_id
             WHERE LOWER(m.status) = \'active\' AND TRIM(COALESCE(m.email, \'\')) <> \'\'
             ORDER BY (m.full_member_id IS NOT NULL), m.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $m) {
            $email = strtolower(trim((string) $m['email']));
            if (!self::isDeliverable($email)) {
                continue;
            }
            if (!isset($groups[$email])) {
                $groups[$email] = [
                    'key' => (int) $m['id'],
                    'email' => $email,
                    'names' => [],
                    'first_names' => [],
                    'last_names' => [],
                    'numbers' => [],
                    'chapter' => (string) ($m['chapter_name'] ?? ''),
                    'sort' => strtolower($m['last_name'] . ' ' . $m['first_name']),
                    'opted_out' => false,
                ];
            }
            $g = &$groups[$email];
            $g['names'][] = trim($m['first_name'] . ' ' . $m['last_name']);
            $g['first_names'][] = trim((string) $m['first_name']);
            $g['last_names'][] = trim((string) $m['last_name']);
            if (!empty($m['member_number_base'])) {
                $g['numbers'][] = MembershipService::displayMembershipNumber((int) $m['member_number_base'], (int) ($m['member_number_suffix'] ?? 0));
            }
            // Anyone on a shared inbox who opted out of Wings emails speaks for it.
            $userId = (int) ($m['user_id'] ?? 0);
            if ($userId > 0 && !NotificationPreferenceService::shouldReceive($userId, 'wings')) {
                $g['opted_out'] = true;
            }
            unset($g);
        }
        $groups = array_values($groups);
        foreach ($groups as &$g) {
            // "John & Mary Citizen" rather than "John Citizen & Mary Citizen".
            $lastNames = array_unique($g['last_names']);
            $g['label'] = count($g['names']) > 1 && count($lastNames) === 1
                ? self::greeting($g['first_names']) . ' ' . $lastNames[0]
                : implode(' & ', $g['names']);
        }
        unset($g);
        usort($groups, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
        return $groups;
    }

    // ACTIVE members we can't email at all (no address, or a placeholder one).
    public static function noEmailCount(): int
    {
        $emails = Database::connection()
            ->query("SELECT TRIM(COALESCE(email, '')) FROM members WHERE LOWER(status) = 'active'")
            ->fetchAll(PDO::FETCH_COLUMN);
        return count(array_filter($emails, fn ($e) => !self::isDeliverable(strtolower((string) $e))));
    }

    // Skips committee-roster stubs (+stub@) and Migration 055's @goldwing.invalid rows.
    private static function isDeliverable(string $email): bool
    {
        return $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && !str_contains($email, '+stub@')
            && !str_ends_with($email, '.invalid');
    }

    public static function greeting(array $firstNames): string
    {
        $names = array_values(array_unique(array_filter($firstNames)));
        if (count($names) <= 1) {
            return $names[0] ?? 'Member';
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' & ' . $last;
    }

    /**
     * The email. $links: read / download / cover URLs (absolute in real sends,
     * relative in the admin preview). $preview keeps an empty message box in
     * the markup so the compose form can fill it live.
     */
    public static function renderEmail(array $issue, string $subject, string $message, string $greeting, array $links, bool $preview = false): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $title = $esc($issue['title'] ?? 'Wings');
        $month = $esc(date('F Y', strtotime((string) ($issue['published_at'] ?? 'now'))));
        $read = $esc($links['read'] ?? '#');
        $download = $esc($links['download'] ?? '#');
        $cover = (string) ($links['cover'] ?? '');

        $body = '<p style="margin:0 0 6px;font-size:22px;font-weight:700;color:#1c1a17;line-height:1.3;">G\'day ' . $esc($greeting) . ',</p>'
            . '<p style="margin:0 0 28px;font-size:16px;color:#5a5a55;line-height:1.6;">The new issue of Wings has just landed. Here\'s your copy.</p>';

        $message = trim($message);
        if ($message !== '' || $preview) {
            $body .= '<table id="wings-message" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;' . ($message === '' ? 'display:none;' : '') . '"><tr>'
                . '<td style="background:#f4f1e8;border-left:4px solid #9e9140;border-radius:0 8px 8px 0;padding:18px 22px;font-size:15px;line-height:1.65;color:#374151;">'
                . '<span id="wings-message-text">' . nl2br($esc($message)) . '</span></td></tr></table>';
        }

        $coverHtml = $cover !== ''
            ? '<a href="' . $read . '" style="text-decoration:none;"><img src="' . $esc($cover) . '" alt="Cover of ' . $title . '" width="280" style="display:block;width:280px;max-width:100%;height:auto;border:0;border-radius:6px;box-shadow:0 12px 28px rgba(28,26,23,0.28);"></a>'
            : '<a href="' . $read . '" style="display:block;width:220px;padding:90px 0;border:2px solid #9e9140;border-radius:6px;font-family:Georgia,\'Times New Roman\',serif;font-size:34px;font-weight:700;letter-spacing:0.12em;color:#9e9140;text-decoration:none;text-align:center;">WINGS</a>';

        $body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td align="center" style="padding:4px 0 0;">' . $coverHtml . '</td></tr>'
            . '<tr><td align="center" style="padding:24px 0 0;">'
            . '<p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#9e9140;">' . $month . '</p>'
            . '<p style="margin:0 0 24px;font-family:Georgia,\'Times New Roman\',serif;font-size:26px;font-weight:700;color:#1c1a17;line-height:1.25;">' . $title . '</p>'
            . '<table cellpadding="0" cellspacing="0" border="0" align="center"><tr><td style="background:#9e9140;border-radius:8px;">'
            . '<a href="' . $read . '" style="display:inline-block;padding:15px 38px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;letter-spacing:0.02em;">Read the magazine &rarr;</a>'
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;font-size:13px;color:#9a9a94;">or <a href="' . $download . '" style="color:#9e9140;text-decoration:underline;">download the PDF</a></p>'
            . '</td></tr></table>'
            . '<hr style="border:none;border-top:1px solid #e8e3d7;margin:32px 0 20px;">'
            . '<p style="margin:0;font-size:13px;color:#9a9a94;line-height:1.6;">This link is just for you, so there\'s no need to log in. Every past issue lives in the member portal under Wings. Questions? Reply to this email or contact <a href="mailto:webmaster@goldwing.org.au" style="color:#9e9140;text-decoration:none;">webmaster@goldwing.org.au</a>.</p>';

        return EmailService::wrapHtml($subject, $body, 'Wings Magazine');
    }

    // Absolute URL for a stored path (cover images live under /uploads).
    public static function absoluteUrl(string $path): string
    {
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return BaseUrlService::emailLink($path);
    }

    /**
     * Freezes the reviewed list into a send. The audience is re-read here, so
     * a posted key only selects from it: nobody outside it can be added.
     */
    public static function createSend(int $issueId, string $subject, string $message, array $includeKeys, int $userId): int
    {
        $pdo = Database::connection();
        $include = array_flip(array_map('intval', $includeKeys));
        $counts = ['queued' => 0, 'removed' => 0, 'opted_out' => 0];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO wings_sends (issue_id, subject, message, created_by, created_at) VALUES (:issue, :subject, :message, :by, NOW())')
                ->execute(['issue' => $issueId, 'subject' => $subject, 'message' => $message, 'by' => $userId ?: null]);
            $sendId = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare('INSERT INTO wings_send_recipients (send_id, member_id, email, greeting, token, status) VALUES (:send, :member, :email, :greeting, :token, :status)');
            foreach (self::audience() as $g) {
                $status = $g['opted_out'] ? 'opted_out' : (isset($include[$g['key']]) ? 'queued' : 'removed');
                $counts[$status]++;
                $insert->execute([
                    'send' => $sendId,
                    'member' => $g['key'],
                    'email' => $g['email'],
                    'greeting' => self::greeting($g['first_names']),
                    'token' => bin2hex(random_bytes(16)),
                    'status' => $status,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        ActivityLogger::log('admin', $userId, null, 'wings.email_send', [
            'target_type' => 'wings_issue',
            'target_id' => $issueId,
            'send_id' => $sendId,
            'subject' => $subject,
        ] + $counts);
        return $sendId;
    }

    public static function findSend(int $sendId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM wings_sends WHERE id = :id');
        $stmt->execute(['id' => $sendId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Emails the next batch of queued recipients, never more than the daily
     * limit allows. A row is claimed by flipping it to 'sent' before mailing
     * (at most once: a second tab or a crash can never double-send), and turned
     * back to 'failed' if the mailer refuses. Extra keys on the counts:
     * paused_until (daily limit reached) and stalled (the whole batch failed,
     * which points at the mail service rather than one bad address).
     */
    public static function sendBatch(int $sendId, int $limit = self::BATCH_SIZE): array
    {
        $send = self::findSend($sendId);
        $issue = $send ? self::issue((int) $send['issue_id']) : null;
        if (!$send || !$issue) {
            return ['error' => 'Send not found.'];
        }
        $pdo = Database::connection();
        // One batch at a time across tabs and admins, so the daily count can't be overshot.
        if (!$pdo->query("SELECT GET_LOCK('wings_send_batch', 30)")->fetchColumn()) {
            return self::counts($sendId);
        }
        $sent = $failed = 0;
        try {
            if (self::dailyLimit() > 0) {
                $limit = min($limit, self::dailyLimit() - self::sentLast24h());
            }
            if ($limit <= 0) {
                return self::counts($sendId) + ['paused_until' => self::nextSendAt()];
            }
            $stmt = $pdo->prepare("SELECT * FROM wings_send_recipients WHERE send_id = :id AND status = 'queued' ORDER BY id LIMIT " . (int) $limit);
            $stmt->execute(['id' => $sendId]);
            $claim = $pdo->prepare("UPDATE wings_send_recipients SET status = 'sent', sent_at = NOW() WHERE id = :id AND status = 'queued'");
            $fail = $pdo->prepare("UPDATE wings_send_recipients SET status = 'failed', sent_at = NULL WHERE id = :id");
            $cover = self::absoluteUrl((string) ($issue['cover_image_url'] ?? ''));

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $claim->execute(['id' => $r['id']]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $html = self::renderEmail($issue, $send['subject'], (string) $send['message'], $r['greeting'], [
                    'read' => BaseUrlService::emailLink('/member/read_wings.php?t=' . $r['token']),
                    'download' => BaseUrlService::emailLink('/member/download_wings.php?t=' . $r['token']),
                    'cover' => $cover,
                ]);
                $ok = EmailService::send($r['email'], $send['subject'], $html, [
                    'member_id' => $r['member_id'] ? (int) $r['member_id'] : null,
                    'notification_key' => 'wings_issue',
                    'category' => 'wings',
                ]);
                if ($ok) {
                    $sent++;
                } else {
                    $failed++;
                    $fail->execute(['id' => $r['id']]);
                }
                usleep(500000); // 2 a second at most: inside Resend's 10/s (older accounts: 2/s)
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('wings_send_batch')");
        }

        $counts = self::counts($sendId);
        if ($counts['queued'] === 0) {
            $pdo->prepare('UPDATE wings_sends SET finished_at = NOW() WHERE id = :id AND finished_at IS NULL')->execute(['id' => $sendId]);
        }
        if ($sent === 0 && $failed > 0) {
            $counts['stalled'] = true;
        }
        return $counts;
    }

    public static function retryFailed(int $sendId): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE wings_send_recipients SET status = 'queued' WHERE send_id = :id AND status = 'failed'")->execute(['id' => $sendId]);
        $pdo->prepare('UPDATE wings_sends SET finished_at = NULL WHERE id = :id')->execute(['id' => $sendId]);
    }

    public static function counts(int $sendId): array
    {
        $stmt = Database::connection()->prepare('SELECT status, COUNT(*) AS n, SUM(opened_at IS NOT NULL) AS opened FROM wings_send_recipients WHERE send_id = :id GROUP BY status');
        $stmt->execute(['id' => $sendId]);
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'opted_out' => 0, 'opened' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['n'];
            $counts['opened'] += (int) $row['opened'];
        }
        $counts['total'] = $counts['queued'] + $counts['sent'] + $counts['failed'];
        return $counts;
    }

    public static function recipients(int $sendId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, CONCAT(m.first_name, \' \', m.last_name) AS member_name
             FROM wings_send_recipients r LEFT JOIN members m ON m.id = r.member_id
             WHERE r.send_id = :id
             ORDER BY FIELD(r.status, \'failed\', \'queued\', \'sent\', \'removed\', \'opted_out\'), m.last_name, m.first_name'
        );
        $stmt->execute(['id' => $sendId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Every send of an issue, newest first, with its numbers.
    public static function sendsForIssue(int $issueId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM wings_sends WHERE issue_id = :id ORDER BY id DESC');
        $stmt->execute(['id' => $issueId]);
        $sends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sends as &$s) {
            $s['counts'] = self::counts((int) $s['id']);
        }
        unset($s);
        return $sends;
    }

    // issue_id => latest send (with counts), for the Wings admin page badges.
    public static function latestSendByIssue(): array
    {
        if (!self::tablesReady()) {
            return [];
        }
        $rows = Database::connection()->query(
            "SELECT s.id, s.issue_id, s.created_at, SUM(r.status = 'sent') AS sent, SUM(r.status = 'queued') AS queued, SUM(r.opened_at IS NOT NULL) AS opened
             FROM wings_sends s LEFT JOIN wings_send_recipients r ON r.send_id = s.id
             GROUP BY s.id ORDER BY s.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $byIssue = [];
        foreach ($rows as $row) {
            $byIssue[(int) $row['issue_id']] = $row;
        }
        return $byIssue;
    }

    // The recipient behind a personal link. Only links that were actually mailed work.
    public static function recipientForToken(string $token): ?array
    {
        if (strlen($token) !== 32 || !ctype_xdigit($token) || !self::tablesReady()) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            "SELECT r.*, s.issue_id FROM wings_send_recipients r JOIN wings_sends s ON s.id = r.send_id
             WHERE r.token = :token AND r.status = 'sent' LIMIT 1"
        );
        $stmt->execute(['token' => strtolower($token)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function markOpened(int $recipientId): void
    {
        Database::connection()
            ->prepare('UPDATE wings_send_recipients SET opened_at = NOW() WHERE id = :id AND opened_at IS NULL')
            ->execute(['id' => $recipientId]);
    }
}
