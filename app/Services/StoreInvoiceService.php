<?php

namespace App\Services;

/**
 * Builds a Stripe Invoice (with itemized line items per store_product) for a
 * `store_orders` row. The invoice's PaymentIntent is what the customer pays via
 * the on-page Stripe Payment Element.
 *
 * Stripe Invoice gives us, for free:
 *   - native line-items table in the dashboard payment view
 *   - downloadable PDF invoice + Stripe-hosted invoice URL
 *   - per-Customer billing history under dashboard.stripe.com/customers
 *   - product catalog: each product is mirrored as a Stripe Product (lazy)
 *
 * Idempotency:
 *   - If a draft/open invoice already exists for the order (column
 *     `store_orders.stripe_invoice_id`) we reuse it instead of creating a new
 *     one. So clicking "Pay now" twice doesn't double-bill.
 *
 * Webhook handover:
 *   - When the customer pays, Stripe fires `invoice.paid`. The handler in
 *     `PaymentWebhookService::handleInvoicePaid` looks up the order by
 *     `metadata.store_order_id` (and falls back to `stripe_invoice_id`),
 *     then calls `markStoreOrderPaid` to finalize and convert the cart.
 */
class StoreInvoiceService
{
    /**
     * Build (or reuse) a Stripe Invoice for a store_order and return the
     * PaymentIntent client_secret needed by the browser's Payment Element.
     *
     * @return array{client_secret:string, payment_intent_id:string, invoice_id:string, invoice_url:?string}
     * @throws \RuntimeException on unrecoverable Stripe errors
     */
    public static function ensureInvoiceForOrder(int $storeOrderId, ?int $cartId = null): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $storeOrderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new \RuntimeException('store_order not found: ' . $storeOrderId);
        }

        // ── 1. Reuse an existing draft/open invoice if present ────────────────
        if (!empty($order['stripe_invoice_id'])) {
            $existing = StripeService::retrieveInvoice($order['stripe_invoice_id']);
            if ($existing && in_array($existing['status'] ?? '', ['draft', 'open'], true)) {
                $reused = self::resolveClientSecret($existing);
                if ($reused !== null) {
                    return $reused;
                }
            }
        }

        // ── 2. Get the line items for this order ─────────────────────────────
        $stmt = $pdo->prepare(
            'SELECT oi.*, p.id AS sp_id, p.title AS sp_title, p.description AS sp_description, p.stripe_product_id, p.slug AS sp_slug
             FROM store_order_items oi
             LEFT JOIN store_products p ON p.id = oi.product_id
             WHERE oi.order_id = :id'
        );
        $stmt->execute(['id' => $storeOrderId]);
        $items = $stmt->fetchAll();
        if (!$items) {
            throw new \RuntimeException('store_order ' . $storeOrderId . ' has no items');
        }

        // ── 3. Resolve / create the Stripe Customer ──────────────────────────
        $customerId = self::ensureCustomer($order);
        if ($customerId === '') {
            throw new \RuntimeException('Could not resolve a Stripe customer for the order');
        }

        // ── 4. Resolve the matching orders row (for the unified order id used
        //      in webhook metadata) ───────────────────────────────────────────
        $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = :n LIMIT 1');
        $stmt->execute(['n' => $order['order_number']]);
        $unifiedOrderId = (int) ($stmt->fetchColumn() ?: 0);

        // ── 5. Create the draft invoice ──────────────────────────────────────
        $invoiceMetadata = [
            'order_type' => 'store',
            'store_order_id' => (string) $storeOrderId,
            'store_order_number' => (string) ($order['order_number'] ?? ''),
            'order_id' => (string) $unifiedOrderId,
            'user_id' => (string) ($order['user_id'] ?? ''),
            'member_id' => (string) ($order['member_id'] ?? ''),
            'cart_id' => (string) ($cartId ?? ''),
        ];

        $invoicePayload = [
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'pending_invoice_items_behavior' => 'exclude',
            'description' => 'Store order ' . ($order['order_number'] ?? ('#' . $storeOrderId)),
            'metadata' => $invoiceMetadata,
        ];

        $invoice = StripeService::createInvoice($invoicePayload, 'store_invoice_' . $storeOrderId);
        if (!$invoice || empty($invoice['id'])) {
            throw new \RuntimeException('Stripe invoice creation failed');
        }
        $invoiceId = (string) $invoice['id'];

        // ── 6. Add line items: one per product + shipping/discount/fee ───────
        foreach ($items as $item) {
            $stripeProductId = self::ensureStripeProduct((int) $item['sp_id'], [
                'title' => $item['sp_title'] ?: $item['title_snapshot'],
                'description' => $item['sp_description'] ?? null,
                'slug' => $item['sp_slug'] ?? null,
                'existing_stripe_id' => $item['stripe_product_id'] ?? null,
            ]);

            $description = (string) ($item['title_snapshot'] ?? '');
            if (!empty($item['variant_snapshot'])) {
                $description .= ' (' . $item['variant_snapshot'] . ')';
            }

            $payload = [
                'customer' => $customerId,
                'invoice' => $invoiceId,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'currency' => 'aud',
                'description' => $description,
                'metadata' => [
                    'store_order_item_id' => (string) $item['id'],
                    'store_product_id' => (string) ($item['sp_id'] ?? ''),
                ],
            ];
            $unitAmountCents = (int) round((float) $item['unit_price_final'] * 100);
            if ($stripeProductId !== '') {
                $payload['price_data'] = [
                    'currency' => 'aud',
                    'product' => $stripeProductId,
                    'unit_amount' => $unitAmountCents,
                ];
            } else {
                // Fall back to a one-off line if we couldn't sync the product
                $payload['price_data'] = [
                    'currency' => 'aud',
                    'product_data' => ['name' => $description !== '' ? $description : 'Store item'],
                    'unit_amount' => $unitAmountCents,
                ];
            }

            StripeService::createInvoiceItem($payload);
        }

        // Shipping line
        $shippingTotal = (float) ($order['shipping_total'] ?? 0);
        if ($shippingTotal > 0) {
            StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice' => $invoiceId,
                'amount' => (int) round($shippingTotal * 100),
                'currency' => 'aud',
                'description' => 'Shipping',
            ]);
        }

        // Discount line (negative amount)
        $discountTotal = (float) ($order['discount_total'] ?? 0);
        if ($discountTotal > 0) {
            $discountLabel = 'Discount';
            if (!empty($order['discount_code'])) {
                $discountLabel .= ' (' . $order['discount_code'] . ')';
            }
            StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice' => $invoiceId,
                'amount' => -(int) round($discountTotal * 100),
                'currency' => 'aud',
                'description' => $discountLabel,
            ]);
        }

        // Processing fee line
        $processingFee = (float) ($order['processing_fee_total'] ?? 0);
        if ($processingFee > 0) {
            StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice' => $invoiceId,
                'amount' => (int) round($processingFee * 100),
                'currency' => 'aud',
                'description' => 'Payment processing fee',
            ]);
        }

        // ── 7. Finalize → Stripe creates the linked PaymentIntent ────────────
        $finalized = StripeService::finalizeInvoice($invoiceId);
        if (!$finalized || empty($finalized['id'])) {
            throw new \RuntimeException('Stripe invoice finalize failed');
        }
        $invoice = $finalized;

        $resolved = self::resolveClientSecret($invoice);
        if ($resolved === null) {
            throw new \RuntimeException('Stripe invoice finalized but no PaymentIntent client_secret available');
        }

        // ── 8. Persist invoice + PI back onto our order rows ─────────────────
        $stmt = $pdo->prepare('UPDATE store_orders SET stripe_invoice_id = :inv, stripe_payment_intent_id = :pi, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['inv' => $resolved['invoice_id'], 'pi' => $resolved['payment_intent_id'], 'id' => $storeOrderId]);
        if ($unifiedOrderId > 0) {
            $stmt = $pdo->prepare('UPDATE orders SET stripe_invoice_id = :inv, stripe_payment_intent_id = :pi, payment_method = "stripe", payment_status = "pending", updated_at = NOW() WHERE id = :id');
            $stmt->execute(['inv' => $resolved['invoice_id'], 'pi' => $resolved['payment_intent_id'], 'id' => $unifiedOrderId]);
        }

        return $resolved;
    }

    /**
     * Look up — or create + persist — the Stripe Customer for an order. Members
     * with a stored `stripe_customer_id` are reused; otherwise we create a fresh
     * Customer using the order's customer_email/customer_name and (for members)
     * write the new id back to `members.stripe_customer_id` so future orders
     * land under the same Customer in dashboard.stripe.com/customers.
     */
    private static function ensureCustomer(array $order): string
    {
        $pdo = Database::connection();

        $memberId = (int) ($order['member_id'] ?? 0);
        $existingCustomerId = '';
        if ($memberId > 0) {
            $stmt = $pdo->prepare('SELECT stripe_customer_id FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $memberId]);
            $existingCustomerId = (string) ($stmt->fetchColumn() ?: '');
        }
        if ($existingCustomerId !== '') {
            return $existingCustomerId;
        }

        $email = trim((string) ($order['customer_email'] ?? ''));
        $name = trim((string) ($order['customer_name'] ?? ''));

        // If email is set, try to find an existing Customer to avoid duplicates
        if ($email !== '') {
            $found = StripeService::findCustomerByEmail($email);
            if ($found && !empty($found['id'])) {
                $customerId = (string) $found['id'];
                if ($memberId > 0) {
                    $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :cid WHERE id = :id AND (stripe_customer_id IS NULL OR stripe_customer_id = "")');
                    $stmt->execute(['cid' => $customerId, 'id' => $memberId]);
                }
                return $customerId;
            }
        }

        $payload = ['metadata' => ['source' => 'store_checkout']];
        if ($email !== '') {
            $payload['email'] = $email;
        }
        if ($name !== '') {
            $payload['name'] = $name;
        }
        if ($memberId > 0) {
            $payload['metadata']['member_id'] = (string) $memberId;
        }
        if (!empty($order['user_id'])) {
            $payload['metadata']['user_id'] = (string) $order['user_id'];
        }

        $customer = StripeService::createCustomerSimple($payload);
        if (!$customer || empty($customer['id'])) {
            return '';
        }
        $customerId = (string) $customer['id'];

        if ($memberId > 0) {
            $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :cid WHERE id = :id AND (stripe_customer_id IS NULL OR stripe_customer_id = "")');
            $stmt->execute(['cid' => $customerId, 'id' => $memberId]);
        }

        return $customerId;
    }

    /**
     * Ensure the given store_product has a matching Stripe Product. Cached on
     * the row via `store_products.stripe_product_id` so we only hit the API the
     * first time the product is referenced (or after a manual resync).
     */
    private static function ensureStripeProduct(int $storeProductId, array $hints = []): string
    {
        $existing = (string) ($hints['existing_stripe_id'] ?? '');
        if ($existing !== '') {
            return $existing;
        }
        if ($storeProductId <= 0) {
            return '';
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, title, description, slug, stripe_product_id FROM store_products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $storeProductId]);
        $product = $stmt->fetch();
        if (!$product) {
            return '';
        }
        if (!empty($product['stripe_product_id'])) {
            return (string) $product['stripe_product_id'];
        }

        $name = trim((string) ($product['title'] ?? ($hints['title'] ?? '')));
        if ($name === '') {
            $name = 'Store product #' . $storeProductId;
        }

        $payload = [
            'name' => $name,
            'metadata' => [
                'store_product_id' => (string) $product['id'],
                'store_slug' => (string) ($product['slug'] ?? ''),
            ],
        ];
        $description = trim((string) ($product['description'] ?? ''));
        if ($description !== '') {
            // Stripe limits description to 22_000 chars; trim conservatively
            $payload['description'] = mb_substr(strip_tags($description), 0, 500);
        }

        $stripeProduct = StripeService::createProduct($payload);
        if (!$stripeProduct || empty($stripeProduct['id'])) {
            return '';
        }
        $stripeProductId = (string) $stripeProduct['id'];

        $stmt = $pdo->prepare('UPDATE store_products SET stripe_product_id = :spid, updated_at = NOW() WHERE id = :id AND (stripe_product_id IS NULL OR stripe_product_id = "")');
        $stmt->execute(['spid' => $stripeProductId, 'id' => $storeProductId]);

        return $stripeProductId;
    }

    /**
     * Given a Stripe Invoice payload, dig out the PaymentIntent client_secret
     * needed by the browser's Payment Element. If the PI id is set but the
     * client_secret isn't expanded inline, retrieve it.
     */
    private static function resolveClientSecret(array $invoice): ?array
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($invoiceId === '') {
            return null;
        }

        $piRef = $invoice['payment_intent'] ?? null;
        if (!$piRef) {
            return null;
        }
        $paymentIntentId = is_array($piRef) ? (string) ($piRef['id'] ?? '') : (string) $piRef;
        $clientSecret = is_array($piRef) ? (string) ($piRef['client_secret'] ?? '') : '';

        if ($paymentIntentId === '') {
            return null;
        }
        if ($clientSecret === '') {
            $piData = StripeService::retrievePaymentIntentSimple($paymentIntentId);
            if (!$piData) {
                return null;
            }
            $clientSecret = (string) ($piData['client_secret'] ?? '');
        }
        if ($clientSecret === '') {
            return null;
        }

        return [
            'invoice_id' => $invoiceId,
            'payment_intent_id' => $paymentIntentId,
            'client_secret' => $clientSecret,
            'invoice_url' => $invoice['hosted_invoice_url'] ?? null,
        ];
    }
}
