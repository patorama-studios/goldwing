<?php
namespace App\Services;

use PDO;
use RuntimeException;

class RefundService
{
    private static ?bool $refundsTableAvailable = null;
    private static ?bool $ordersTableAvailable = null;
    private static array $orderColumnCache = [];

    private static function hasRefundsTable(PDO $pdo): bool
    {
        if (self::$refundsTableAvailable !== null) {
            return self::$refundsTableAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'store_refunds'");
            self::$refundsTableAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$refundsTableAvailable = false;
        }

        return self::$refundsTableAvailable;
    }

    private static function hasOrdersTable(PDO $pdo): bool
    {
        if (self::$ordersTableAvailable !== null) {
            return self::$ordersTableAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'store_orders'");
            self::$ordersTableAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$ordersTableAvailable = false;
        }

        return self::$ordersTableAvailable;
    }

    private static function hasOrderColumn(PDO $pdo, string $column): bool
    {
        if (array_key_exists($column, self::$orderColumnCache)) {
            return self::$orderColumnCache[$column];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM store_orders LIKE " . $pdo->quote($column));
            self::$orderColumnCache[$column] = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$orderColumnCache[$column] = false;
        }

        return self::$orderColumnCache[$column];
    }

    public static function listByMember(int $memberId, int $limit = 25): array
    {
        $pdo = Database::connection();
        if (!self::hasRefundsTable($pdo)) {
            return [];
        }
        $select = 'r.*';
        if (self::hasOrdersTable($pdo) && self::hasOrderColumn($pdo, 'order_number')) {
            $select .= ', o.order_number';
            $join = ' LEFT JOIN store_orders o ON o.id = r.order_id';
        } else {
            $select .= ', NULL AS order_number';
            $join = '';
        }
        $stmt = $pdo->prepare('SELECT ' . $select . ' FROM store_refunds r' . $join . ' WHERE r.member_id = :member_id ORDER BY r.created_at DESC LIMIT :limit');
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function processRefund(int $orderId, int $memberId, int $amountCents, string $reason, int $adminUserId): array
    {
        $order = OrderRepository::getById($orderId);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
        $remaining = OrderRepository::calculateRefundableCents($orderId);
        if ($amountCents <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }
        if ($amountCents > $remaining) {
            throw new RuntimeException('Refund exceeds refundable total (remaining ' . self::formatCurrency($remaining) . ').');
        }
        $intent = $order['stripe_payment_intent_id'] ?? null;
        if (!$intent) {
            throw new RuntimeException('Stripe payment intent is not available for this order.');
        }
        $pdo = Database::connection();
        if (!self::hasRefundsTable($pdo)) {
            throw new RuntimeException('Refunds table is missing. Run the store admin migration to create store_refunds.');
        }

        ActivityLogger::log('admin', $adminUserId, $memberId, 'refund.requested', [
            'order_id' => $orderId,
            'amount_cents' => $amountCents,
            'reason' => $reason,
        ]);

        $refund = StripeService::createRefund($intent, $amountCents);
        if (empty($refund) || empty($refund['id'])) {
            ActivityLogger::log('admin', $adminUserId, $memberId, 'refund.failed', [
                'order_id' => $orderId,
                'amount_cents' => $amountCents,
                'reason' => $reason,
            ]);
            throw new RuntimeException('Stripe refund could not be completed.');
        }

        $stmt = $pdo->prepare('INSERT INTO store_refunds (order_id, member_id, amount_cents, reason, stripe_refund_id, status, created_by_user_id, created_at) VALUES (:order_id, :member_id, :amount_cents, :reason, :stripe_refund_id, "processed", :admin_id, NOW())');
        $stmt->execute([
            'order_id' => $orderId,
            'member_id' => $memberId,
            'amount_cents' => $amountCents,
            'reason' => trim($reason),
            'stripe_refund_id' => $refund['id'],
            'admin_id' => $adminUserId,
        ]);
        $refundId = (int) $pdo->lastInsertId();

        $remainingAfter = $remaining - $amountCents;
        $paymentStatus = $remainingAfter <= 0 ? 'refunded' : 'partial_refund';
        $status = $remainingAfter <= 0 ? 'refunded' : 'paid';
        $orderStatus = $remainingAfter <= 0 ? 'cancelled' : ($order['order_status'] ?? 'processing');

        $stmt = $pdo->prepare('UPDATE store_orders SET status = :status, payment_status = :payment_status, order_status = :order_status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'id' => $orderId,
        ]);

        store_add_order_event($pdo, $orderId, 'refund.processed', $paymentStatus === 'refunded' ? 'Full refund processed.' : 'Partial refund processed.', $adminUserId, [
            'refund_id' => $refundId,
            'stripe_refund_id' => $refund['id'],
            'amount_cents' => $amountCents,
            'reason' => trim($reason),
        ]);

        ActivityLogger::log('admin', $adminUserId, $memberId, 'refund.processed', [
            'order_id' => $orderId,
            'refund_id' => $refundId,
            'stripe_refund_id' => $refund['id'],
            'amount_cents' => $amountCents,
        ]);
        $safeRefundId = htmlspecialchars((string) $refundId, ENT_QUOTES, 'UTF-8');
        $safeAdminId = htmlspecialchars((string) $adminUserId, ENT_QUOTES, 'UTF-8');
        SecurityAlertService::send('refund_created', 'Security alert: refund created', '<p>Refund #' . $safeRefundId . ' created by user ID ' . $safeAdminId . '.</p>');

        if (!empty($order['customer_email'])) {
            NotificationService::dispatch('store_refund_processed', [
                'primary_email' => $order['customer_email'],
                'admin_emails' => NotificationService::getAdminEmails(store_get_settings()['notification_emails'] ?? ''),
                'order_number' => NotificationService::escape((string) ($order['order_number'] ?? $orderId)),
                'refund_amount' => NotificationService::escape(self::formatCurrency($amountCents)),
                'refund_reason' => NotificationService::escape(trim($reason)),
            ]);
        }

        return [
            'refund_id' => $refundId,
            'stripe_refund_id' => $refund['id'],
            'remaining_refundable_cents' => $remainingAfter,
        ];
    }

    private static function formatCurrency(int $cents): string
    {
        return 'A$' . number_format($cents / 100, 2);
    }
}
