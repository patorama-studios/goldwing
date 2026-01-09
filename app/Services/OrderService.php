<?php
namespace App\Services;

class OrderService
{
    public static function createOrder(array $order, array $items): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO orders (user_id, status, order_type, currency, subtotal, tax_total, shipping_total, total, stripe_session_id, stripe_payment_intent_id, stripe_charge_id, channel_id, shipping_required, shipping_address_json, created_at) VALUES (:user_id, :status, :order_type, :currency, :subtotal, :tax_total, :shipping_total, :total, :stripe_session_id, :stripe_payment_intent_id, :stripe_charge_id, :channel_id, :shipping_required, :shipping_address_json, NOW())');
        $stmt->execute([
            'user_id' => $order['user_id'],
            'status' => $order['status'] ?? 'pending',
            'order_type' => $order['order_type'],
            'currency' => $order['currency'] ?? 'AUD',
            'subtotal' => $order['subtotal'] ?? 0,
            'tax_total' => $order['tax_total'] ?? 0,
            'shipping_total' => $order['shipping_total'] ?? 0,
            'total' => $order['total'] ?? 0,
            'stripe_session_id' => $order['stripe_session_id'] ?? null,
            'stripe_payment_intent_id' => $order['stripe_payment_intent_id'] ?? null,
            'stripe_charge_id' => $order['stripe_charge_id'] ?? null,
            'channel_id' => $order['channel_id'],
            'shipping_required' => (int) ($order['shipping_required'] ?? 0),
            'shipping_address_json' => $order['shipping_address_json'] ?? null,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        self::insertItems($orderId, $items);
        return $orderId;
    }

    public static function insertItems(int $orderId, array $items): void
    {
        if (!$items) {
            return;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, quantity, unit_price, is_physical, created_at) VALUES (:order_id, :product_id, :name, :quantity, :unit_price, :is_physical, NOW())');
        foreach ($items as $item) {
            $stmt->execute([
                'order_id' => $orderId,
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'is_physical' => (int) ($item['is_physical'] ?? 0),
            ]);
        }
    }

    public static function getOrderById(int $orderId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    public static function getOrderItems(int $orderId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function updateStripeSession(int $orderId, string $sessionId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE orders SET stripe_session_id = :session_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['session_id' => $sessionId, 'id' => $orderId]);
    }

    public static function markPaid(int $orderId, string $paymentIntentId, ?string $chargeId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE orders SET status = "paid", payment_status = "accepted", payment_method = CASE WHEN payment_method IS NULL OR payment_method = "" THEN "stripe" ELSE payment_method END, stripe_payment_intent_id = :payment_intent_id, stripe_charge_id = :charge_id, paid_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $chargeId,
            'id' => $orderId,
        ]);
    }

    public static function markRefunded(int $orderId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE orders SET status = "refunded", payment_status = "refunded", refunded_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
    }
}
