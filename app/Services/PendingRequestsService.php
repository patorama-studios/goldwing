<?php
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
    public const TYPE_STORE_ORDER         = 'store_order';
    public const TYPE_MEMBERSHIP          = 'membership_application';

    public static function types(): array
    {
        return [
            self::TYPE_NOTICE              => ['label' => 'Notice',                 'icon' => 'campaign'],
            self::TYPE_EVENT               => ['label' => 'Event',                  'icon' => 'event'],
            self::TYPE_MEMBER_OF_YEAR      => ['label' => 'Member of the Year',     'icon' => 'emoji_events'],
            self::TYPE_FALLEN_WINGS        => ['label' => 'Fallen Wings',           'icon' => 'military_tech'],
            self::TYPE_CHAPTER_CHANGE      => ['label' => 'Chapter Change',         'icon' => 'swap_horiz'],
            self::TYPE_STORE_ORDER         => ['label' => 'Store Order',            'icon' => 'storefront'],
            self::TYPE_MEMBERSHIP          => ['label' => 'Membership Application', 'icon' => 'how_to_reg'],
        ];
    }

    /**
     * Return a unified list of all pending requests across all types.
     * Each row: [type, id, title, submitted_by, submitted_at, status, detail_url, summary]
     */
    public static function all(?string $typeFilter = null, string $statusFilter = 'pending'): array
    {
        $items = [];
        $methods = [
            self::TYPE_NOTICE         => 'fetchNotices',
            self::TYPE_EVENT          => 'fetchEvents',
            self::TYPE_MEMBER_OF_YEAR => 'fetchMemberOfYear',
            self::TYPE_FALLEN_WINGS   => 'fetchFallenWings',
            self::TYPE_CHAPTER_CHANGE => 'fetchChapterChange',
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
        $rows = self::all($type, 'all');
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
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
                       c.name AS chapter_name, c.state AS chapter_state
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
        if ($statusFilter === 'pending') {
            return "AND $col = 'pending'";
        }
        if ($statusFilter === 'approved') {
            return "AND $col = 'approved'";
        }
        if ($statusFilter === 'rejected') {
            return "AND $col = 'rejected'";
        }
        return '';
    }

    private static function statusWhereUpper(string $statusFilter, string $col): string
    {
        if ($statusFilter === 'pending') {
            return "AND $col = 'PENDING'";
        }
        if ($statusFilter === 'approved') {
            return "AND $col = 'APPROVED'";
        }
        if ($statusFilter === 'rejected') {
            return "AND $col = 'REJECTED'";
        }
        return '';
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
            self::TYPE_MEMBERSHIP     => ['table' => 'membership_applications',     'pk' => 'id', 'enum' => 'upper'],
            self::TYPE_MEMBER_OF_YEAR => ['table' => 'member_of_year_nominations',  'pk' => 'id', 'enum' => 'moy'],
            self::TYPE_STORE_ORDER    => ['table' => 'store_orders',                'pk' => 'id', 'enum' => 'store'],
        ];

        if (!isset($tableMap[$type])) {
            return ['ok' => false, 'message' => 'Unknown request type'];
        }

        $cfg = $tableMap[$type];
        $row = self::find($type, $id);
        if (!$row) {
            return ['ok' => false, 'message' => 'Request not found'];
        }

        try {
            if ($action === 'approve') {
                self::updateStatus($pdo, $cfg, $id, 'approved', $message, $reviewerUserId);
            } elseif ($action === 'reject') {
                self::updateStatus($pdo, $cfg, $id, 'rejected', $message, $reviewerUserId);
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
    }

    private static function updateFeedback(PDO $pdo, array $cfg, int $id, string $message, int $reviewerUserId): void
    {
        $table = $cfg['table'];
        $sql = "UPDATE $table SET feedback_message = :msg WHERE id = :id";
        // Some tables also have reviewed_by/reviewed_at
        if (in_array($cfg['enum'], ['lower'], true)) {
            $sql = "UPDATE $table SET feedback_message = :msg, reviewed_by = :r, reviewed_at = NOW() WHERE id = :id";
            $pdo->prepare($sql)->execute(['msg' => $message, 'r' => $reviewerUserId, 'id' => $id]);
            return;
        }
        $pdo->prepare($sql)->execute(['msg' => $message, 'id' => $id]);
    }
}
