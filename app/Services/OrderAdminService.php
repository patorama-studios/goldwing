<?php
namespace App\Services;

use PDO;
use Throwable;

/**
 * Soft-void / hard-delete helpers for both order tables.
 *
 * Both `orders` (membership + store-mirror rows) and `store_orders` (store
 * fulfillment rows) carry the voided_at / voided_by_user_id / voided_reason
 * columns added in migration 029.
 *
 * Void = set voided_at and hide from default admin lists. Reversible.
 * Delete = hard DELETE. Cascading FKs clean up items/events/refunds.
 *
 * Permission gating is the caller's job — these methods just do the work.
 */
class OrderAdminService
{
    public static function voidStoreOrder(int $orderId, int $actorUserId, ?string $reason = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE store_orders
             SET voided_at = NOW(),
                 voided_by_user_id = :actor,
                 voided_reason = :reason,
                 updated_at = NOW()
             WHERE id = :id AND voided_at IS NULL'
        );
        $stmt->execute([
            'actor' => $actorUserId > 0 ? $actorUserId : null,
            'reason' => $reason !== null && $reason !== '' ? $reason : null,
            'id' => $orderId,
        ]);

        if (self::tableExists($pdo, 'store_order_events')) {
            $eventStmt = $pdo->prepare(
                'INSERT INTO store_order_events (order_id, event_type, message, metadata_json, created_by_user_id, created_at)
                 VALUES (:order_id, :event_type, :message, :metadata, :actor, NOW())'
            );
            $eventStmt->execute([
                'order_id' => $orderId,
                'event_type' => 'order.voided',
                'message' => 'Order voided' . ($reason !== null && $reason !== '' ? ': ' . substr($reason, 0, 200) : '.'),
                'metadata' => json_encode(['reason' => $reason]),
                'actor' => $actorUserId > 0 ? $actorUserId : null,
            ]);
        }
    }

    public static function unvoidStoreOrder(int $orderId, int $actorUserId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE store_orders
             SET voided_at = NULL,
                 voided_by_user_id = NULL,
                 voided_reason = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $orderId]);

        if (self::tableExists($pdo, 'store_order_events')) {
            $eventStmt = $pdo->prepare(
                'INSERT INTO store_order_events (order_id, event_type, message, metadata_json, created_by_user_id, created_at)
                 VALUES (:order_id, :event_type, :message, NULL, :actor, NOW())'
            );
            $eventStmt->execute([
                'order_id' => $orderId,
                'event_type' => 'order.unvoided',
                'message' => 'Order restored from voided.',
                'actor' => $actorUserId > 0 ? $actorUserId : null,
            ]);
        }
    }

    public static function deleteStoreOrder(int $orderId): void
    {
        $pdo = Database::connection();
        // store_order_items, store_order_events, store_refunds, store_shipments
        // all use ON DELETE CASCADE on order_id, so this single DELETE wipes them.
        $stmt = $pdo->prepare('DELETE FROM store_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
    }

    public static function voidMembershipOrder(int $orderId, int $actorUserId, ?string $reason = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE orders
             SET voided_at = NOW(),
                 voided_by_user_id = :actor,
                 voided_reason = :reason,
                 updated_at = NOW()
             WHERE id = :id AND voided_at IS NULL'
        );
        $stmt->execute([
            'actor' => $actorUserId > 0 ? $actorUserId : null,
            'reason' => $reason !== null && $reason !== '' ? $reason : null,
            'id' => $orderId,
        ]);
    }

    public static function unvoidMembershipOrder(int $orderId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE orders
             SET voided_at = NULL,
                 voided_by_user_id = NULL,
                 voided_reason = NULL,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $orderId]);
    }

    public static function deleteMembershipOrder(int $orderId): void
    {
        $pdo = Database::connection();
        // order_items, invoices use ON DELETE CASCADE on order_id, so the
        // DELETE below also wipes those child rows.
        //
        // Also opportunistically clean up the linked membership_periods row
        // when it's still in PENDING_PAYMENT and no other orders reference it.
        // Without this, /member/index.php?page=billing sees the orphan period
        // on the next page load and auto-creates a fresh pending order (with a
        // new order_number) via MembershipOrderService::createMembershipOrder,
        // which makes admin "Delete" feel broken — the order keeps coming back.
        //
        // renewal_reminders has a no-action FK to membership_periods.id, so
        // its rows are wiped first.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT membership_period_id FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $periodId = (int) ($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
            $stmt->execute(['id' => $orderId]);

            if ($periodId > 0) {
                $stmt = $pdo->prepare("SELECT status FROM membership_periods WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $periodId]);
                $periodStatus = strtoupper((string) ($stmt->fetchColumn() ?: ''));

                if ($periodStatus === 'PENDING_PAYMENT') {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE membership_period_id = :id');
                    $stmt->execute(['id' => $periodId]);
                    $remainingRefs = (int) $stmt->fetchColumn();

                    if ($remainingRefs === 0) {
                        $stmt = $pdo->prepare('DELETE FROM renewal_reminders WHERE period_id = :id');
                        $stmt->execute(['id' => $periodId]);
                        $stmt = $pdo->prepare('DELETE FROM membership_periods WHERE id = :id');
                        $stmt->execute(['id' => $periodId]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Send the `order_voided` notification for a row in the unified `orders`
     * table. Callers opt in after calling voidMembershipOrder() so the void/
     * delete methods themselves stay pure data ops.
     */
    public static function sendOrderVoidedNotification(int $orderId, ?string $reason): void
    {
        if ($orderId <= 0) {
            return;
        }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('SELECT id, order_number, order_type, member_id, user_id FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return;
        }
        if (!$row) {
            return;
        }
        $email = '';
        $firstName = '';
        $lastName = '';
        if (!empty($row['member_id'])) {
            $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $row['member_id']]);
            $m = $stmt->fetch();
            if ($m) {
                $email = (string) ($m['email'] ?? '');
                $firstName = (string) ($m['first_name'] ?? '');
                $lastName = (string) ($m['last_name'] ?? '');
            }
        }
        if ($email === '' && !empty($row['user_id'])) {
            $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $row['user_id']]);
            $u = $stmt->fetch();
            if ($u) {
                $email = (string) ($u['email'] ?? '');
                if ($firstName === '' && !empty($u['name'])) {
                    $firstName = (string) $u['name'];
                }
            }
        }
        if ($email === '') {
            return;
        }
        $typeRaw = (string) ($row['order_type'] ?? '');
        $typeLabel = $typeRaw === 'membership' ? 'membership' : ($typeRaw === 'store' ? 'store' : 'order');
        $reasonText = trim((string) ($reason ?? ''));
        if ($reasonText === '') {
            $reasonText = 'No reason provided.';
        }
        NotificationService::dispatch('order_voided', [
            'primary_email' => $email,
            'admin_emails' => NotificationService::getAdminEmails(),
            'member_name' => trim($firstName . ' ' . $lastName),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'order_type_label' => $typeLabel,
            'void_reason' => NotificationService::escape($reasonText),
        ]);
    }

    /**
     * Same as sendOrderVoidedNotification but for rows in the separate
     * `store_orders` table (used by /admin/store/orders bulk void and the
     * single-order view).
     */
    public static function sendStoreOrderVoidedNotification(int $storeOrderId, ?string $reason): void
    {
        if ($storeOrderId <= 0) {
            return;
        }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare('SELECT o.id, o.order_number, o.customer_email, o.customer_name, m.first_name, m.last_name, m.email AS member_email FROM store_orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.id = :id LIMIT 1');
            $stmt->execute(['id' => $storeOrderId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return;
        }
        if (!$row) {
            return;
        }
        $email = (string) ($row['customer_email'] ?? '');
        if ($email === '') {
            $email = (string) ($row['member_email'] ?? '');
        }
        if ($email === '') {
            return;
        }
        $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        if ($name === '') {
            $name = (string) ($row['customer_name'] ?? '');
        }
        $reasonText = trim((string) ($reason ?? ''));
        if ($reasonText === '') {
            $reasonText = 'No reason provided.';
        }
        NotificationService::dispatch('order_voided', [
            'primary_email' => $email,
            'admin_emails' => NotificationService::getAdminEmails(),
            'member_name' => $name,
            'order_number' => (string) ($row['order_number'] ?? ''),
            'order_type_label' => 'store',
            'void_reason' => NotificationService::escape($reasonText),
        ]);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
