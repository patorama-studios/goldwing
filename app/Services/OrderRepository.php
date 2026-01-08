<?php
namespace App\Services;

use PDO;

class OrderRepository
{
    private static ?bool $ordersTableAvailable = null;
    private static array $orderColumnCache = [];
    private static ?bool $refundsTableAvailable = null;

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

    public static function listByMember(int $memberId, int $limit = 25): array
    {
        $pdo = Database::connection();
        if (!self::hasOrdersTable($pdo) || !self::hasOrderColumn($pdo, 'member_id')) {
            return [];
        }

        $desired = [
            'id',
            'order_number',
            'status',
            'subtotal',
            'total',
            'stripe_payment_intent_id',
            'stripe_charge_id',
            'created_at',
            'updated_at',
        ];
        $selectColumns = [];
        foreach ($desired as $column) {
            if (self::hasOrderColumn($pdo, $column)) {
                $selectColumns[] = $column;
            }
        }
        if (empty($selectColumns)) {
            $selectColumns[] = 'id';
        }

        $stmt = $pdo->prepare('SELECT ' . implode(', ', $selectColumns) . ' FROM store_orders WHERE member_id = :member_id ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$order) {
            $order['currency'] = 'AUD';
            $order['total_cents'] = self::toCents($order['total'] ?? 0);
        }
        return $orders;
    }

    public static function getById(int $orderId): ?array
    {
        $pdo = Database::connection();
        if (!self::hasOrdersTable($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        $order['total_cents'] = self::toCents($order['total'] ?? 0);
        return $order;
    }

    public static function updateStatus(int $orderId, string $status): void
    {
        $pdo = Database::connection();
        if (!self::hasOrdersTable($pdo) || !self::hasOrderColumn($pdo, 'status')) {
            return;
        }
        $stmt = $pdo->prepare('UPDATE store_orders SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $orderId]);
    }

    public static function attachToMember(int $orderId, int $memberId): void
    {
        $pdo = Database::connection();
        if (!self::hasOrdersTable($pdo) || !self::hasOrderColumn($pdo, 'member_id')) {
            return;
        }
        $stmt = $pdo->prepare('UPDATE store_orders SET member_id = :member_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['member_id' => $memberId, 'id' => $orderId]);
    }

    public static function appendAdminNote(int $orderId, string $note): void
    {
        $pdo = Database::connection();
        if (!self::hasOrdersTable($pdo) || !self::hasOrderColumn($pdo, 'admin_notes')) {
            return;
        }
        $stmt = $pdo->prepare("UPDATE store_orders SET admin_notes = CASE WHEN admin_notes IS NULL OR admin_notes = '' THEN :note ELSE CONCAT(admin_notes, '\n', :note) END, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['note' => $note, 'id' => $orderId]);
    }

    public static function calculateRefundableCents(int $orderId): int
    {
        $order = self::getById($orderId);
        if (!$order) {
            return 0;
        }
        $total = self::toCents($order['total']);
        $pdo = Database::connection();
        if (!self::hasRefundsTable($pdo)) {
            return $total;
        }
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_cents), 0) as refunded FROM store_refunds WHERE order_id = :order_id AND status = "processed"');
        $stmt->execute(['order_id' => $orderId]);
        $refunded = (int) $stmt->fetchColumn();
        return max(0, $total - $refunded);
    }

    public static function refreshFromStripe(int $orderId): ?array
    {
        $order = self::getById($orderId);
        if (!$order || empty($order['stripe_payment_intent_id'])) {
            return null;
        }
        $intent = StripeService::retrievePaymentIntent($order['stripe_payment_intent_id']);
        if (!$intent || empty($intent['status'])) {
            return null;
        }
        $mapped = self::mapStripeStatus((string) $intent['status']);
        if ($mapped !== $order['status']) {
            self::updateStatus($orderId, $mapped);
        }
        return $intent;
    }

    private static function mapStripeStatus(string $status): string
    {
        return match ($status) {
            'succeeded', 'processing', 'requires_capture' => 'paid',
            'canceled' => 'cancelled',
            default => 'pending',
        };
    }

    private static function toCents($amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
