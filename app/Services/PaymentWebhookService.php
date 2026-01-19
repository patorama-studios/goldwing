<?php
namespace App\Services;

class PaymentWebhookService
{
    public static function recordEvent(array $event): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO webhook_events (stripe_event_id, type, payload_json, processed_status, received_at) VALUES (:stripe_event_id, :type, :payload_json, "received", NOW())');
        try {
            $stmt->execute([
                'stripe_event_id' => $event['id'] ?? '',
                'type' => $event['type'] ?? '',
                'payload_json' => json_encode($event),
            ]);
        } catch (\PDOException $e) {
            return false;
        }
        ActivityLogger::log('system', null, null, 'security.webhook_received', [
            'event_id' => $event['id'] ?? '',
            'event_type' => $event['type'] ?? '',
        ]);
        return true;
    }

    public static function markProcessed(string $eventId, string $status, ?string $error = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE webhook_events SET processed_status = :status, error = :error WHERE stripe_event_id = :event_id');
        $stmt->execute([
            'status' => $status,
            'error' => $error,
            'event_id' => $eventId,
        ]);
        ActivityLogger::log('system', null, null, 'security.webhook_' . $status, [
            'event_id' => $eventId,
            'error' => $error,
        ]);
        if ($status === 'failed') {
            self::alertOnFailures();
        }
    }

    public static function handleCheckoutCompleted(array $event, int $channelId): ?array
    {
        $session = $event['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];
        $orderId = isset($metadata['order_id']) ? (int) $metadata['order_id'] : 0;
        if ($orderId <= 0) {
            return null;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = self::getOrderForUpdate($orderId);
            if (!$order) {
                $pdo->rollBack();
                return null;
            }
            if (($order['status'] ?? '') === 'paid') {
                if (!self::invoiceExists($orderId)) {
                    InvoiceService::createForOrder($order);
                }
                $pdo->commit();
                return $order;
            }

            $paymentIntentId = $session['payment_intent'] ?? '';
            $sessionId = $session['id'] ?? '';
            OrderService::markPaid($orderId, $paymentIntentId, null);

            $updatedOrder = OrderService::getOrderById($orderId);
            if ($updatedOrder) {
                $order = $updatedOrder;
            }

            if (!empty($session['customer']) && !empty($metadata['member_id'])) {
                $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :customer_id WHERE id = :id');
                $stmt->execute([
                    'customer_id' => $session['customer'],
                    'id' => (int) $metadata['member_id'],
                ]);
            }

            if (($order['order_type'] ?? '') === 'membership') {
                self::markMembershipPaid($order, $metadata);
            }
            if (($order['order_type'] ?? '') === 'store' && !empty($metadata['store_order_id'])) {
                self::markStoreOrderPaid((int) $metadata['store_order_id'], $paymentIntentId, $sessionId);
            }

            InvoiceService::createForOrder($order);

            $pdo->commit();
            return $order;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function handleChargeRefunded(array $event): void
    {
        $charge = $event['data']['object'] ?? [];
        $paymentIntentId = $charge['payment_intent'] ?? '';
        $chargeId = $charge['id'] ?? '';
        if ($paymentIntentId === '' && $chargeId === '') {
            return;
        }
        $pdo = Database::connection();
        $order = null;
        if ($paymentIntentId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_payment_intent_id = :payment_intent_id LIMIT 1');
            $stmt->execute(['payment_intent_id' => $paymentIntentId]);
            $order = $stmt->fetch();
        }
        if (!$order && $chargeId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_charge_id = :charge_id LIMIT 1');
            $stmt->execute(['charge_id' => $chargeId]);
            $order = $stmt->fetch();
        }
        if (!$order) {
            return;
        }
        if (($order['status'] ?? '') === 'refunded') {
            return;
        }

        OrderService::markRefunded((int) $order['id']);

        $refundId = null;
        $reason = null;
        $refunds = $charge['refunds']['data'] ?? [];
        if ($refunds) {
            $refund = $refunds[0];
            $refundId = $refund['id'] ?? null;
            $reason = $refund['reason'] ?? null;
        }

        $stmt = $pdo->prepare('INSERT INTO refunds (order_id, stripe_refund_id, refunded_by_user_id, refunded_at, reason, created_at) VALUES (:order_id, :stripe_refund_id, :refunded_by_user_id, NOW(), :reason, NOW())');
        $stmt->execute([
            'order_id' => $order['id'],
            'stripe_refund_id' => $refundId ?? ($event['id'] ?? ''),
            'refunded_by_user_id' => null,
            'reason' => $reason,
        ]);

        if (($order['order_type'] ?? '') === 'membership') {
            MembershipOrderService::markOrderRefunded($order, 'Stripe refund received.');
            $stmt = $pdo->prepare('UPDATE memberships SET status = "unpaid", updated_at = NOW() WHERE order_id = :order_id');
            $stmt->execute(['order_id' => $order['id']]);
        }

        if ($paymentIntentId !== '') {
            $stmt = $pdo->prepare('UPDATE store_orders SET status = "refunded", updated_at = NOW() WHERE stripe_payment_intent_id = :payment_intent_id');
            $stmt->execute(['payment_intent_id' => $paymentIntentId]);
        }
    }

    public static function handlePaymentFailed(array $event): void
    {
        $intent = $event['data']['object'] ?? [];
        if (!empty($intent['invoice'])) {
            return;
        }
        $paymentIntentId = $intent['id'] ?? '';
        if ($paymentIntentId === '') {
            return;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_payment_intent_id = :payment_intent_id LIMIT 1');
        $stmt->execute(['payment_intent_id' => $paymentIntentId]);
        $order = $stmt->fetch();
        if (!$order) {
            return;
        }
        $stmt = $pdo->prepare('UPDATE orders SET status = "cancelled", payment_status = "failed", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $order['id']]);

        if (($order['order_type'] ?? '') === 'membership' && !empty($order['member_id'])) {
            $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $order['member_id']]);
            $member = $stmt->fetch();
            $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
            NotificationService::dispatch('membership_payment_failed', [
                'primary_email' => $member['email'] ?? '',
                'admin_emails' => NotificationService::getAdminEmails(),
                'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                'order_number' => $order['order_number'] ?? '',
                'payment_link' => NotificationService::escape($paymentLink),
            ]);
            ActivityLogger::log('system', null, (int) $order['member_id'], 'membership.payment_failed', [
                'order_id' => $order['id'],
                'payment_intent' => $paymentIntentId,
            ]);
        }
    }

    public static function handlePaymentIntentSucceeded(array $event): void
    {
        $intent = $event['data']['object'] ?? [];
        if (!empty($intent['invoice'])) {
            return;
        }
        $paymentIntentId = $intent['id'] ?? '';
        if ($paymentIntentId === '') {
            return;
        }

        $metadata = $intent['metadata'] ?? [];
        $orderId = isset($metadata['order_id']) ? (int) $metadata['order_id'] : 0;
        if ($orderId <= 0) {
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = self::getOrderForUpdate($orderId);
            if (!$order) {
                $pdo->rollBack();
                return;
            }
            if (($order['status'] ?? '') !== 'paid') {
                $chargeId = $intent['latest_charge'] ?? '';
                if ($chargeId === '' && !empty($intent['charges']['data'][0]['id'])) {
                    $chargeId = (string) $intent['charges']['data'][0]['id'];
                }
                OrderService::markPaid($orderId, $paymentIntentId, $chargeId !== '' ? $chargeId : null);
            }

            if (($order['order_type'] ?? '') === 'store') {
                $storeOrderId = isset($metadata['store_order_id']) ? (int) $metadata['store_order_id'] : 0;
                if ($storeOrderId > 0) {
                    self::markStoreOrderPaid($storeOrderId, $paymentIntentId, null);
                }
            }

            if (!self::invoiceExists($orderId)) {
                $updatedOrder = OrderService::getOrderById($orderId);
                if ($updatedOrder) {
                    $order = $updatedOrder;
                }
                InvoiceService::createForOrder($order);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function handleInvoicePaid(array $event): void
    {
        $invoice = $event['data']['object'] ?? [];
        $invoiceId = $invoice['id'] ?? '';
        $subscriptionId = $invoice['subscription'] ?? '';
        $paymentIntentId = $invoice['payment_intent'] ?? '';
        if ($invoiceId === '' && $subscriptionId === '') {
            return;
        }

        $pdo = Database::connection();
        $order = null;
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_invoice_id = :invoice_id LIMIT 1');
            $stmt->execute(['invoice_id' => $invoiceId]);
            $order = $stmt->fetch();
        }
        if (!$order && $subscriptionId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_subscription_id = :subscription_id LIMIT 1');
            $stmt->execute(['subscription_id' => $subscriptionId]);
            $order = $stmt->fetch();
        }
        if (!$order && !empty($invoice['metadata']['order_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $invoice['metadata']['order_id']]);
            $order = $stmt->fetch();
        }
        if (!$order) {
            return;
        }

        $stmt = $pdo->prepare('UPDATE orders SET stripe_payment_intent_id = CASE WHEN stripe_payment_intent_id IS NULL OR stripe_payment_intent_id = "" THEN :payment_intent_id ELSE stripe_payment_intent_id END, stripe_invoice_id = :invoice_id, stripe_subscription_id = CASE WHEN stripe_subscription_id IS NULL OR stripe_subscription_id = "" THEN :subscription_id ELSE stripe_subscription_id END, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'payment_intent_id' => $paymentIntentId,
            'invoice_id' => $invoiceId !== '' ? $invoiceId : ($order['stripe_invoice_id'] ?? ''),
            'subscription_id' => $subscriptionId !== '' ? $subscriptionId : ($order['stripe_subscription_id'] ?? ''),
            'id' => (int) ($order['id'] ?? 0),
        ]);

        if (($order['order_type'] ?? '') !== 'membership') {
            return;
        }

        MembershipOrderService::activateMembershipForOrder($order, [
            'payment_reference' => $paymentIntentId !== '' ? $paymentIntentId : $invoiceId,
            'period_id' => $order['membership_period_id'] ?? null,
        ]);

        $internal = json_decode((string) ($order['internal_notes'] ?? ''), true);
        if (is_array($internal)) {
            $associatePeriodId = (int) ($internal['associate_period_id'] ?? 0);
            if ($associatePeriodId > 0) {
                MembershipService::markPaid($associatePeriodId, $paymentIntentId !== '' ? $paymentIntentId : $invoiceId);
            }
            $associateMemberId = (int) ($internal['associate_member_id'] ?? 0);
            if ($associateMemberId > 0 && $associatePeriodId <= 0) {
                $stmt = $pdo->prepare('UPDATE members SET status = "ACTIVE", updated_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => $associateMemberId]);
            }
        }

        if (!empty($order['user_id'])) {
            $stmt = $pdo->prepare('UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = :id AND is_active = 0');
            $stmt->execute(['id' => (int) $order['user_id']]);
        }
    }

    public static function handleInvoicePaymentFailed(array $event): void
    {
        $invoice = $event['data']['object'] ?? [];
        $invoiceId = $invoice['id'] ?? '';
        $subscriptionId = $invoice['subscription'] ?? '';
        if ($invoiceId === '' && $subscriptionId === '') {
            return;
        }

        $pdo = Database::connection();
        $order = null;
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_invoice_id = :invoice_id LIMIT 1');
            $stmt->execute(['invoice_id' => $invoiceId]);
            $order = $stmt->fetch();
        }
        if (!$order && $subscriptionId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_subscription_id = :subscription_id LIMIT 1');
            $stmt->execute(['subscription_id' => $subscriptionId]);
            $order = $stmt->fetch();
        }
        if (!$order && !empty($invoice['metadata']['order_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $invoice['metadata']['order_id']]);
            $order = $stmt->fetch();
        }
        if (!$order) {
            return;
        }

        MembershipOrderService::markOrderFailed((int) ($order['id'] ?? 0), 'Stripe invoice payment failed.');
        $periodId = (int) ($order['membership_period_id'] ?? 0);
        if ($periodId > 0) {
            $stmt = $pdo->prepare('UPDATE membership_periods SET status = "PENDING_PAYMENT" WHERE id = :id');
            $stmt->execute(['id' => $periodId]);
        }
    }

    public static function handleSubscriptionUpdated(array $event): void
    {
        $subscription = $event['data']['object'] ?? [];
        $subscriptionId = $subscription['id'] ?? '';
        if ($subscriptionId === '') {
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_subscription_id = :subscription_id LIMIT 1');
        $stmt->execute(['subscription_id' => $subscriptionId]);
        $order = $stmt->fetch();
        if (!$order) {
            return;
        }

        $status = strtolower((string) ($subscription['status'] ?? ''));
        if ($status === 'past_due') {
            $stmt = $pdo->prepare('UPDATE orders SET payment_status = "failed", updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) ($order['id'] ?? 0)]);
        }
        if (in_array($status, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
            $stmt = $pdo->prepare('UPDATE orders SET status = "cancelled", payment_status = "failed", updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => (int) ($order['id'] ?? 0)]);

            $periodId = (int) ($order['membership_period_id'] ?? 0);
            if ($periodId > 0) {
                $stmt = $pdo->prepare('UPDATE membership_periods SET status = "LAPSED" WHERE id = :id');
                $stmt->execute(['id' => $periodId]);
            }
            $memberId = (int) ($order['member_id'] ?? 0);
            if ($memberId > 0) {
                $stmt = $pdo->prepare('UPDATE members SET status = "INACTIVE", updated_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => $memberId]);
            }
        }
    }

    private static function alertOnFailures(): void
    {
        $settings = SecuritySettingsService::get();
        if (!$settings['webhook_alerts_enabled']) {
            return;
        }
        $window = max(1, (int) $settings['webhook_alert_window_minutes']);
        $threshold = max(1, (int) $settings['webhook_alert_threshold']);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE processed_status = "failed" AND received_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)');
        $stmt->bindValue(':window', $window, \PDO::PARAM_INT);
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        if ($count >= $threshold) {
            $safeCount = htmlspecialchars((string) $count, ENT_QUOTES, 'UTF-8');
            $safeWindow = htmlspecialchars((string) $window, ENT_QUOTES, 'UTF-8');
            SecurityAlertService::send('webhook_failure', 'Security alert: Stripe webhook failures', '<p>' . $safeCount . ' Stripe webhook failures in the last ' . $safeWindow . ' minutes.</p>');
        }
    }

    private static function getOrderForUpdate(int $orderId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    private static function markStoreOrderPaid(int $storeOrderId, string $paymentIntentId, ?string $sessionId = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $storeOrderId]);
        $order = $stmt->fetch();
        if (!$order) {
            return;
        }

        $paidStatus = (string) SettingsService::getGlobal('store.order_paid_status', 'paid');
        $alreadyPaid = ($order['status'] ?? '') === $paidStatus;
        if (!$alreadyPaid) {
            $stmt = $pdo->prepare('UPDATE store_orders SET status = :status, order_status = "new", payment_status = "paid", fulfillment_status = "unfulfilled", stripe_payment_intent_id = :payment_intent_id, stripe_session_id = :session_id, paid_at = NOW(), updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'status' => $paidStatus,
                'payment_intent_id' => $paymentIntentId,
                'session_id' => $sessionId !== null && $sessionId !== '' ? $sessionId : ($order['stripe_session_id'] ?? ''),
                'id' => $storeOrderId,
            ]);
        } else {
            if (($order['stripe_payment_intent_id'] ?? '') === '' && $paymentIntentId !== '') {
                $stmt = $pdo->prepare('UPDATE store_orders SET stripe_payment_intent_id = :payment_intent_id, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    'payment_intent_id' => $paymentIntentId,
                    'id' => $storeOrderId,
                ]);
            }
            if (($order['stripe_session_id'] ?? '') === '' && $sessionId !== null && $sessionId !== '') {
                $stmt = $pdo->prepare('UPDATE store_orders SET stripe_session_id = :session_id, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    'session_id' => $sessionId,
                    'id' => $storeOrderId,
                ]);
            }
            return;
        }

        store_add_order_event($pdo, $storeOrderId, 'payment.paid', 'Payment captured via Stripe.', null, [
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :id');
        $stmt->execute(['id' => $storeOrderId]);
        $items = $stmt->fetchAll();

        $tickets = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'ticket') {
                continue;
            }
            $quantity = (int) ($item['quantity'] ?? 0);
            for ($i = 0; $i < $quantity; $i++) {
                $ticketCode = 'TKT-' . strtoupper(bin2hex(random_bytes(4)));
                $stmt = $pdo->prepare('INSERT INTO store_tickets (order_item_id, ticket_code, status, event_name, created_at) VALUES (:order_item_id, :ticket_code, "active", :event_name, NOW())');
                $stmt->execute([
                    'order_item_id' => $item['id'],
                    'ticket_code' => $ticketCode,
                    'event_name' => $item['event_name_snapshot'],
                ]);
                $tickets[] = [
                    'ticket_code' => $ticketCode,
                    'event_name' => $item['event_name_snapshot'],
                ];
            }
        }

        foreach ($items as $item) {
            $stmt = $pdo->prepare('SELECT track_inventory, stock_quantity FROM store_products WHERE id = :id');
            $stmt->execute(['id' => $item['product_id']]);
            $product = $stmt->fetch();
            if (!$product || (int) ($product['track_inventory'] ?? 0) !== 1) {
                continue;
            }
            if (!empty($item['variant_id'])) {
                $stmt = $pdo->prepare('UPDATE store_product_variants SET stock_quantity = stock_quantity - :qty WHERE id = :id');
                $stmt->execute(['qty' => $item['quantity'], 'id' => $item['variant_id']]);
            } else {
                $stmt = $pdo->prepare('UPDATE store_products SET stock_quantity = stock_quantity - :qty WHERE id = :id');
                $stmt->execute(['qty' => $item['quantity'], 'id' => $item['product_id']]);
            }
        }

        if (!empty($order['discount_id'])) {
            $stmt = $pdo->prepare('UPDATE store_discounts SET used_count = used_count + 1 WHERE id = :id');
            $stmt->execute(['id' => $order['discount_id']]);
        }

        $settings = store_get_settings();
        $orderItemsHtml = store_order_items_html($items);
        $totalsHtml = store_order_totals_html($order);
        $addressHtml = store_order_address_html($order);

        $adminEmails = NotificationService::getAdminEmails($settings['notification_emails'] ?? '');
        $context = [
            'primary_email' => $order['customer_email'] ?? '',
            'admin_emails' => $adminEmails,
            'order_number' => NotificationService::escape((string) $order['order_number']),
            'address_html' => $addressHtml,
            'items_html' => $orderItemsHtml,
            'totals_html' => $totalsHtml,
            'member_id' => $order['member_id'] ?? null,
        ];
        if (!empty($order['customer_email'])) {
            NotificationService::dispatch('store_order_confirmation', $context);
            if ($tickets) {
                $context['ticket_list_html'] = store_ticket_list_html($tickets);
                NotificationService::dispatch('store_ticket_codes', $context);
            }
        }
        NotificationService::dispatch('store_admin_new_order', $context);
    }

    private static function invoiceExists(int $orderId): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE order_id = :order_id LIMIT 1');
        $stmt->execute(['order_id' => $orderId]);
        return (bool) $stmt->fetch();
    }

    private static function markMembershipPaid(array $order, array $metadata): void
    {
        $memberId = (int) ($order['member_id'] ?? 0);
        $periodId = (int) ($order['membership_period_id'] ?? 0);
        if ($periodId <= 0) {
            $periodId = isset($metadata['period_id']) ? (int) $metadata['period_id'] : 0;
        }
        if ($memberId > 0 && $periodId > 0) {
            $activated = MembershipOrderService::activateMembershipForOrder($order, [
                'payment_reference' => $order['stripe_payment_intent_id'] ?? ($metadata['payment_intent'] ?? ''),
                'period_id' => $periodId,
            ]);
            if ($activated) {
                $stmt = Database::connection()->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $memberId]);
                $member = $stmt->fetch();
                NotificationService::dispatch('membership_payment_received', [
                    'primary_email' => $member['email'] ?? '',
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                    'order_number' => $order['order_number'] ?? '',
                ]);
            }
            return;
        }

        $pdo = Database::connection();
        $year = isset($metadata['membership_year']) ? (int) $metadata['membership_year'] : (int) date('Y');
        $stmt = $pdo->prepare('SELECT id FROM memberships WHERE user_id = :user_id AND year = :year LIMIT 1');
        $stmt->execute(['user_id' => $order['user_id'], 'year' => $year]);
        $existing = $stmt->fetch();
        if ($existing) {
            $stmt = $pdo->prepare('UPDATE memberships SET status = "paid", approval_status = "pending", order_id = :order_id, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'order_id' => $order['id'],
                'id' => $existing['id'],
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO memberships (user_id, year, status, approval_status, order_id, created_at) VALUES (:user_id, :year, "paid", "pending", :order_id, NOW())');
            $stmt->execute([
                'user_id' => $order['user_id'],
                'year' => $year,
                'order_id' => $order['id'],
            ]);
        }

        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = "member" LIMIT 1');
        $stmt->execute();
        $role = $stmt->fetch();
        if ($role) {
            $stmt = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :user_id AND role_id = :role_id');
            $stmt->execute(['user_id' => $order['user_id'], 'role_id' => $role['id']]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
                $stmt->execute(['user_id' => $order['user_id'], 'role_id' => $role['id']]);
                ActivityLogger::log('system', null, null, 'security.role_escalation', [
                    'user_id' => $order['user_id'],
                    'role' => 'member',
                ]);
                $safeUserId = htmlspecialchars((string) $order['user_id'], ENT_QUOTES, 'UTF-8');
                SecurityAlertService::send('role_escalation', 'Security alert: role escalation', '<p>User ID ' . $safeUserId . ' assigned role member via webhook.</p>');
            }
        }
    }
}
