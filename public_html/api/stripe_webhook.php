<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\StripeService;
use App\Services\MembershipService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\PaymentWebhookService;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!StripeService::verifyWebhook($payload, $signature)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!$event) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$isNew = PaymentWebhookService::recordEvent($event);
if (!$isNew) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$pdo = db();

try {
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];
        $storeOrderId = isset($metadata['store_order_id']) ? (int) $metadata['store_order_id'] : 0;
        if ($storeOrderId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $storeOrderId]);
            $order = $stmt->fetch();
            $paidStatus = (string) SettingsService::getGlobal('store.order_paid_status', 'paid');
            if ($order && $order['status'] !== $paidStatus) {
                $stmt = $pdo->prepare('UPDATE store_orders SET status = :status, stripe_payment_intent_id = :payment_intent, stripe_session_id = :session_id, paid_at = NOW(), updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    'status' => $paidStatus,
                    'payment_intent' => $session['payment_intent'] ?? '',
                    'session_id' => $session['id'] ?? '',
                    'id' => $storeOrderId,
                ]);

                $stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = :id');
                $stmt->execute(['id' => $storeOrderId]);
                $items = $stmt->fetchAll();

                $tickets = [];
                foreach ($items as $item) {
                    if ($item['type'] !== 'ticket') {
                        continue;
                    }
                    for ($i = 0; $i < (int) $item['quantity']; $i++) {
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
                    if (!$product || (int) $product['track_inventory'] !== 1) {
                        continue;
                    }
                    if (!empty($item['variant_id'])) {
                        $stmt = $pdo->prepare('UPDATE store_product_variants SET stock_quantity = GREATEST(0, stock_quantity - :qty) WHERE id = :id');
                        $stmt->execute(['qty' => $item['quantity'], 'id' => $item['variant_id']]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE store_products SET stock_quantity = GREATEST(0, stock_quantity - :qty) WHERE id = :id');
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
        }

        $periodId = isset($metadata['period_id']) ? (int) $metadata['period_id'] : 0;
        $memberId = isset($metadata['member_id']) ? (int) $metadata['member_id'] : 0;
        $amount = isset($session['amount_total']) ? ((int) $session['amount_total'] / 100) : 0;
        $customerId = $session['customer'] ?? null;

        if ($periodId) {
            MembershipService::markPaid($periodId, $session['payment_intent'] ?? '');
        }

        if ($memberId && $customerId) {
            $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :customer_id WHERE id = :id');
            $stmt->execute(['customer_id' => $customerId, 'id' => $memberId]);
        }

        $stmt = $pdo->prepare('INSERT INTO payments (member_id, type, description, amount, status, payment_method, order_source, order_reference, stripe_payment_id, created_at) VALUES (:member_id, :type, :description, :amount, :status, :payment_method, :order_source, :order_reference, :stripe_payment_id, NOW())');
        $stmt->execute([
            'member_id' => $memberId,
            'type' => 'membership',
            'description' => 'Membership payment',
            'amount' => $amount,
            'status' => 'PAID',
            'payment_method' => 'Stripe',
            'order_source' => 'Stripe',
            'order_reference' => $session['payment_intent'] ?? null,
            'stripe_payment_id' => $session['payment_intent'] ?? '',
        ]);

        if ($memberId) {
            AuditService::log(null, 'payment_received', 'Stripe payment received for member #' . $memberId . '.');
        }
    }

    PaymentWebhookService::markProcessed($event['id'] ?? '', 'processed', null);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    PaymentWebhookService::markProcessed($event['id'] ?? '', 'failed', $e->getMessage());
    http_response_code(500);
    echo 'Webhook error';
}
