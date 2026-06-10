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

    /**
     * Resolve a PaymentIntent ID for a given order row.
     *
     * Subscription-created orders only have a `stripe_subscription_id` (and
     * sometimes a `stripe_session_id`) on the local row — the PaymentIntent
     * lives on `latest_invoice.payment_intent` over on Stripe. Checkout
     * Session orders may only have `stripe_session_id` until their
     * checkout.session.completed webhook fills the PI column.
     *
     * Walks the available references in this order:
     *   1. stripe_payment_intent_id (most direct, no Stripe call)
     *   2. stripe_session_id        → Checkout Session → payment_intent
     *   3. stripe_subscription_id   → Subscription → latest_invoice → payment_intent
     *   4. stripe_invoice_id        → Invoice → payment_intent
     *
     * Returns the PI id, or '' if none can be found.
     */
    public static function resolvePaymentIntentId(array $order): string
    {
        $pi = trim((string) ($order['stripe_payment_intent_id'] ?? ''));
        if ($pi !== '') {
            return $pi;
        }

        // 2) Checkout Session
        $sessionId = trim((string) ($order['stripe_session_id'] ?? ''));
        if ($sessionId !== '') {
            $session = StripeService::retrieveCheckoutSession($sessionId);
            if (is_array($session) && !empty($session['payment_intent'])) {
                return is_array($session['payment_intent'])
                    ? (string) ($session['payment_intent']['id'] ?? '')
                    : (string) $session['payment_intent'];
            }
        }

        // 3) Subscription → latest_invoice → PI
        $subId = trim((string) ($order['stripe_subscription_id'] ?? ''));
        if ($subId !== '') {
            $sub = StripeService::retrieveSubscription($subId, ['latest_invoice.payment_intent']);
            if (is_array($sub)) {
                $latest = $sub['latest_invoice'] ?? null;
                if (is_array($latest)) {
                    $piRef = $latest['payment_intent'] ?? null;
                    if (is_array($piRef) && !empty($piRef['id'])) {
                        return (string) $piRef['id'];
                    }
                    if (is_string($piRef) && $piRef !== '') {
                        return $piRef;
                    }
                }
            }
        }

        // 4) Invoice → PI
        $invoiceId = trim((string) ($order['stripe_invoice_id'] ?? ''));
        if ($invoiceId !== '') {
            $invoice = StripeService::retrieveInvoice($invoiceId, ['payment_intent']);
            if (is_array($invoice)) {
                $piRef = $invoice['payment_intent'] ?? null;
                if (is_array($piRef) && !empty($piRef['id'])) {
                    return (string) $piRef['id'];
                }
                if (is_string($piRef) && $piRef !== '') {
                    return $piRef;
                }
            }
        }

        return '';
    }

    /**
     * Membership refund — partial or full. Mirrors processRefund() but works
     * against the unified `orders` table + the membership `refunds` table.
     * Requires migration 034 (refunds.amount_cents + refunds.status).
     *
     * @return array{refund_id:int, stripe_refund_id:string, remaining_refundable_cents:int}
     * @throws RuntimeException
     */
    public static function processMembershipRefund(int $orderId, int $amountCents, string $reason, int $adminUserId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND order_type = "membership" LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Membership order not found.');
        }
        $memberId = (int) ($order['member_id'] ?? 0);

        $orderTotalCents = (int) round((float) ($order['total'] ?? 0) * 100);

        // Sum already-processed refund amounts. The amount_cents column was
        // added in migration 034; older rows pre-migration use the order total.
        $refundedCents = 0;
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM refunds WHERE order_id = :id AND (status IS NULL OR status = 'processed')");
            $stmt->execute(['id' => $orderId]);
            $refundedCents = (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Pre-migration schema — fall back to row count × full order total.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM refunds WHERE order_id = :id');
            $stmt->execute(['id' => $orderId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $refundedCents = $orderTotalCents;
            }
        }

        $remaining = max(0, $orderTotalCents - $refundedCents);
        if ($amountCents <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }
        if ($amountCents > $remaining) {
            throw new RuntimeException('Refund exceeds refundable total (remaining ' . self::formatCurrency($remaining) . ').');
        }

        // Resolve PaymentIntent for this order. The local column may be empty
        // when the order was created via Stripe Checkout / Subscription paths
        // — in that case we walk the session / subscription / invoice on
        // Stripe to find the PI, then cache it back to the row so future
        // refunds skip this round-trip.
        $paymentIntentId = self::resolvePaymentIntentId($order);
        if ($paymentIntentId === '') {
            throw new RuntimeException('Could not resolve a Stripe PaymentIntent for this order (no PI / session / subscription / invoice on file).');
        }
        // Cache the resolved PI back to the row if it wasn't there before.
        if ($paymentIntentId !== (string) ($order['stripe_payment_intent_id'] ?? '')) {
            try {
                $stmt = $pdo->prepare('UPDATE orders SET stripe_payment_intent_id = :pi, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['pi' => $paymentIntentId, 'id' => $orderId]);
                $order['stripe_payment_intent_id'] = $paymentIntentId;
            } catch (\Throwable $e) {
                // Non-fatal — refund still goes ahead even if caching fails.
                error_log('[RefundService] PI cache-back failed: ' . $e->getMessage());
            }
        }

        ActivityLogger::log('admin', $adminUserId, $memberId, 'membership.refund_requested', [
            'order_id' => $orderId,
            'amount_cents' => $amountCents,
            'reason' => $reason,
        ]);

        $refund = StripeService::createRefund($paymentIntentId, $amountCents);
        if (empty($refund) || empty($refund['id'])) {
            ActivityLogger::log('admin', $adminUserId, $memberId, 'membership.refund_failed', [
                'order_id' => $orderId,
                'amount_cents' => $amountCents,
                'reason' => $reason,
            ]);
            throw new RuntimeException('Stripe refund could not be completed.');
        }

        // Persist — gracefully handle pre-migration schemas where amount_cents
        // or status columns don't exist yet.
        try {
            $stmt = $pdo->prepare("INSERT INTO refunds (order_id, stripe_refund_id, refunded_by_user_id, refunded_at, reason, amount_cents, status, created_at) VALUES (:order_id, :stripe_refund_id, :user_id, NOW(), :reason, :amount_cents, 'processed', NOW())");
            $stmt->execute([
                'order_id' => $orderId,
                'stripe_refund_id' => (string) $refund['id'],
                'user_id' => $adminUserId > 0 ? $adminUserId : null,
                'reason' => $reason !== '' ? $reason : null,
                'amount_cents' => $amountCents,
            ]);
        } catch (\Throwable $e) {
            // Old schema fallback — no amount_cents / status. Insert without.
            $stmt = $pdo->prepare('INSERT INTO refunds (order_id, stripe_refund_id, refunded_by_user_id, refunded_at, reason, created_at) VALUES (:order_id, :stripe_refund_id, :user_id, NOW(), :reason, NOW())');
            $stmt->execute([
                'order_id' => $orderId,
                'stripe_refund_id' => (string) $refund['id'],
                'user_id' => $adminUserId > 0 ? $adminUserId : null,
                'reason' => $reason !== '' ? $reason : null,
            ]);
        }
        $refundId = (int) $pdo->lastInsertId();

        $remainingAfter = max(0, $remaining - $amountCents);
        $newPaymentStatus = $remainingAfter <= 0 ? 'refunded' : 'partial_refund';
        $newStatus = $remainingAfter <= 0 ? 'refunded' : 'paid';

        // Update the order. Only touch payment_status if the column exists
        // (older schemas may not have it).
        $sql = 'UPDATE orders SET status = :status, updated_at = NOW()';
        $params = ['status' => $newStatus, 'id' => $orderId];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_status'");
            if ($colStmt->fetchColumn()) {
                $sql = 'UPDATE orders SET status = :status, payment_status = :payment_status, updated_at = NOW()';
                $params['payment_status'] = $newPaymentStatus;
            }
        } catch (\Throwable $e) { /* skip if SHOW fails */ }
        $sql .= ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        // On full refund, lapse the linked membership_periods row so the member
        // doesn't keep an active membership the AGA was paid back for.
        if ($remainingAfter <= 0 && !empty($order['membership_period_id'])) {
            try {
                $stmt = $pdo->prepare('UPDATE membership_periods SET status = "LAPSED" WHERE id = :id');
                $stmt->execute(['id' => (int) $order['membership_period_id']]);
            } catch (\Throwable $e) { /* best-effort */ }
        }

        ActivityLogger::log('admin', $adminUserId, $memberId, 'membership.refund_processed', [
            'order_id' => $orderId,
            'refund_id' => $refundId,
            'stripe_refund_id' => (string) $refund['id'],
            'amount_cents' => $amountCents,
            'remaining_refundable_cents' => $remainingAfter,
        ]);

        $safeRefundId = htmlspecialchars((string) $refundId, ENT_QUOTES, 'UTF-8');
        $safeAdminId = htmlspecialchars((string) $adminUserId, ENT_QUOTES, 'UTF-8');
        SecurityAlertService::send('refund_created', 'Security alert: membership refund created', '<p>Membership refund #' . $safeRefundId . ' (order ' . $orderId . ') created by user ID ' . $safeAdminId . '.</p>');

        if ($memberId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $memberId]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($member && !empty($member['email'])) {
                    NotificationService::dispatch('membership_refund_processed', [
                        'primary_email' => $member['email'],
                        'admin_emails' => NotificationService::getAdminEmails(),
                        'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                        'order_number' => (string) ($order['order_number'] ?? $orderId),
                        'refund_amount' => NotificationService::escape(self::formatCurrency($amountCents)),
                        'refund_reason' => NotificationService::escape($reason !== '' ? $reason : 'Refund issued by admin.'),
                    ]);
                }
            } catch (\Throwable $e) { /* notification failure shouldn't block refund */ }
        }

        return [
            'refund_id' => $refundId,
            'stripe_refund_id' => (string) $refund['id'],
            'remaining_refundable_cents' => $remainingAfter,
        ];
    }

    /**
     * Sum of refunded cents for a membership order. Used by the UI to show
     * "refundable: $X" alongside the refund form. Pre-migration rows without
     * amount_cents are treated as full refunds of the order total.
     */
    public static function getMembershipRefundedCents(int $orderId): int
    {
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM refunds WHERE order_id = :id AND (status IS NULL OR status = 'processed')");
            $stmt->execute(['id' => $orderId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Pre-migration: every row = full refund of order total.
            try {
                $stmt = $pdo->prepare('SELECT o.total FROM orders o WHERE o.id = :id LIMIT 1');
                $stmt->execute(['id' => $orderId]);
                $total = (float) ($stmt->fetchColumn() ?: 0);
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM refunds WHERE order_id = :id');
                $stmt->execute(['id' => $orderId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    return (int) round($total * 100);
                }
            } catch (\Throwable $e2) { /* nothing useful to report */ }
            return 0;
        }
    }
}
