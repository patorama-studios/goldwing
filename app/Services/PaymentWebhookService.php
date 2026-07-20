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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'metadata.order_id missing', [
                'event_id' => $event['id'] ?? null,
                'metadata' => $metadata,
                'related_stripe_session_id' => $session['id'] ?? null,
                'related_stripe_pi_id' => $session['payment_intent'] ?? null,
            ]);
            return null;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = self::getOrderForUpdate($orderId);
            if (!$order) {
                $pdo->rollBack();
                StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by id ' . $orderId, [
                    'event_id' => $event['id'] ?? null,
                    'related_order_id' => $orderId,
                    'metadata' => $metadata,
                    'related_stripe_session_id' => $session['id'] ?? null,
                    'related_stripe_pi_id' => $session['payment_intent'] ?? null,
                ]);
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
                $cartId = isset($metadata['cart_id']) && $metadata['cart_id'] !== '' ? (int) $metadata['cart_id'] : 0;
                self::markStoreOrderPaid((int) $metadata['store_order_id'], $paymentIntentId, $sessionId, $cartId);
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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'PI id missing on charge and no charge id', [
                'event_id' => $event['id'] ?? null,
            ]);
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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by PI or charge id', [
                'event_id' => $event['id'] ?? null,
                'related_stripe_pi_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                'stripe_charge_id' => $chargeId !== '' ? $chargeId : null,
            ]);
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

        $refundAmountCents = 0;
        if (isset($charge['amount_refunded'])) {
            $refundAmountCents = (int) $charge['amount_refunded'];
        } elseif (isset($refunds[0]['amount'])) {
            $refundAmountCents = (int) $refunds[0]['amount'];
        }
        $refundAmountFormatted = 'A$' . number_format(max(0, $refundAmountCents) / 100, 2);
        $reasonText = $reason !== null && $reason !== '' ? $reason : 'Refund issued by Stripe.';

        if (($order['order_type'] ?? '') === 'membership') {
            MembershipOrderService::markOrderRefunded($order, 'Stripe refund received.');
            $stmt = $pdo->prepare('UPDATE memberships SET status = "unpaid", updated_at = NOW() WHERE order_id = :order_id');
            $stmt->execute(['order_id' => $order['id']]);

            if (!empty($order['member_id'])) {
                $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => (int) $order['member_id']]);
                $member = $stmt->fetch();
                if ($member && !empty($member['email'])) {
                    NotificationService::dispatch('membership_refund_processed', [
                        'primary_email' => $member['email'],
                        'admin_emails' => NotificationService::getAdminEmails(),
                        'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                        'order_number' => $order['order_number'] ?? '',
                        'refund_amount' => NotificationService::escape($refundAmountFormatted),
                        'refund_reason' => NotificationService::escape($reasonText),
                    ]);
                }
            }
        }

        if ($paymentIntentId !== '') {
            $stmt = $pdo->prepare('UPDATE store_orders SET status = "refunded", updated_at = NOW() WHERE stripe_payment_intent_id = :payment_intent_id');
            $stmt->execute(['payment_intent_id' => $paymentIntentId]);

            if (($order['order_type'] ?? '') === 'store') {
                $stmt = $pdo->prepare('SELECT customer_email, customer_name, order_number FROM store_orders WHERE stripe_payment_intent_id = :payment_intent_id LIMIT 1');
                $stmt->execute(['payment_intent_id' => $paymentIntentId]);
                $storeOrder = $stmt->fetch();
                $customerEmail = (string) ($storeOrder['customer_email'] ?? '');
                if ($customerEmail !== '') {
                    NotificationService::dispatch('store_refund_processed', [
                        'primary_email' => $customerEmail,
                        'admin_emails' => NotificationService::getAdminEmails(),
                        'order_number' => NotificationService::escape((string) ($storeOrder['order_number'] ?? ($order['order_number'] ?? ''))),
                        'refund_amount' => NotificationService::escape($refundAmountFormatted),
                        'refund_reason' => NotificationService::escape($reasonText),
                    ]);
                }
            }
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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'payment_intent_id empty', [
                'event_id' => $event['id'] ?? null,
                'metadata' => $intent['metadata'] ?? null,
            ]);
            return;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_payment_intent_id = :payment_intent_id LIMIT 1');
        $stmt->execute(['payment_intent_id' => $paymentIntentId]);
        $order = $stmt->fetch();
        if (!$order) {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by PI ' . $paymentIntentId, [
                'event_id' => $event['id'] ?? null,
                'related_stripe_pi_id' => $paymentIntentId,
                'metadata' => $intent['metadata'] ?? null,
            ]);
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
            self::notifyAdminsPaymentIssue($order, $member ?: [], 'failed',
                (string) (($intent['last_payment_error']['message'] ?? '') ?: 'Stripe reported the card payment as failed.'));
            ActivityLogger::log('system', null, (int) $order['member_id'], 'membership.payment_failed', [
                'order_id' => $order['id'],
                'payment_intent' => $paymentIntentId,
            ]);
        }
    }

    public static function handlePaymentIntentSucceeded(array $event): void
    {
        self::reconcilePaymentIntent($event['data']['object'] ?? [], $event['id'] ?? null);
    }

    /**
     * Apply a succeeded PaymentIntent to its order(s): mark paid, activate
     * memberships, create invoices. Called by the webhook, and synchronously
     * from /member/ page loads when the webhook is delayed or missed.
     * Idempotent — already-paid orders are skipped.
     */
    public static function reconcilePaymentIntent(array $intent, ?string $eventId = null): void
    {
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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'metadata.order_id missing', [
                'event_id' => $eventId,
                'metadata' => $metadata,
                'related_stripe_pi_id' => $paymentIntentId,
            ]);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = self::getOrderForUpdate($orderId);
            if (!$order) {
                $pdo->rollBack();
                StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by id ' . $orderId, [
                    'event_id' => $eventId,
                    'related_order_id' => $orderId,
                    'related_stripe_pi_id' => $paymentIntentId,
                    'metadata' => $metadata,
                ]);
                return;
            }
            $chargeId = $intent['latest_charge'] ?? '';
            if ($chargeId === '' && !empty($intent['charges']['data'][0]['id'])) {
                $chargeId = (string) $intent['charges']['data'][0]['id'];
            }
            if (($order['status'] ?? '') !== 'paid') {
                OrderService::markPaid($orderId, $paymentIntentId, $chargeId !== '' ? $chargeId : null);
                // Inline Payment Element flow: there is no
                // checkout.session.completed event, so membership
                // activation (period → ACTIVE, member receipt, treasurer
                // notification) has to happen here.
                if (($order['order_type'] ?? '') === 'membership') {
                    $updated = OrderService::getOrderById($orderId);
                    self::markMembershipPaid($updated ?: $order, $metadata);
                }
            }

            if (($order['order_type'] ?? '') === 'store') {
                $storeOrderId = isset($metadata['store_order_id']) ? (int) $metadata['store_order_id'] : 0;
                if ($storeOrderId > 0) {
                    $cartId = isset($metadata['cart_id']) && $metadata['cart_id'] !== '' ? (int) $metadata['cart_id'] : 0;
                    self::markStoreOrderPaid($storeOrderId, $paymentIntentId, null, $cartId);
                }
            }

            if (!self::invoiceExists($orderId)) {
                $updatedOrder = OrderService::getOrderById($orderId);
                if ($updatedOrder) {
                    $order = $updatedOrder;
                }
                InvoiceService::createForOrder($order);
            }

            // A single PaymentIntent can cover additional orders (e.g. the
            // partner's renewal bought in the same lightbox). They're
            // listed in metadata.extra_order_ids as a comma-separated list.
            if (!empty($metadata['extra_order_ids'])) {
                foreach (explode(',', (string) $metadata['extra_order_ids']) as $extraIdRaw) {
                    $extraId = (int) trim($extraIdRaw);
                    if ($extraId <= 0 || $extraId === $orderId) {
                        continue;
                    }
                    $extra = self::getOrderForUpdate($extraId);
                    if (!$extra) {
                        StripeErrorLogger::logWebhookSkip(__METHOD__, 'extra order not found by id ' . $extraId, [
                            'event_id' => $eventId,
                            'related_order_id' => $extraId,
                            'related_stripe_pi_id' => $paymentIntentId,
                        ]);
                        continue;
                    }
                    if (($extra['status'] ?? '') !== 'paid') {
                        OrderService::markPaid($extraId, $paymentIntentId, $chargeId !== '' ? $chargeId : null);
                        if (($extra['order_type'] ?? '') === 'membership') {
                            $extraUpdated = OrderService::getOrderById($extraId);
                            self::markMembershipPaid($extraUpdated ?: $extra, []);
                        }
                    }
                    if (!self::invoiceExists($extraId)) {
                        $extraRow = OrderService::getOrderById($extraId);
                        if ($extraRow) {
                            InvoiceService::createForOrder($extraRow);
                        }
                    }
                }
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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'invoice id and subscription id both missing', [
                'event_id' => $event['id'] ?? null,
                'metadata' => $invoice['metadata'] ?? null,
            ]);
            return;
        }

        $pdo = Database::connection();
        $order = null;
        $invoiceMetadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];

        // Billing-artifact invoices that must NOT drive activation:
        //  - membership_application: no order row yet (apply.php + admin approval
        //    handle activation).
        //  - membership_backfill: historical orders already paid + active; the
        //    paid-out-of-band invoice is a record only, so re-activating would
        //    wrongly re-chain the membership dates.
        $invoiceContext = (string) ($invoiceMetadata['context'] ?? ($invoiceMetadata['purpose'] ?? ''));
        if ($invoiceContext === 'membership_application' || $invoiceContext === 'membership_backfill') {
            return;
        }

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
        if (!$order && !empty($invoiceMetadata['order_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $invoiceMetadata['order_id']]);
            $order = $stmt->fetch();
        }
        if (!$order) {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by invoice, subscription, or metadata.order_id', [
                'event_id' => $event['id'] ?? null,
                'stripe_invoice_id' => $invoiceId !== '' ? $invoiceId : null,
                'stripe_subscription_id' => $subscriptionId !== '' ? $subscriptionId : null,
                'related_stripe_pi_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                'metadata' => $invoiceMetadata,
            ]);
            return;
        }

        $stmt = $pdo->prepare('UPDATE orders SET stripe_payment_intent_id = CASE WHEN stripe_payment_intent_id IS NULL OR stripe_payment_intent_id = "" THEN :payment_intent_id ELSE stripe_payment_intent_id END, stripe_invoice_id = :invoice_id, stripe_subscription_id = CASE WHEN stripe_subscription_id IS NULL OR stripe_subscription_id = "" THEN :subscription_id ELSE stripe_subscription_id END, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'payment_intent_id' => $paymentIntentId,
            'invoice_id' => $invoiceId !== '' ? $invoiceId : ($order['stripe_invoice_id'] ?? ''),
            'subscription_id' => $subscriptionId !== '' ? $subscriptionId : ($order['stripe_subscription_id'] ?? ''),
            'id' => (int) ($order['id'] ?? 0),
        ]);

        // Store orders: hand off to markStoreOrderPaid which flips the order to
        // paid, runs ticket/stock fulfillment, and converts the cart.
        if (($order['order_type'] ?? '') === 'store') {
            $storeOrderId = isset($invoiceMetadata['store_order_id']) ? (int) $invoiceMetadata['store_order_id'] : 0;
            if ($storeOrderId <= 0) {
                // Fall back to the store_orders row that matches our order_number
                $stmt = $pdo->prepare('SELECT id FROM store_orders WHERE order_number = :n LIMIT 1');
                $stmt->execute(['n' => $order['order_number'] ?? '']);
                $storeOrderId = (int) ($stmt->fetchColumn() ?: 0);
            }
            if ($storeOrderId > 0) {
                $cartId = isset($invoiceMetadata['cart_id']) && $invoiceMetadata['cart_id'] !== '' ? (int) $invoiceMetadata['cart_id'] : 0;
                self::markStoreOrderPaid($storeOrderId, $paymentIntentId, null, $cartId);
            } else {
                StripeErrorLogger::logWebhookSkip(__METHOD__, 'store order: could not resolve store_order_id', [
                    'event_id' => $event['id'] ?? null,
                    'stripe_invoice_id' => $invoiceId,
                    'order_number' => $order['order_number'] ?? null,
                ]);
            }
            if (!self::invoiceExists((int) $order['id'])) {
                $updatedOrder = OrderService::getOrderById((int) $order['id']);
                if ($updatedOrder) {
                    InvoiceService::createForOrder($updatedOrder);
                }
            }
            return;
        }

        if (($order['order_type'] ?? '') !== 'membership') {
            return;
        }

        // Guard: never activate off an underpaid invoice. A code bug once let
        // invoices finalize with only the card-fee line (July 2026 — members
        // charged $2.40 for a $72.40 renewal), and invoice.paid fires as long
        // as the INVOICE is fully paid, regardless of what it covers. Compare
        // what Stripe collected against every membership order stamped with
        // this invoice; on shortfall, log + alert instead of activating.
        $membershipOrdersDueCents = (int) round(((float) ($order['total'] ?? 0)) * 100);
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE stripe_invoice_id = :inv AND order_type = 'membership' AND id <> :id");
            $stmt->execute(['inv' => $invoiceId, 'id' => (int) ($order['id'] ?? 0)]);
            $membershipOrdersDueCents += (int) round(((float) $stmt->fetchColumn()) * 100);
        }
        $amountPaidCents = (int) ($invoice['amount_paid'] ?? 0);
        if ($membershipOrdersDueCents > 0 && $amountPaidCents < $membershipOrdersDueCents) {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'invoice underpaid vs membership orders — activation blocked', [
                'event_id' => $event['id'] ?? null,
                'stripe_invoice_id' => $invoiceId,
                'order_id' => $order['id'] ?? null,
                'amount_paid_cents' => $amountPaidCents,
                'orders_due_cents' => $membershipOrdersDueCents,
            ]);
            ActivityLogger::log('system', null, (int) ($order['member_id'] ?? 0), 'membership.activation_blocked_underpaid', [
                'order_id' => $order['id'] ?? null,
                'stripe_invoice_id' => $invoiceId,
                'amount_paid_cents' => $amountPaidCents,
                'orders_due_cents' => $membershipOrdersDueCents,
            ]);
            return;
        }

        // Route through markMembershipPaid (not a bare activateMembershipForOrder)
        // so invoice.paid renewals send the member receipt + treasurer
        // notification — previously they activated silently and NO receipt went
        // out (the PaymentIntent path that sends it early-returns whenever the PI
        // belongs to an invoice, which every membership invoice PI does).
        // Idempotency: activation stacks expiry and is NOT idempotent. recordEvent
        // dedupes Stripe's own retries (same event id), but a manual reconcile
        // (admin/reconcile-stranded-payments.php, synthetic event id) or a delayed
        // real retry would otherwise activate twice and double the term / receipt.
        // Skip if already paid — matching the sibling loop below.
        if (($order['status'] ?? '') !== 'paid') {
            self::markMembershipPaid($order, [
                'payment_intent' => $paymentIntentId !== '' ? $paymentIntentId : $invoiceId,
                'period_id' => $order['membership_period_id'] ?? null,
            ]);
        }

        $internal = json_decode((string) ($order['internal_notes'] ?? ''), true);
        if (is_array($internal)) {
            // Combined RENEWAL: the partner has their own order + period. Activate
            // it here too, so the partner is never stranded if the shared-invoice
            // lookup below misses (e.g. the partner order's stripe_invoice_id
            // stamp didn't land). Guarded on paid status — activation stacks
            // expiry and is NOT idempotent; the guard plus recordEvent's unique
            // event id keep it to exactly one activation.
            $partnerOrderId = (int) ($internal['partner_order_id'] ?? 0);
            if ($partnerOrderId > 0 && $partnerOrderId !== (int) ($order['id'] ?? 0)) {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id AND order_type = 'membership' LIMIT 1");
                $stmt->execute(['id' => $partnerOrderId]);
                $partnerOrder = $stmt->fetch();
                if ($partnerOrder && ($partnerOrder['status'] ?? '') !== 'paid') {
                    self::markMembershipPaid($partnerOrder, [
                        'payment_intent' => $paymentIntentId !== '' ? $paymentIntentId : $invoiceId,
                        'period_id' => $partnerOrder['membership_period_id'] ?? null,
                    ]);
                    if (!empty($partnerOrder['user_id'])) {
                        $pdo->prepare('UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = :id AND is_active = 0')
                            ->execute(['id' => (int) $partnerOrder['user_id']]);
                    }
                }
            }

            // Legacy JOIN: the associate is period-only (no separate order row).
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

        // A combined renewal bills the partner's order on the SAME invoice.
        // Activate every other membership order stamped with this invoice id
        // (skip any already paid for idempotency).
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE stripe_invoice_id = :inv AND order_type = 'membership' AND id <> :id");
            $stmt->execute(['inv' => $invoiceId, 'id' => (int) ($order['id'] ?? 0)]);
            foreach ($stmt->fetchAll() as $extra) {
                if (($extra['status'] ?? '') === 'paid') {
                    continue;
                }
                self::markMembershipPaid($extra, [
                    'payment_intent' => $paymentIntentId !== '' ? $paymentIntentId : $invoiceId,
                    'period_id' => $extra['membership_period_id'] ?? null,
                ]);
                if (!empty($extra['user_id'])) {
                    $stmt2 = $pdo->prepare('UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = :id AND is_active = 0');
                    $stmt2->execute(['id' => (int) $extra['user_id']]);
                }
            }
        }
    }

    public static function handleInvoicePaymentFailed(array $event): void
    {
        $invoice = $event['data']['object'] ?? [];
        $invoiceId = $invoice['id'] ?? '';
        $subscriptionId = $invoice['subscription'] ?? '';
        if ($invoiceId === '' && $subscriptionId === '') {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'invoice id and subscription id both missing', [
                'event_id' => $event['id'] ?? null,
                'metadata' => $invoice['metadata'] ?? null,
            ]);
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
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by invoice, subscription, or metadata.order_id', [
                'event_id' => $event['id'] ?? null,
                'stripe_invoice_id' => $invoiceId !== '' ? $invoiceId : null,
                'stripe_subscription_id' => $subscriptionId !== '' ? $subscriptionId : null,
                'metadata' => $invoice['metadata'] ?? null,
            ]);
            return;
        }

        MembershipOrderService::markOrderFailed((int) ($order['id'] ?? 0), 'Stripe invoice payment failed.');
        $periodId = (int) ($order['membership_period_id'] ?? 0);
        if ($periodId > 0) {
            $stmt = $pdo->prepare('UPDATE membership_periods SET status = "PENDING_PAYMENT" WHERE id = :id');
            $stmt->execute(['id' => $periodId]);
        }

        if (($order['order_type'] ?? '') === 'membership' && !empty($order['member_id'])) {
            $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $order['member_id']]);
            $member = $stmt->fetch();
            if ($member && !empty($member['email'])) {
                $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
                NotificationService::dispatch('membership_payment_failed', [
                    'primary_email' => $member['email'],
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                    'order_number' => $order['order_number'] ?? '',
                    'payment_link' => NotificationService::escape($paymentLink),
                ]);
                ActivityLogger::log('system', null, (int) $order['member_id'], 'membership.invoice_payment_failed', [
                    'order_id' => $order['id'],
                    'invoice_id' => $invoiceId,
                ]);
            }
            self::notifyAdminsPaymentIssue($order, $member ?: [], 'failed', 'Stripe invoice payment failed (card declined or authentication failed).');
        }
    }

    public static function handleSubscriptionUpdated(array $event): void
    {
        $subscription = $event['data']['object'] ?? [];
        $subscriptionId = $subscription['id'] ?? '';
        if ($subscriptionId === '') {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'subscription id missing', [
                'event_id' => $event['id'] ?? null,
                'subscription_status' => $subscription['status'] ?? null,
            ]);
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE stripe_subscription_id = :subscription_id LIMIT 1');
        $stmt->execute(['subscription_id' => $subscriptionId]);
        $order = $stmt->fetch();
        if (!$order) {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'order not found by subscription id ' . $subscriptionId, [
                'event_id' => $event['id'] ?? null,
                'stripe_subscription_id' => $subscriptionId,
                'subscription_status' => $subscription['status'] ?? null,
            ]);
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

                $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $memberId]);
                $member = $stmt->fetch();
                if ($member && !empty($member['email'])) {
                    $paymentLink = BaseUrlService::buildUrl('/member/index.php?page=billing');
                    NotificationService::dispatch('membership_subscription_cancelled', [
                        'primary_email' => $member['email'],
                        'admin_emails' => NotificationService::getAdminEmails(),
                        'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')),
                        'order_number' => $order['order_number'] ?? '',
                        'payment_link' => NotificationService::escape($paymentLink),
                    ]);
                    ActivityLogger::log('system', null, $memberId, 'membership.subscription_cancelled', [
                        'order_id' => $order['id'] ?? null,
                        'subscription_id' => $subscriptionId,
                        'subscription_status' => $status,
                    ]);
                }
                self::notifyAdminsPaymentIssue($order, $member ?: [], 'cancelled', 'Stripe subscription status: ' . $status . '. The membership has been marked inactive.');
            }
        }
    }

    /**
     * Admin/treasurer copy of a failed or cancelled membership payment, so
     * nobody chases a member whose Stripe payment never went through. A mail
     * failure must never break webhook processing.
     */
    private static function notifyAdminsPaymentIssue(array $order, array $member, string $statusLabel, string $reason): void
    {
        try {
            NotificationService::dispatch('membership_admin_payment_issue', [
                'admin_emails' => NotificationService::getAdminEmails(),
                'member_id' => (int) ($order['member_id'] ?? 0) ?: null,
                'member_name' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: 'Unknown member',
                'member_email' => (string) (($member['email'] ?? '') ?: '—'),
                'order_number' => (string) (($order['order_number'] ?? '') ?: ($order['id'] ?? '')),
                'status_label' => $statusLabel,
                'reason' => NotificationService::escape($reason),
                'admin_link' => BaseUrlService::buildUrl('/admin/membership-orders/view.php?id=' . (int) ($order['id'] ?? 0)),
            ]);
        } catch (\Throwable $e) {
            error_log('[PaymentWebhookService] admin payment-issue notification failed: ' . $e->getMessage());
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

    /**
     * Flip a store_order to paid + run the post-payment side effects
     * (cart conversion, ticket generation, stock decrement, payment.paid
     * event, downstream `invoices` row + email).
     *
     * Public so admin tools can re-use the same code path when Stripe
     * webhooks didn't reach us (test mode without a test endpoint, manual
     * cleanup, etc.). Idempotent: a second call on an already-paid order
     * just back-fills missing Stripe ids and returns.
     */
    public static function markStoreOrderPaid(int $storeOrderId, string $paymentIntentId, ?string $sessionId = null, int $cartId = 0): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $storeOrderId]);
        $order = $stmt->fetch();
        if (!$order) {
            return;
        }

        // Close out the cart that produced this paid order. Lookup by cart_id
        // from PI/session metadata, with a fallback to the user's currently
        // active cart. This intentionally lives here (not in the create
        // endpoint) so abandoned/failed attempts don't lock the user out.
        if ($cartId > 0) {
            $stmt = $pdo->prepare('UPDATE store_carts SET status = "converted", updated_at = NOW() WHERE id = :id AND status = "active"');
            $stmt->execute(['id' => $cartId]);
        } elseif (!empty($order['user_id'])) {
            $stmt = $pdo->prepare('UPDATE store_carts SET status = "converted", updated_at = NOW() WHERE user_id = :user_id AND status = "active"');
            $stmt->execute(['user_id' => (int) $order['user_id']]);
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
        if ($memberId <= 0) {
            $memberId = isset($metadata['member_id']) ? (int) $metadata['member_id'] : 0;
        }
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
                // Associate → Full upgrade: if the order's internal_notes
                // declared this was an upgrade purchase, flip the member
                // type, allocate a new base number, drop the suffix and
                // link to the primary member.
                $internal = json_decode((string) ($order['internal_notes'] ?? ''), true);
                if (is_array($internal) && !empty($internal['upgrade'])) {
                    MembershipUpgradeService::convertAssociateToFull($memberId);
                }

                $pdo = Database::connection();
                $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone, member_type FROM members WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $memberId]);
                $member = $stmt->fetch();
                $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));

                // Member-facing thin receipt — unchanged behaviour.
                NotificationService::dispatch('membership_payment_received', [
                    'primary_email' => $member['email'] ?? '',
                    'admin_emails' => NotificationService::getAdminEmails(),
                    'member_name' => $memberName,
                    'order_number' => $order['order_number'] ?? '',
                ]);

                // Treasurer-facing detailed notification — full reconciliation
                // data so the treasurer can match payments without opening the
                // site. Recipient list comes from the admin_emails setting (or
                // a custom list configured per-template in admin).
                try {
                    // Period for term + dates.
                    $periodStart = '';
                    $periodEnd   = '';
                    $termLabel   = '';
                    $stmt = $pdo->prepare('SELECT start_date, end_date FROM membership_periods WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $periodId]);
                    $period = $stmt->fetch();
                    if ($period) {
                        $periodStart = $period['start_date'] ? date('j M Y', strtotime((string) $period['start_date'])) : '';
                        $periodEnd   = $period['end_date']   ? date('j M Y', strtotime((string) $period['end_date']))   : '';
                        if ($period['start_date'] && $period['end_date']) {
                            $months = max(1, (int) round((strtotime((string) $period['end_date']) - strtotime((string) $period['start_date'])) / (60 * 60 * 24 * 30.44)));
                            $termLabel = $months >= 34 ? '3-year' : ($months >= 22 ? '2-year' : '1-year');
                        }
                    }
                    if ($termLabel === '' && !empty($metadata['term'])) {
                        // Fall back to the term we put in Stripe metadata.
                        $termLabel = strtoupper((string) $metadata['term']) === '3Y'
                            ? '3-year' : (strtoupper((string) $metadata['term']) === '2Y' ? '2-year' : '1-year');
                    }

                    // Associate name (if this order purchased an associate add-on).
                    $associateName = '—';
                    $associateMemberId = isset($metadata['associate_member_id']) ? (int) $metadata['associate_member_id'] : 0;
                    if ($associateMemberId > 0) {
                        $stmt = $pdo->prepare('SELECT first_name, last_name FROM members WHERE id = :id LIMIT 1');
                        $stmt->execute(['id' => $associateMemberId]);
                        $assoc = $stmt->fetch();
                        if ($assoc) {
                            $associateName = trim(($assoc['first_name'] ?? '') . ' ' . ($assoc['last_name'] ?? ''));
                        }
                    }

                    // Amount + payment method from the order row.
                    $amount = '';
                    if (isset($order['total'])) {
                        $amount = 'A$' . number_format((float) $order['total'], 2);
                    } elseif (isset($order['amount'])) {
                        $amount = 'A$' . number_format((float) $order['amount'], 2);
                    }
                    $paymentMethod = 'Stripe (card)';
                    if (!empty($order['payment_method'])) {
                        $paymentMethod = ucfirst((string) $order['payment_method']);
                    }

                    $orderId = (int) ($order['id'] ?? 0);
                    $adminLink = BaseUrlService::buildUrl('/admin/membership-orders/view.php?id=' . $orderId);

                    NotificationService::dispatch('membership_treasurer_notification', [
                        'primary_email'        => $member['email'] ?? '',
                        'admin_emails'         => NotificationService::getAdminEmails(),
                        'member_name'          => $memberName,
                        'member_email'         => $member['email'] ?? '—',
                        'member_phone'         => $member['phone'] ?? '—',
                        'member_type'          => ucfirst(strtolower((string) ($member['member_type'] ?? 'member'))),
                        'term_label'           => $termLabel,
                        'amount'               => $amount,
                        'period_start'         => $periodStart,
                        'period_end'           => $periodEnd,
                        'payment_method'       => $paymentMethod,
                        'stripe_payment_intent'=> (string) ($order['stripe_payment_intent_id'] ?? ''),
                        'associate_name'       => $associateName,
                        'order_number'         => $order['order_number'] ?? ('#' . $orderId),
                        'admin_link'           => $adminLink,
                    ]);
                } catch (\Throwable $e) {
                    // Don't block the webhook on a notification failure.
                    error_log('[PaymentWebhookService] treasurer notification failed: ' . $e->getMessage());
                }
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

        /* Defence-in-depth: only escalate the user to the 'member' role if
         * the order row really is paid in the DB. The webhook signature is
         * already verified upstream, but a replayed event or a stale row
         * shouldn't be able to flip a user's role. Re-read the order fresh
         * to avoid acting on a stale snapshot from earlier in this handler. */
        $orderId = (int) ($order['id'] ?? 0);
        $userId  = (int) ($order['user_id'] ?? 0);
        if ($orderId <= 0 || $userId <= 0) {
            return;
        }
        $stmt = $pdo->prepare('SELECT status, payment_status, order_type FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $fresh = $stmt->fetch();
        if (!$fresh || ($fresh['status'] ?? '') !== 'paid' || ($fresh['payment_status'] ?? '') !== 'accepted') {
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'role escalation refused: order not paid/accepted at escalate time', [
                'related_order_id' => $orderId,
                'user_id' => $userId,
                'order_status' => $fresh['status'] ?? null,
                'order_payment_status' => $fresh['payment_status'] ?? null,
            ]);
            return;
        }
        if (($fresh['order_type'] ?? '') !== 'membership') {
            /* Only membership orders should grant the member role. A store
             * purchase reaching this code path is a bug elsewhere. */
            StripeErrorLogger::logWebhookSkip(__METHOD__, 'role escalation refused: order_type is not membership', [
                'related_order_id' => $orderId,
                'user_id' => $userId,
                'order_type' => $fresh['order_type'] ?? null,
            ]);
            return;
        }

        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = "member" LIMIT 1');
        $stmt->execute();
        $role = $stmt->fetch();
        if ($role) {
            $stmt = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :user_id AND role_id = :role_id');
            $stmt->execute(['user_id' => $userId, 'role_id' => $role['id']]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
                $stmt->execute(['user_id' => $userId, 'role_id' => $role['id']]);
                /* Audit: subject_user_id populated, plus full order/payment
                 * context so the trail is enough to reconstruct what
                 * happened without joining other tables. */
                ActivityLogger::log('system', $userId, (int) ($order['member_id'] ?? 0) ?: null, 'security.role_escalation', [
                    'subject_user_id' => $userId,
                    'role' => 'member',
                    'role_id' => (int) $role['id'],
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'] ?? null,
                    'order_type' => $fresh['order_type'],
                    'stripe_payment_intent_id' => $order['stripe_payment_intent_id'] ?? null,
                    'stripe_invoice_id' => $order['stripe_invoice_id'] ?? null,
                    'stripe_subscription_id' => $order['stripe_subscription_id'] ?? null,
                    'granted_via' => 'webhook.invoice.paid',
                ]);
                $safeUserId = htmlspecialchars((string) $userId, ENT_QUOTES, 'UTF-8');
                $safeOrderNum = htmlspecialchars((string) ($order['order_number'] ?? '(unknown)'), ENT_QUOTES, 'UTF-8');
                SecurityAlertService::send(
                    'role_escalation',
                    'Security alert: role escalation',
                    '<p>User ID ' . $safeUserId . ' assigned role <strong>member</strong> via webhook.</p>'
                    . '<p>Triggered by paid membership order ' . $safeOrderNum . ' (id=' . $orderId . ').</p>'
                );
            }
        }
    }
}
