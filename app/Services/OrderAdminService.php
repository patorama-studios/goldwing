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
        // order_items, invoices use ON DELETE CASCADE on order_id.
        $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
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
