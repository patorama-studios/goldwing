<?php
// version: 2026-06-03b — bump mtime to force OPcache reload after fetchOne() addition
namespace App\Services;

use PDO;
use Throwable;

/**
 * Aggregates pending approval requests across all request types for the
 * admin notification hub. Each query is wrapped in try/catch so a missing
 * table (e.g., before migration runs) doesn't break the dashboard.
 */
class PendingRequestsService
{
    public const TYPE_NOTICE              = 'notice';
    public const TYPE_EVENT               = 'event';
    public const TYPE_MEMBER_OF_YEAR      = 'member_of_year';
    public const TYPE_FALLEN_WINGS        = 'fallen_wings';
    public const TYPE_CHAPTER_CHANGE      = 'chapter_change';
    public const TYPE_PROFILE_CHANGE      = 'profile_change';
    public const TYPE_PROFILE_UPDATE      = 'profile_update';
    public const TYPE_STORE_ORDER         = 'store_order';
    public const TYPE_MEMBERSHIP          = 'membership_application';
    public const TYPE_FEEDBACK            = 'feedback';

    /**
     * Profile fields a member may request changes to via the notification
     * hub. Keys are the column name on `members`; values describe how the
     * field is presented and validated.
     */
    public const PROFILE_FIELDS = [
        'join_date' => [
            'label' => 'Join date',
            'input_type' => 'date',
        ],
    ];

    /**
     * Fields whose change marks a profile update as contact-relevant —
     * the ones that matter before a CSV export or external mail-out.
     */
    public const PROFILE_UPDATE_CONTACT_FIELDS = [
        'email', 'phone',
        'address_line1', 'address_line2', 'suburb', 'city',
        'state', 'postcode', 'postal_code', 'country',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_FEEDBACK            => ['label' => 'Beta Feedback',          'icon' => 'feedback'],
            self::TYPE_NOTICE              => ['label' => 'Notice',                 'icon' => 'campaign'],
            self::TYPE_EVENT               => ['label' => 'Event',                  'icon' => 'event'],
            self::TYPE_MEMBER_OF_YEAR      => ['label' => 'Member of the Year',     'icon' => 'emoji_events'],
            self::TYPE_FALLEN_WINGS        => ['label' => 'Fallen Wings',           'icon' => 'military_tech'],
            self::TYPE_CHAPTER_CHANGE      => ['label' => 'Chapter Change',         'icon' => 'swap_horiz'],
            self::TYPE_PROFILE_CHANGE      => ['label' => 'Profile Change',         'icon' => 'edit_note'],
            self::TYPE_PROFILE_UPDATE      => ['label' => 'Details Updated',        'icon' => 'manage_accounts'],
            self::TYPE_STORE_ORDER         => ['label' => 'Store Order',            'icon' => 'storefront'],
            self::TYPE_MEMBERSHIP          => ['label' => 'Membership Application', 'icon' => 'how_to_reg'],
        ];
    }

    /**
     * Return a unified list of all pending requests across all types.
     * Each row: [type, id, title, submitted_by, submitted_at, status, detail_url, summary]
     */
    /** Valid status filters accepted by the hub */
    public static function validStatuses(): array
    {
        return ['pending', 'approved', 'rejected', 'archived', 'all'];
    }

    public static function all(?string $typeFilter = null, string $statusFilter = 'pending'): array
    {
        $items = [];
        $methods = [
            self::TYPE_FEEDBACK       => 'fetchFeedback',
            self::TYPE_NOTICE         => 'fetchNotices',
            self::TYPE_EVENT          => 'fetchEvents',
            self::TYPE_MEMBER_OF_YEAR => 'fetchMemberOfYear',
            self::TYPE_FALLEN_WINGS   => 'fetchFallenWings',
            self::TYPE_CHAPTER_CHANGE => 'fetchChapterChange',
            self::TYPE_PROFILE_CHANGE => 'fetchProfileChange',
            self::TYPE_PROFILE_UPDATE => 'fetchProfileUpdates',
            self::TYPE_STORE_ORDER    => 'fetchStoreOrders',
            self::TYPE_MEMBERSHIP     => 'fetchMembershipApplications',
        ];

        foreach ($methods as $type => $method) {
            if ($typeFilter && $typeFilter !== $type) {
                continue;
            }
            try {
                $rows = self::$method($statusFilter);
                foreach ($rows as $row) {
                    $items[] = $row;
                }
            } catch (Throwable $e) {
                // Skip missing tables silently
            }
        }

        usort($items, function ($a, $b) {
            return strcmp((string) ($b['submitted_at'] ?? ''), (string) ($a['submitted_at'] ?? ''));
        });

        return $items;
    }

    public static function counts(): array
    {
        $counts = [];
        foreach (array_keys(self::types()) as $type) {
            $counts[$type] = 0;
        }
        $counts['__total'] = 0;

        $items = self::all(null, 'pending');
        foreach ($items as $item) {
            $type = $item['type'] ?? null;
            if ($type && isset($counts[$type])) {
                $counts[$type]++;
            }
            $counts['__total']++;
        }
        return $counts;
    }

    public static function find(string $type, int $id): ?array
    {
        // Fast path: look up the specific row directly. Avoids fetching the
        // entire type listing (which can be slow, and which silently swallows
        // a failing JOIN in one fetcher and returns no rows — leaving the
        // detail page looking blank).
        $direct = self::fetchOne($type, $id);
        if ($direct !== null) {
            return $direct;
        }

        // Fallback to the bulk fetch (preserves any per-type fields that
        // fetchOne might not assemble identically).
        $rows = self::all($type, 'all');
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    private static function fetchOne(string $type, int $id): ?array
    {
        try {
            $pdo = Database::connection();
            switch ($type) {
                case self::TYPE_FEEDBACK:
                    $stmt = $pdo->prepare(
                        "SELECT f.id, f.user_id, f.submitter_name, f.submitter_email, f.message,
                                f.page_url, f.user_agent, f.status, f.response, f.reviewed_by,
                                f.reviewed_at, f.created_at,
                                u.name AS user_name, u.email AS user_email
                         FROM beta_feedback f
                         LEFT JOIN users u ON u.id = f.user_id
                         WHERE f.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    $displayStatus = match ((string) $r['status']) {
                        'resolved' => 'approved',
                        'wont_fix' => 'rejected',
                        'archived' => 'archived',
                        default    => 'pending',
                    };
                    $title = 'Ticket #' . $r['id'] . ' — ' . substr(strip_tags((string) $r['message']), 0, 60);
                    $name = $r['submitter_name'] ?: $r['user_name'] ?: 'Anonymous';
                    $email = $r['submitter_email'] ?: $r['user_email'] ?: null;
                    $summary = ($r['page_url'] ? 'Page: ' . $r['page_url'] . ' · ' : '')
                             . substr((string) $r['message'], 0, 160);
                    $r['ticket_status'] = $r['status'];
                    return self::row(self::TYPE_FEEDBACK, $r['id'], $title, $displayStatus, $r['created_at'],
                        $name, $email, $summary, $r);

                case self::TYPE_NOTICE:
                    $stmt = $pdo->prepare(
                        "SELECT n.id, n.title, n.content, n.status, n.created_at, n.created_by, n.feedback_message,
                                n.reviewed_by, n.reviewed_at,
                                u.name AS submitter_name, u.email AS submitter_email
                         FROM notices n
                         LEFT JOIN users u ON u.id = n.created_by
                         WHERE n.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    return self::row(self::TYPE_NOTICE, $r['id'], $r['title'], $r['status'], $r['created_at'],
                        $r['submitter_name'], $r['submitter_email'], substr(strip_tags((string) $r['content']), 0, 160), $r);

                case self::TYPE_EVENT:
                    $stmt = $pdo->prepare(
                        "SELECT e.id, e.title, e.description, e.status, e.created_at, e.created_by,
                                e.start_at, e.end_at, e.feedback_message, e.reviewed_by, e.reviewed_at,
                                u.name AS submitter_name, u.email AS submitter_email
                         FROM calendar_events e
                         LEFT JOIN users u ON u.id = e.created_by
                         WHERE e.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    return self::row(self::TYPE_EVENT, $r['id'], $r['title'], $r['status'], $r['created_at'],
                        $r['submitter_name'], $r['submitter_email'],
                        ($r['start_at'] ? 'Starts ' . $r['start_at'] . ' · ' : '') . substr(strip_tags((string) $r['description']), 0, 140),
                        $r);

                case self::TYPE_MEMBER_OF_YEAR:
                    $stmt = $pdo->prepare(
                        "SELECT id, submitted_at, status, nominator_first_name, nominator_last_name, nominator_email,
                                nominee_first_name, nominee_last_name, nominee_chapter, nomination_details,
                                admin_notes, feedback_message
                         FROM member_of_year_nominations WHERE id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    $title = 'Nominee: ' . trim($r['nominee_first_name'] . ' ' . $r['nominee_last_name']);
                    $submitter = trim($r['nominator_first_name'] . ' ' . $r['nominator_last_name']);
                    return self::row(self::TYPE_MEMBER_OF_YEAR, $r['id'], $title, $r['status'], $r['submitted_at'],
                        $submitter, $r['nominator_email'],
                        'Chapter: ' . ($r['nominee_chapter'] ?? 'n/a') . ' · ' . substr((string) $r['nomination_details'], 0, 140),
                        $r);

                case self::TYPE_FALLEN_WINGS:
                    $stmt = $pdo->prepare(
                        "SELECT fw.id, fw.full_name, fw.year_of_passing, fw.tribute, fw.status, fw.created_at,
                                fw.feedback_message, fw.member_number, fw.image_url, fw.pdf_url,
                                u.name AS submitter_name, u.email AS submitter_email
                         FROM fallen_wings fw
                         LEFT JOIN users u ON u.id = fw.submitted_by
                         WHERE fw.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    return self::row(self::TYPE_FALLEN_WINGS, $r['id'], $r['full_name'], strtolower((string) $r['status']), $r['created_at'],
                        $r['submitter_name'], $r['submitter_email'],
                        'Year of passing: ' . $r['year_of_passing'] . ' · ' . substr((string) $r['tribute'], 0, 140),
                        $r);

                case self::TYPE_CHAPTER_CHANGE:
                    $stmt = $pdo->prepare(
                        "SELECT ccr.id, ccr.member_id, ccr.requested_chapter_id, ccr.status, ccr.requested_at,
                                ccr.feedback_message, ccr.rejection_reason,
                                m.first_name, m.last_name, m.email,
                                " . ChapterRepository::displayNameSql($pdo) . " AS chapter_name, c.state AS chapter_state
                         FROM chapter_change_requests ccr
                         LEFT JOIN members m ON m.id = ccr.member_id
                         LEFT JOIN chapters c ON c.id = ccr.requested_chapter_id
                         WHERE ccr.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    $title = $submitter . ' → ' . ($r['chapter_name'] ?? 'Chapter');
                    return self::row(self::TYPE_CHAPTER_CHANGE, $r['id'], $title, strtolower((string) $r['status']), $r['requested_at'],
                        $submitter, $r['email'] ?? null,
                        'Requested chapter: ' . ($r['chapter_name'] ?? 'n/a') . ' (' . ($r['chapter_state'] ?? '') . ')',
                        $r);

                case self::TYPE_PROFILE_CHANGE:
                    $stmt = $pdo->prepare(
                        "SELECT mpr.id, mpr.member_id, mpr.field_name, mpr.current_value, mpr.requested_value,
                                mpr.status, mpr.requested_at, mpr.feedback_message, mpr.rejection_reason,
                                m.first_name, m.last_name, m.email
                         FROM member_profile_change_requests mpr
                         LEFT JOIN members m ON m.id = mpr.member_id
                         WHERE mpr.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    $fieldLabel = self::PROFILE_FIELDS[$r['field_name']]['label'] ?? $r['field_name'];
                    $title = $submitter . ' → ' . $fieldLabel;
                    $summary = $fieldLabel . ': ' . self::formatProfileValue($r['current_value'])
                        . ' → ' . self::formatProfileValue($r['requested_value']);
                    return self::row(self::TYPE_PROFILE_CHANGE, $r['id'], $title, strtolower((string) $r['status']),
                        $r['requested_at'], $submitter, $r['email'] ?? null, $summary, $r);

                case self::TYPE_PROFILE_UPDATE:
                    $stmt = $pdo->prepare(
                        "SELECT mpu.id, mpu.member_id, mpu.source, mpu.changes, mpu.has_contact_change,
                                mpu.status, mpu.created_at, mpu.reviewed_by, mpu.reviewed_at,
                                m.first_name, m.last_name, m.email
                         FROM member_profile_updates mpu
                         LEFT JOIN members m ON m.id = mpu.member_id
                         WHERE mpu.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    return self::profileUpdateRow($r);

                case self::TYPE_STORE_ORDER:
                    $stmt = $pdo->prepare(
                        "SELECT so.id, so.member_id, so.total_amount, so.created_at,
                                so.order_status, so.payment_status, so.fulfillment_status,
                                m.first_name, m.last_name, m.email
                         FROM store_orders so
                         LEFT JOIN members m ON m.id = so.member_id
                         WHERE so.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    return self::row(self::TYPE_STORE_ORDER, $r['id'], 'Order #' . $r['id'],
                        $r['payment_status'] === 'unpaid' ? 'pending' : strtolower((string) $r['payment_status']),
                        $r['created_at'], $submitter, $r['email'] ?? null,
                        'Total: $' . number_format((float) $r['total_amount'], 2) . ' · ' . $r['payment_status'] . ' / ' . $r['order_status'],
                        $r);

                case self::TYPE_MEMBERSHIP:
                    $stmt = $pdo->prepare(
                        "SELECT a.id, a.member_id, a.member_type, a.status, a.created_at,
                                m.first_name, m.last_name, m.email
                         FROM membership_applications a
                         LEFT JOIN members m ON m.id = a.member_id
                         WHERE a.id = :id LIMIT 1"
                    );
                    $stmt->execute(['id' => $id]);
                    $r = $stmt->fetch();
                    if (!$r) return null;
                    $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    return self::row(self::TYPE_MEMBERSHIP, $r['id'], $submitter ?: 'Member #' . $r['member_id'],
                        strtolower((string) $r['status']), $r['created_at'],
                        $submitter, $r['email'] ?? null,
                        'Type: ' . $r['member_type'], $r);
            }
        } catch (Throwable $e) {
            // Swallow — caller will fall back to bulk fetch path.
        }
        return null;
    }

    /**
     * Return all requests submitted by a specific user (for the member notification hub).
     * Keyed the same as all() so the same templates can render them.
     */
    public static function allForUser(int $userId, int $memberId): array
    {
        $pdo = Database::connection();
        $items = [];

        // beta_feedback — linked by user_id
        try {
            $stmt = $pdo->prepare(
                "SELECT f.id, f.user_id, f.submitter_name, f.submitter_email, f.message,
                        f.page_url, f.user_agent, f.status, f.response, f.reviewed_by,
                        f.reviewed_at, f.created_at,
                        u.name AS user_name, u.email AS user_email
                 FROM beta_feedback f
                 LEFT JOIN users u ON u.id = f.user_id
                 WHERE f.user_id = :uid
                 ORDER BY f.created_at DESC LIMIT 100"
            );
            $stmt->execute(['uid' => $userId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $displayStatus = match ((string) $r['status']) {
                    'resolved'  => 'approved',
                    'wont_fix'  => 'rejected',
                    'archived'  => 'archived',
                    default     => 'pending',
                };
                $r['ticket_status'] = $r['status'];
                $items[] = self::row(self::TYPE_FEEDBACK, $r['id'],
                    'Ticket #' . $r['id'] . ' — ' . substr(strip_tags((string) $r['message']), 0, 60),
                    $displayStatus, $r['created_at'],
                    $r['submitter_name'] ?: $r['user_name'] ?: 'You',
                    $r['submitter_email'] ?: $r['user_email'] ?: null,
                    ($r['page_url'] ? 'Page: ' . $r['page_url'] . ' · ' : '') . substr((string) $r['message'], 0, 160),
                    $r);
            }
        } catch (Throwable $e) {}

        // notices — linked by created_by (user_id)
        try {
            $stmt = $pdo->prepare(
                "SELECT n.id, n.title, n.content, n.status, n.created_at, n.feedback_message
                 FROM notices n WHERE n.created_by = :uid ORDER BY n.created_at DESC LIMIT 50"
            );
            $stmt->execute(['uid' => $userId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $items[] = self::row(self::TYPE_NOTICE, $r['id'], $r['title'], $r['status'], $r['created_at'],
                    null, null, substr(strip_tags((string) $r['content']), 0, 160), $r);
            }
        } catch (Throwable $e) {}

        // calendar_events — linked by created_by
        try {
            $stmt = $pdo->prepare(
                "SELECT e.id, e.title, e.description, e.status, e.created_at, e.start_at, e.feedback_message
                 FROM calendar_events e WHERE e.created_by = :uid ORDER BY e.created_at DESC LIMIT 50"
            );
            $stmt->execute(['uid' => $userId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $st = strtolower((string) $r['status']) === 'published' ? 'approved' : strtolower((string) $r['status']);
                $items[] = self::row(self::TYPE_EVENT, $r['id'], $r['title'], $st, $r['created_at'],
                    null, null,
                    ($r['start_at'] ? 'Starts ' . $r['start_at'] . ' · ' : '') . substr(strip_tags((string) $r['description']), 0, 140),
                    $r);
            }
        } catch (Throwable $e) {}

        // fallen_wings — linked by submitted_by (user_id)
        try {
            $stmt = $pdo->prepare(
                "SELECT fw.id, fw.full_name, fw.year_of_passing, fw.tribute, fw.status, fw.created_at, fw.feedback_message
                 FROM fallen_wings fw WHERE fw.submitted_by = :uid ORDER BY fw.created_at DESC LIMIT 50"
            );
            $stmt->execute(['uid' => $userId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $items[] = self::row(self::TYPE_FALLEN_WINGS, $r['id'], $r['full_name'],
                    strtolower((string) $r['status']), $r['created_at'], null, null,
                    'Year of passing: ' . $r['year_of_passing'], $r);
            }
        } catch (Throwable $e) {}

        // chapter_change_requests — linked by member_id
        try {
            $stmt = $pdo->prepare(
                "SELECT ccr.id, ccr.status, ccr.requested_at, ccr.feedback_message,
                        " . ChapterRepository::displayNameSql($pdo) . " AS chapter_name
                 FROM chapter_change_requests ccr
                 LEFT JOIN chapters c ON c.id = ccr.requested_chapter_id
                 WHERE ccr.member_id = :mid ORDER BY ccr.requested_at DESC LIMIT 20"
            );
            $stmt->execute(['mid' => $memberId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $items[] = self::row(self::TYPE_CHAPTER_CHANGE, $r['id'],
                    'Chapter change → ' . ($r['chapter_name'] ?? 'Chapter'),
                    strtolower((string) $r['status']), $r['requested_at'], null, null,
                    'Requested chapter: ' . ($r['chapter_name'] ?? 'n/a'), $r);
            }
        } catch (Throwable $e) {}

        // membership_applications — linked by member_id
        try {
            $stmt = $pdo->prepare(
                "SELECT a.id, a.member_type, a.status, a.created_at
                 FROM membership_applications a WHERE a.member_id = :mid ORDER BY a.created_at DESC LIMIT 20"
            );
            $stmt->execute(['mid' => $memberId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $items[] = self::row(self::TYPE_MEMBERSHIP, $r['id'], 'Membership application',
                    strtolower((string) $r['status']), $r['created_at'], null, null,
                    'Type: ' . $r['member_type'], $r);
            }
        } catch (Throwable $e) {}

        // member_profile_change_requests — linked by member_id
        try {
            $stmt = $pdo->prepare(
                "SELECT mpr.id, mpr.field_name, mpr.current_value, mpr.requested_value,
                        mpr.status, mpr.requested_at, mpr.feedback_message, mpr.rejection_reason
                 FROM member_profile_change_requests mpr
                 WHERE mpr.member_id = :mid ORDER BY mpr.requested_at DESC LIMIT 20"
            );
            $stmt->execute(['mid' => $memberId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $fieldLabel = self::PROFILE_FIELDS[$r['field_name']]['label'] ?? $r['field_name'];
                $summary = $fieldLabel . ': ' . self::formatProfileValue($r['current_value'])
                    . ' → ' . self::formatProfileValue($r['requested_value']);
                $items[] = self::row(self::TYPE_PROFILE_CHANGE, $r['id'],
                    'Profile change → ' . $fieldLabel,
                    strtolower((string) $r['status']), $r['requested_at'], null, null,
                    $summary, $r);
            }
        } catch (Throwable $e) {}

        usort($items, fn($a, $b) => strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? '')));
        return $items;
    }

    // ─── ticket message thread ─────────────────────────────────────────────

    /** Fetch conversation messages for a specific request. */
    public static function getMessages(string $type, int $id): array
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT * FROM ticket_messages WHERE request_type = :t AND request_id = :id ORDER BY created_at ASC'
            );
            $stmt->execute(['t' => $type, 'id' => $id]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Add a message to the conversation thread. */
    public static function addMessage(string $type, int $id, string $senderType, ?int $userId, string $senderName, string $message): void
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO ticket_messages (request_type, request_id, sender_type, user_id, sender_name, message, created_at)
                 VALUES (:t, :id, :st, :uid, :name, :msg, NOW())'
            );
            $stmt->execute([
                't'    => $type,
                'id'   => $id,
                'st'   => $senderType,
                'uid'  => $userId,
                'name' => $senderName,
                'msg'  => $message,
            ]);
        } catch (Throwable $e) {}
    }

    /** Reopen a request back to pending when a member replies. */
    public static function reopen(string $type, int $id): void
    {
        try {
            $pdo = Database::connection();
            $tableMap = [
                self::TYPE_FEEDBACK       => ['table' => 'beta_feedback',           'status_open' => 'open',    'enum' => 'lower'],
                self::TYPE_NOTICE         => ['table' => 'notices',                  'status_open' => 'pending', 'enum' => 'lower'],
                self::TYPE_EVENT          => ['table' => 'calendar_events',          'status_open' => 'pending', 'enum' => 'lower'],
                self::TYPE_FALLEN_WINGS   => ['table' => 'fallen_wings',             'status_open' => 'PENDING', 'enum' => 'upper'],
                self::TYPE_CHAPTER_CHANGE => ['table' => 'chapter_change_requests',  'status_open' => 'PENDING', 'enum' => 'upper'],
                self::TYPE_PROFILE_CHANGE => ['table' => 'member_profile_change_requests', 'status_open' => 'PENDING', 'enum' => 'upper'],
                self::TYPE_MEMBERSHIP     => ['table' => 'membership_applications',  'status_open' => 'PENDING', 'enum' => 'upper'],
            ];
            if (!isset($tableMap[$type])) return;
            $cfg = $tableMap[$type];
            $pdo->prepare("UPDATE {$cfg['table']} SET status = :s WHERE id = :id")
                ->execute(['s' => $cfg['status_open'], 'id' => $id]);
        } catch (Throwable $e) {}
    }

    // ─── per-type fetchers ─────────────────────────────────────────────

    private static function fetchNotices(string $statusFilter): array
    {
        $pdo = Database::connection();
        $where = self::statusWhere($statusFilter);
        $sql = "SELECT n.id, n.title, n.content, n.status, n.created_at, n.created_by, n.feedback_message,
                       n.reviewed_by, n.reviewed_at,
                       u.name AS submitter_name, u.email AS submitter_email
                FROM notices n
                LEFT JOIN users u ON u.id = n.created_by
                WHERE 1=1 $where
                ORDER BY n.created_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            return self::row(self::TYPE_NOTICE, $r['id'], $r['title'], $r['status'], $r['created_at'],
                $r['submitter_name'], $r['submitter_email'], substr(strip_tags((string) $r['content']), 0, 160), $r);
        }, $rows);
    }

    private static function fetchEvents(string $statusFilter): array
    {
        $pdo = Database::connection();
        // calendar_events historically uses 'published' as the live state; treat it as approved.
        $where = '';
        if ($statusFilter === 'pending') {
            $where = "AND e.status = 'pending'";
        } elseif ($statusFilter === 'approved') {
            $where = "AND e.status IN ('approved','published')";
        } elseif ($statusFilter === 'rejected') {
            $where = "AND e.status = 'rejected'";
        }
        $sql = "SELECT e.id, e.title, e.description, e.status, e.created_at, e.created_by,
                       e.start_at, e.end_at, e.feedback_message, e.reviewed_by, e.reviewed_at,
                       u.name AS submitter_name, u.email AS submitter_email
                FROM calendar_events e
                LEFT JOIN users u ON u.id = e.created_by
                WHERE 1=1 $where
                ORDER BY e.created_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            return self::row(self::TYPE_EVENT, $r['id'], $r['title'], $r['status'], $r['created_at'],
                $r['submitter_name'], $r['submitter_email'],
                ($r['start_at'] ? 'Starts ' . $r['start_at'] . ' · ' : '') . substr(strip_tags((string) $r['description']), 0, 140),
                $r);
        }, $rows);
    }

    private static function fetchMemberOfYear(string $statusFilter): array
    {
        $pdo = Database::connection();
        $statusWhere = '';
        if ($statusFilter === 'pending') {
            $statusWhere = "AND status = 'new'";
        }
        $sql = "SELECT id, submitted_at, status, nominator_first_name, nominator_last_name, nominator_email,
                       nominee_first_name, nominee_last_name, nominee_chapter, nomination_details,
                       admin_notes, feedback_message
                FROM member_of_year_nominations
                WHERE 1=1 $statusWhere
                ORDER BY submitted_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            $title = 'Nominee: ' . trim($r['nominee_first_name'] . ' ' . $r['nominee_last_name']);
            $submitter = trim($r['nominator_first_name'] . ' ' . $r['nominator_last_name']);
            return self::row(self::TYPE_MEMBER_OF_YEAR, $r['id'], $title, $r['status'], $r['submitted_at'],
                $submitter, $r['nominator_email'],
                'Chapter: ' . ($r['nominee_chapter'] ?? 'n/a') . ' · ' . substr((string) $r['nomination_details'], 0, 140),
                $r);
        }, $rows);
    }

    private static function fetchFallenWings(string $statusFilter): array
    {
        $pdo = Database::connection();
        $where = self::statusWhereUpper($statusFilter, 'fw.status');
        $sql = "SELECT fw.id, fw.full_name, fw.year_of_passing, fw.tribute, fw.status, fw.created_at,
                       fw.feedback_message, u.name AS submitter_name, u.email AS submitter_email
                FROM fallen_wings fw
                LEFT JOIN users u ON u.id = fw.submitted_by
                WHERE 1=1 $where
                ORDER BY fw.created_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            return self::row(self::TYPE_FALLEN_WINGS, $r['id'], $r['full_name'], strtolower((string) $r['status']), $r['created_at'],
                $r['submitter_name'], $r['submitter_email'],
                'Year of passing: ' . $r['year_of_passing'] . ' · ' . substr((string) $r['tribute'], 0, 140),
                $r);
        }, $rows);
    }

    private static function fetchChapterChange(string $statusFilter): array
    {
        $pdo = Database::connection();
        $where = self::statusWhereUpper($statusFilter, 'ccr.status');
        $sql = "SELECT ccr.id, ccr.member_id, ccr.requested_chapter_id, ccr.status, ccr.requested_at,
                       ccr.feedback_message, ccr.rejection_reason,
                       m.first_name, m.last_name, m.email,
                       " . ChapterRepository::displayNameSql($pdo) . " AS chapter_name, c.state AS chapter_state
                FROM chapter_change_requests ccr
                LEFT JOIN members m ON m.id = ccr.member_id
                LEFT JOIN chapters c ON c.id = ccr.requested_chapter_id
                WHERE 1=1 $where
                ORDER BY ccr.requested_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $title = $submitter . ' → ' . ($r['chapter_name'] ?? 'Chapter');
            return self::row(self::TYPE_CHAPTER_CHANGE, $r['id'], $title, strtolower((string) $r['status']), $r['requested_at'],
                $submitter, $r['email'] ?? null,
                'Requested chapter: ' . ($r['chapter_name'] ?? 'n/a') . ' (' . ($r['chapter_state'] ?? '') . ')',
                $r);
        }, $rows);
    }

    private static function fetchProfileChange(string $statusFilter): array
    {
        $pdo = Database::connection();
        $where = self::statusWhereUpper($statusFilter, 'mpr.status');
        $sql = "SELECT mpr.id, mpr.member_id, mpr.field_name, mpr.current_value, mpr.requested_value,
                       mpr.status, mpr.requested_at, mpr.feedback_message, mpr.rejection_reason,
                       m.first_name, m.last_name, m.email
                FROM member_profile_change_requests mpr
                LEFT JOIN members m ON m.id = mpr.member_id
                WHERE 1=1 $where
                ORDER BY mpr.requested_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $fieldLabel = self::PROFILE_FIELDS[$r['field_name']]['label'] ?? $r['field_name'];
            $title = $submitter . ' → ' . $fieldLabel;
            $summary = $fieldLabel . ': ' . self::formatProfileValue($r['current_value'])
                . ' → ' . self::formatProfileValue($r['requested_value']);
            return self::row(self::TYPE_PROFILE_CHANGE, $r['id'], $title, strtolower((string) $r['status']),
                $r['requested_at'], $submitter, $r['email'] ?? null, $summary, $r);
        }, $rows);
    }

    private static function fetchProfileUpdates(string $statusFilter): array
    {
        $pdo = Database::connection();
        // Informational entries only ever sit in PENDING (unread) or
        // ARCHIVED (read); the approved/rejected filters match nothing.
        $where = self::statusWhereUpper($statusFilter, 'mpu.status');
        $sql = "SELECT mpu.id, mpu.member_id, mpu.source, mpu.changes, mpu.has_contact_change,
                       mpu.status, mpu.created_at, mpu.reviewed_by, mpu.reviewed_at,
                       m.first_name, m.last_name, m.email
                FROM member_profile_updates mpu
                LEFT JOIN members m ON m.id = mpu.member_id
                WHERE 1=1 $where
                ORDER BY mpu.created_at DESC
                LIMIT 200";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(fn ($r) => self::profileUpdateRow($r), $rows);
    }

    /** Build a hub row for a member_profile_updates record. */
    private static function profileUpdateRow(array $r): array
    {
        $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $changes = json_decode((string) ($r['changes'] ?? ''), true);
        $changes = is_array($changes) ? $changes : [];
        $hasContact = !empty($r['has_contact_change']);

        $parts = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $label = (string) ($change['label'] ?? $change['field'] ?? 'Field');
            $old = self::formatProfileValue(isset($change['old']) ? (string) $change['old'] : '');
            $new = self::formatProfileValue(isset($change['new']) ? (string) $change['new'] : '');
            $parts[] = $label . ': ' . $old . ' → ' . $new;
            // Expose each change as its own raw field so the detail page's
            // metadata grid renders one entry per changed field.
            $r['changed ' . $label] = $old . ' → ' . $new;
        }
        unset($r['changes'], $r['has_contact_change']);

        $title = ($submitter !== '' ? $submitter : 'Member #' . (int) ($r['member_id'] ?? 0)) . ' updated their details';
        $summary = ($hasContact ? 'Contact details changed · ' : '') . implode(' · ', $parts);
        if ($summary === '') {
            $summary = 'Profile details updated.';
        }

        return self::row(self::TYPE_PROFILE_UPDATE, $r['id'], $title, strtolower((string) $r['status']),
            $r['created_at'], $submitter !== '' ? $submitter : null, $r['email'] ?? null, $summary, $r);
    }

    private static function fetchStoreOrders(string $statusFilter): array
    {
        $pdo = Database::connection();
        // Only surface orders waiting on admin review (manual / bank transfer)
        $statusWhere = '';
        if ($statusFilter === 'pending') {
            $statusWhere = "AND payment_status = 'unpaid' AND order_status = 'new'";
        }
        $sql = "SELECT so.id, so.member_id, so.total_amount, so.created_at,
                       so.order_status, so.payment_status, so.fulfillment_status,
                       m.first_name, m.last_name, m.email
                FROM store_orders so
                LEFT JOIN members m ON m.id = so.member_id
                WHERE 1=1 $statusWhere
                ORDER BY so.created_at DESC
                LIMIT 100";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return self::row(self::TYPE_STORE_ORDER, $r['id'], 'Order #' . $r['id'],
                $r['payment_status'] === 'unpaid' ? 'pending' : strtolower((string) $r['payment_status']),
                $r['created_at'],
                $submitter, $r['email'] ?? null,
                'Total: $' . number_format((float) $r['total_amount'], 2) . ' · ' . $r['payment_status'] . ' / ' . $r['order_status'],
                $r);
        }, $rows);
    }

    private static function fetchFeedback(string $statusFilter): array
    {
        $pdo = Database::connection();
        // Map hub status filters onto ticket statuses.
        $where = match ($statusFilter) {
            'pending'  => "AND f.status IN ('open','in_progress')",
            'approved' => "AND f.status = 'resolved'",
            'rejected' => "AND f.status = 'wont_fix'",
            'archived' => "AND f.status = 'archived'",
            default    => '',
        };
        $sql = "SELECT f.id, f.user_id, f.submitter_name, f.submitter_email, f.message,
                       f.page_url, f.user_agent, f.status, f.response, f.reviewed_by,
                       f.reviewed_at, f.created_at,
                       u.name AS user_name, u.email AS user_email
                FROM beta_feedback f
                LEFT JOIN users u ON u.id = f.user_id
                WHERE 1=1 $where
                ORDER BY f.created_at DESC
                LIMIT 200";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            // Display the ticket-style status visually as pending/approved/rejected for the hub.
            $displayStatus = match ((string) $r['status']) {
                'resolved' => 'approved',
                'wont_fix' => 'rejected',
                'archived' => 'archived',
                default    => 'pending',
            };
            $title = 'Ticket #' . $r['id'] . ' — ' . substr(strip_tags((string) $r['message']), 0, 60);
            $name = $r['submitter_name'] ?: $r['user_name'] ?: 'Anonymous';
            $email = $r['submitter_email'] ?: $r['user_email'] ?: null;
            $summary = ($r['page_url'] ? 'Page: ' . $r['page_url'] . ' · ' : '')
                     . substr((string) $r['message'], 0, 160);
            // Expose the underlying ticket status alongside the hub-style display status.
            $r['ticket_status'] = $r['status'];
            return self::row(self::TYPE_FEEDBACK, $r['id'], $title, $displayStatus, $r['created_at'],
                $name, $email, $summary, $r);
        }, $rows);
    }

    private static function fetchMembershipApplications(string $statusFilter): array
    {
        $pdo = Database::connection();
        $where = self::statusWhereUpper($statusFilter, 'a.status');
        $sql = "SELECT a.id, a.member_id, a.member_type, a.status, a.created_at,
                       m.first_name, m.last_name, m.email
                FROM membership_applications a
                LEFT JOIN members m ON m.id = a.member_id
                WHERE 1=1 $where
                ORDER BY a.created_at DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(function ($r) {
            $submitter = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return self::row(self::TYPE_MEMBERSHIP, $r['id'], $submitter ?: 'Member #' . $r['member_id'],
                strtolower((string) $r['status']), $r['created_at'],
                $submitter, $r['email'] ?? null,
                'Type: ' . $r['member_type'], $r);
        }, $rows);
    }

    // ─── helpers ───────────────────────────────────────────────────────

    private static function statusWhere(string $statusFilter, string $col = 'n.status'): string
    {
        return match ($statusFilter) {
            'pending'  => "AND $col = 'pending'",
            'approved' => "AND $col = 'approved'",
            'rejected' => "AND $col = 'rejected'",
            'archived' => "AND $col = 'archived'",
            default    => '',
        };
    }

    private static function statusWhereUpper(string $statusFilter, string $col): string
    {
        return match ($statusFilter) {
            'pending'  => "AND $col = 'PENDING'",
            'approved' => "AND $col = 'APPROVED'",
            'rejected' => "AND $col = 'REJECTED'",
            'archived' => "AND $col = 'ARCHIVED'",
            default    => '',
        };
    }

    private static function row(string $type, $id, ?string $title, ?string $status, ?string $submittedAt,
                                 ?string $submitterName, ?string $submitterEmail, ?string $summary,
                                 array $raw): array
    {
        $types = self::types();
        return [
            'type'             => $type,
            'type_label'       => $types[$type]['label'] ?? $type,
            'type_icon'        => $types[$type]['icon'] ?? 'inbox',
            'id'               => (int) $id,
            'title'            => $title ?? ('#' . $id),
            'status'           => strtolower((string) ($status ?? 'pending')),
            'submitted_at'     => $submittedAt,
            'submitter_name'   => $submitterName,
            'submitter_email'  => $submitterEmail,
            'summary'          => $summary,
            'detail_url'       => '/admin/requests/view.php?type=' . $type . '&id=' . (int) $id,
            'raw'              => $raw,
        ];
    }

    // ─── status update handlers ────────────────────────────────────────

    /**
     * Apply an action ('approve' | 'reject' | 'feedback') to a request.
     * Returns ['ok' => bool, 'message' => string, 'submitter_email' => ?string, 'submitter_name' => ?string, 'title' => ?string]
     */
    public static function applyAction(string $type, int $id, string $action, string $message, int $reviewerUserId): array
    {
        $pdo = Database::connection();

        $tableMap = [
            self::TYPE_NOTICE         => ['table' => 'notices',                     'pk' => 'id', 'enum' => 'lower'],
            self::TYPE_EVENT          => ['table' => 'calendar_events',             'pk' => 'id', 'enum' => 'lower'],
            self::TYPE_FALLEN_WINGS   => ['table' => 'fallen_wings',                'pk' => 'id', 'enum' => 'upper'],
            self::TYPE_CHAPTER_CHANGE => ['table' => 'chapter_change_requests',     'pk' => 'id', 'enum' => 'upper'],
            self::TYPE_PROFILE_CHANGE => ['table' => 'member_profile_change_requests', 'pk' => 'id', 'enum' => 'upper'],
            self::TYPE_PROFILE_UPDATE => ['table' => 'member_profile_updates',      'pk' => 'id', 'enum' => 'info'],
            self::TYPE_MEMBERSHIP     => ['table' => 'membership_applications',     'pk' => 'id', 'enum' => 'upper'],
            self::TYPE_MEMBER_OF_YEAR => ['table' => 'member_of_year_nominations',  'pk' => 'id', 'enum' => 'moy'],
            self::TYPE_STORE_ORDER    => ['table' => 'store_orders',                'pk' => 'id', 'enum' => 'store'],
            self::TYPE_FEEDBACK       => ['table' => 'beta_feedback',               'pk' => 'id', 'enum' => 'feedback'],
        ];

        if (!isset($tableMap[$type])) {
            return ['ok' => false, 'message' => 'Unknown request type'];
        }

        $cfg = $tableMap[$type];
        $row = self::find($type, $id);
        if (!$row) {
            return ['ok' => false, 'message' => 'Request not found'];
        }

        if ($cfg['enum'] === 'info' && $action === 'feedback') {
            return ['ok' => false, 'message' => 'This notification is informational only — there is nothing to send feedback on.'];
        }

        try {
            if ($action === 'approve') {
                self::updateStatus($pdo, $cfg, $id, 'approved', $message, $reviewerUserId);
            } elseif ($action === 'reject') {
                self::updateStatus($pdo, $cfg, $id, 'rejected', $message, $reviewerUserId);
            } elseif ($action === 'archive') {
                self::updateStatus($pdo, $cfg, $id, 'archived', $message, $reviewerUserId);
            } elseif ($action === 'feedback') {
                self::updateFeedback($pdo, $cfg, $id, $message, $reviewerUserId);
            } else {
                return ['ok' => false, 'message' => 'Unknown action'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }

        ActivityLogger::log('admin', $reviewerUserId, null, 'request.' . $action, [
            'request_type' => $type,
            'request_id'   => $id,
            'message'      => $message,
            'target_type'  => $type,
            'target_id'    => $id,
        ]);

        return [
            'ok' => true,
            'message' => 'Request ' . $action . 'd',
            'submitter_email' => $row['submitter_email'] ?? null,
            'submitter_name'  => $row['submitter_name'] ?? null,
            'title'           => $row['title'] ?? null,
            'type_label'      => $row['type_label'] ?? null,
        ];
    }

    private static function updateStatus(PDO $pdo, array $cfg, int $id, string $newStatus, string $message, int $reviewerUserId): void
    {
        $table = $cfg['table'];
        $enum = $cfg['enum'];

        if ($enum === 'info') {
            // Informational entries have no approval flow — any status action
            // simply marks them as read (archived).
            $sql = "UPDATE $table SET status = 'ARCHIVED', reviewed_by = :r, reviewed_at = NOW() WHERE id = :id";
            $pdo->prepare($sql)->execute(['r' => $reviewerUserId, 'id' => $id]);
            return;
        }

        if ($enum === 'lower') {
            // calendar_events: existing render code filters status='published', so use that
            // when approving. Notices use 'approved' as configured by the migration.
            $effectiveStatus = $newStatus;
            if ($table === 'calendar_events' && $newStatus === 'approved') {
                $effectiveStatus = 'published';
            }
            $sql = "UPDATE $table SET status = :status, feedback_message = :msg, reviewed_by = :reviewer, reviewed_at = NOW() WHERE id = :id";
            $pdo->prepare($sql)->execute([
                'status' => $effectiveStatus,
                'msg' => $message !== '' ? $message : null,
                'reviewer' => $reviewerUserId,
                'id' => $id,
            ]);
            return;
        }

        if ($enum === 'upper') {
            $upper = strtoupper($newStatus);
            // Different tables use different audit columns; build conservatively
            if ($table === 'membership_applications') {
                if ($newStatus === 'approved') {
                    $sql = "UPDATE $table SET status = :s, approved_by = :r, approved_at = NOW(), notes = COALESCE(notes,'') WHERE id = :id";
                } else {
                    $sql = "UPDATE $table SET status = :s, rejected_by = :r, rejected_at = NOW(), rejection_reason = :msg WHERE id = :id";
                }
                $params = ['s' => $upper, 'r' => $reviewerUserId, 'id' => $id];
                if ($newStatus === 'rejected') $params['msg'] = $message;
                $pdo->prepare($sql)->execute($params);
                return;
            }
            if ($table === 'fallen_wings') {
                $sql = "UPDATE $table SET status = :s, approved_by = :r, approved_at = NOW(), feedback_message = :msg WHERE id = :id";
                $pdo->prepare($sql)->execute(['s' => $upper, 'r' => $reviewerUserId, 'msg' => $message !== '' ? $message : null, 'id' => $id]);
                return;
            }
            if ($table === 'chapter_change_requests') {
                $sql = "UPDATE $table SET status = :s, approved_by = :r, approved_at = NOW(), feedback_message = :msg, rejection_reason = :rr WHERE id = :id";
                $pdo->prepare($sql)->execute([
                    's' => $upper, 'r' => $reviewerUserId,
                    'msg' => $message !== '' ? $message : null,
                    'rr' => $newStatus === 'rejected' ? $message : null,
                    'id' => $id,
                ]);
                return;
            }
            if ($table === 'member_profile_change_requests') {
                // Side effect: on approval, write the requested value back to
                // the member's row before flipping the request status.
                if ($newStatus === 'approved') {
                    $reqStmt = $pdo->prepare("SELECT member_id, field_name, requested_value FROM $table WHERE id = :id LIMIT 1");
                    $reqStmt->execute(['id' => $id]);
                    $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
                    if ($req && isset(self::PROFILE_FIELDS[$req['field_name']])) {
                        $col = $req['field_name'];
                        $stored = self::sanitizeProfileValue($col, $req['requested_value']);
                        $upd = $pdo->prepare("UPDATE members SET $col = :v, updated_at = NOW() WHERE id = :mid");
                        $upd->execute(['v' => $stored, 'mid' => (int) $req['member_id']]);
                    }
                }
                $sql = "UPDATE $table SET status = :s, approved_by = :r, approved_at = NOW(), feedback_message = :msg, rejection_reason = :rr WHERE id = :id";
                $pdo->prepare($sql)->execute([
                    's' => $upper, 'r' => $reviewerUserId,
                    'msg' => $message !== '' ? $message : null,
                    'rr' => $newStatus === 'rejected' ? $message : null,
                    'id' => $id,
                ]);
                return;
            }
        }

        if ($enum === 'moy') {
            $newMoy = $newStatus === 'approved' ? 'reviewed' : ($newStatus === 'rejected' ? 'reviewed' : 'new');
            $sql = "UPDATE $table SET status = :s, feedback_message = :msg WHERE id = :id";
            $pdo->prepare($sql)->execute(['s' => $newMoy, 'msg' => $message !== '' ? $message : null, 'id' => $id]);
            return;
        }

        if ($enum === 'store') {
            if ($newStatus === 'approved') {
                $sql = "UPDATE $table SET payment_status = 'paid', order_status = 'processing' WHERE id = :id";
                $pdo->prepare($sql)->execute(['id' => $id]);
            } else {
                $sql = "UPDATE $table SET order_status = 'cancelled' WHERE id = :id";
                $pdo->prepare($sql)->execute(['id' => $id]);
            }
            return;
        }

        if ($enum === 'feedback') {
            // approve = resolved, reject = wont_fix, archive = archived
            $ticketStatus = match ($newStatus) {
                'approved' => 'resolved',
                'rejected' => 'wont_fix',
                'archived' => 'archived',
                default    => $newStatus,
            };
            $sql = "UPDATE $table SET status = :s, response = :msg, reviewed_by = :r, reviewed_at = NOW() WHERE id = :id";
            $pdo->prepare($sql)->execute([
                's'   => $ticketStatus,
                'msg' => $message !== '' ? $message : null,
                'r'   => $reviewerUserId,
                'id'  => $id,
            ]);
            return;
        }
    }

    private static function updateFeedback(PDO $pdo, array $cfg, int $id, string $message, int $reviewerUserId): void
    {
        $table = $cfg['table'];

        // Beta feedback tickets: store reply in response column, move to in_progress.
        if ($cfg['enum'] === 'feedback') {
            $sql = "UPDATE $table SET response = :msg, status = IF(status = 'open','in_progress',status), reviewed_by = :r, reviewed_at = NOW() WHERE id = :id";
            $pdo->prepare($sql)->execute(['msg' => $message, 'r' => $reviewerUserId, 'id' => $id]);
            return;
        }

        $sql = "UPDATE $table SET feedback_message = :msg WHERE id = :id";
        // Some tables also have reviewed_by/reviewed_at
        if (in_array($cfg['enum'], ['lower'], true)) {
            $sql = "UPDATE $table SET feedback_message = :msg, reviewed_by = :r, reviewed_at = NOW() WHERE id = :id";
            $pdo->prepare($sql)->execute(['msg' => $message, 'r' => $reviewerUserId, 'id' => $id]);
            return;
        }
        $pdo->prepare($sql)->execute(['msg' => $message, 'id' => $id]);
    }

    // ─── profile update (informational) helpers ────────────────────────────

    /**
     * Record an informational "member updated their details" entry for the
     * admin hub. $changes is a list of ['field' => column, 'label' => display
     * name, 'old' => previous value, 'new' => saved value]; entries whose old
     * and new values match are dropped. Returns the new row id, or null when
     * nothing actually changed or the table is missing (pre-migration).
     */
    public static function recordProfileUpdate(int $memberId, array $changes, string $source = 'member'): ?int
    {
        if ($memberId <= 0) {
            return null;
        }
        $clean = [];
        $hasContact = false;
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = trim((string) ($change['field'] ?? ''));
            $old = isset($change['old']) ? trim((string) $change['old']) : '';
            $new = isset($change['new']) ? trim((string) $change['new']) : '';
            if ($field === '' || $old === $new) {
                continue;
            }
            $clean[] = [
                'field' => $field,
                'label' => (string) ($change['label'] ?? ucwords(str_replace('_', ' ', $field))),
                'old'   => $old,
                'new'   => $new,
            ];
            if (in_array($field, self::PROFILE_UPDATE_CONTACT_FIELDS, true)) {
                $hasContact = true;
            }
        }
        if ($clean === []) {
            return null;
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO member_profile_updates (member_id, source, changes, has_contact_change, status, created_at)
                 VALUES (:member_id, :source, :changes, :has_contact, "PENDING", NOW())'
            );
            $stmt->execute([
                'member_id'   => $memberId,
                'source'      => $source,
                'changes'     => json_encode($clean, JSON_UNESCAPED_UNICODE),
                'has_contact' => $hasContact ? 1 : 0,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─── profile change request helpers ────────────────────────────────────

    /**
     * Create a new profile-change request for a member. Returns the new
     * request id, or null if the inputs are invalid / the table is missing.
     */
    public static function submitProfileChange(int $memberId, string $fieldName, ?string $requestedValue): ?int
    {
        if ($memberId <= 0 || !isset(self::PROFILE_FIELDS[$fieldName])) {
            return null;
        }
        $sanitized = self::sanitizeProfileValue($fieldName, $requestedValue);
        if ($sanitized === null) {
            return null;
        }
        try {
            $pdo = Database::connection();
            // Capture the current value for audit / comparison.
            $current = null;
            try {
                $col = $fieldName; // keys are whitelisted in PROFILE_FIELDS
                $cur = $pdo->prepare("SELECT $col FROM members WHERE id = :id LIMIT 1");
                $cur->execute(['id' => $memberId]);
                $current = $cur->fetchColumn();
                if ($current === false) {
                    $current = null;
                }
            } catch (Throwable $e) {}

            $stmt = $pdo->prepare(
                'INSERT INTO member_profile_change_requests (member_id, field_name, current_value, requested_value, status, requested_at)
                 VALUES (:member_id, :field_name, :current_value, :requested_value, "PENDING", NOW())'
            );
            $stmt->execute([
                'member_id' => $memberId,
                'field_name' => $fieldName,
                'current_value' => $current !== null ? (string) $current : null,
                'requested_value' => $sanitized,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Return the most recent pending profile-change request for a given
     * member and field, if any. Used to surface the in-flight request on
     * the member's profile UI.
     */
    public static function latestPendingProfileChange(int $memberId, string $fieldName): ?array
    {
        if ($memberId <= 0 || $fieldName === '') {
            return null;
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT id, member_id, field_name, current_value, requested_value, status, requested_at
                 FROM member_profile_change_requests
                 WHERE member_id = :mid AND field_name = :fn AND status = "PENDING"
                 ORDER BY requested_at DESC LIMIT 1'
            );
            $stmt->execute(['mid' => $memberId, 'fn' => $fieldName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Normalise a requested profile value before storing or applying it.
     * Returns null if the value is invalid for the field, or '' to mean
     * "clear the field".
     */
    private static function sanitizeProfileValue(string $fieldName, ?string $raw): ?string
    {
        if (!isset(self::PROFILE_FIELDS[$fieldName])) {
            return null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $type = self::PROFILE_FIELDS[$fieldName]['input_type'] ?? 'text';
        if ($type === 'date') {
            // Accept YYYY-MM-DD only; reject anything else.
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            if (!$dt || $dt->format('Y-m-d') !== $raw) {
                return null;
            }
            return $dt->format('Y-m-d');
        }
        return $raw;
    }

    /** Render a stored profile value for display in admin / member UIs. */
    public static function formatProfileValue(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '—';
        }
        // Format common date-only values nicely; fall back to raw.
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
        if ($dt && $dt->format('Y-m-d') === substr($raw, 0, 10)) {
            return $dt->format('j M Y');
        }
        return $raw;
    }
}
