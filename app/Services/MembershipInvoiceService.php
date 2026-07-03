<?php

namespace App\Services;

/**
 * Builds a Stripe Invoice (with itemized line items) for membership payments,
 * so the Stripe dashboard shows what was bought — line items, a hosted PDF, and
 * per-Customer billing history — exactly like the store flow (StoreInvoiceService).
 *
 * The invoice's PaymentIntent is what the customer pays via the on-page Stripe
 * Payment Element (collection_method=charge_automatically, but with no default
 * payment method it does NOT auto-charge on finalize — the Payment Element
 * confirms it).
 *
 * Two entry points:
 *   - createApplicationInvoice(): new-member join (/apply.php, /become-a-member).
 *     No order/member row exists yet, so the invoice is billed to a Customer
 *     derived from the applicant's email. Activation stays with apply.php's POST
 *     handler + admin approval, so the invoice.paid webhook is a no-op for these
 *     (PaymentWebhookService skips context=membership_application).
 *   - createRenewalInvoice(): logged-in member renewal. `orders` rows already
 *     exist; the invoice id + PI id are stamped onto every renewer order so
 *     PaymentWebhookService::handleInvoicePaid activates them all.
 *
 * No Stripe idempotency key is used: a cached invoice combined with our
 * (non-idempotent) createInvoiceItem calls would double the line items/total.
 * Each call therefore mints a fresh invoice; abandoned drafts never bill.
 */
class MembershipInvoiceService
{
    /**
     * @param array<int,array{description:string,cents:int}> $lines
     * @param array<string,string> $metadata
     * @return array{client_secret:string,payment_intent_id:string,invoice_id:string}
     */
    public static function createApplicationInvoice(
        string $email,
        string $name,
        array $lines,
        int $feeCents,
        array $metadata
    ): array {
        $customerId = self::ensureCustomerByEmail($email, $name);
        if ($customerId === '') {
            throw new \RuntimeException('Could not resolve a Stripe customer for the application.');
        }
        $invoiceMeta = array_merge([
            'order_type' => 'membership',
            'context'    => 'membership_application',
            'purpose'    => 'membership_application',
        ], $metadata);
        $description = 'AGA membership application' . ($name !== '' ? ' — ' . $name : '');
        return self::buildInvoice($customerId, $description, $invoiceMeta, $lines, $feeCents);
    }

    /**
     * @param array<int,array{order_id:int,label:string,cents:int}> $renewers
     * @param array<string,string> $metadata Already contains order_id / extra_order_ids etc.
     * @return array{client_secret:string,payment_intent_id:string,invoice_id:string}
     */
    public static function createRenewalInvoice(
        int $primaryMemberId,
        string $email,
        string $name,
        array $renewers,
        int $feeCents,
        string $currency,
        array $metadata
    ): array {
        $customerId = self::ensureCustomerByMember($primaryMemberId, $email, $name);
        if ($customerId === '') {
            throw new \RuntimeException('Could not resolve a Stripe customer for the renewal.');
        }
        $lines = [];
        foreach ($renewers as $r) {
            $lines[] = ['description' => (string) ($r['label'] ?? 'Membership renewal'), 'cents' => (int) ($r['cents'] ?? 0)];
        }
        $invoiceMeta = array_merge([
            'order_type' => 'membership',
            'context'    => 'membership_renewal',
        ], $metadata);
        $description = 'AGA membership renewal' . ($name !== '' ? ' — ' . $name : '');
        $resolved = self::buildInvoice($customerId, $description, $invoiceMeta, $lines, $feeCents);

        // Stamp every renewer order so the invoice.paid webhook can find and
        // activate them (primary + partner share one invoice).
        $pdo = Database::connection();
        foreach ($renewers as $r) {
            $orderId = (int) ($r['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $stmt = $pdo->prepare('UPDATE orders SET stripe_invoice_id = :inv, stripe_payment_intent_id = :pi, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'inv' => $resolved['invoice_id'],
                'pi'  => $resolved['payment_intent_id'],
                'id'  => $orderId,
            ]);
        }

        return $resolved;
    }

    /**
     * Itemized invoice for a single EXISTING pending membership order (the
     * billing-page "Pay now" path). Refresh-safe: reuses the order's open/draft
     * invoice instead of minting a new one each page load. Stamps the order with
     * the invoice + PI id so handleInvoicePaid activates it.
     *
     * @param array<string,mixed> $order      The membership `orders` row.
     * @param array<int,array{description:string,cents:int}> $lines
     * @param array<string,string> $metadata
     * @return array{client_secret:string,payment_intent_id:string,invoice_id:string}
     */
    public static function createOrderInvoice(array $order, string $email, string $name, array $lines, array $metadata): array
    {
        $orderId = (int) ($order['id'] ?? 0);

        // Reuse an existing open/draft invoice for this order if present.
        $existingInvoiceId = trim((string) ($order['stripe_invoice_id'] ?? ''));
        if ($existingInvoiceId !== '') {
            $existing = StripeService::retrieveInvoice($existingInvoiceId);
            if ($existing && in_array((string) ($existing['status'] ?? ''), ['draft', 'open'], true)) {
                $reused = self::resolveClientSecret($existing);
                if ($reused !== null) {
                    return $reused;
                }
            }
        }

        $memberId = (int) ($order['member_id'] ?? 0);
        $customerId = $memberId > 0
            ? self::ensureCustomerByMember($memberId, $email, $name)
            : self::ensureCustomerByEmail($email, $name);
        if ($customerId === '') {
            throw new \RuntimeException('Could not resolve a Stripe customer for the order.');
        }
        $invoiceMeta = array_merge(['order_type' => 'membership', 'context' => 'membership_order'], $metadata);
        $description = 'AGA membership — order ' . ($order['order_number'] ?? ('#' . $orderId));
        $resolved = self::buildInvoice($customerId, $description, $invoiceMeta, $lines, 0);

        if ($orderId > 0) {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('UPDATE orders SET stripe_invoice_id = :inv, stripe_payment_intent_id = :pi, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['inv' => $resolved['invoice_id'], 'pi' => $resolved['payment_intent_id'], 'id' => $orderId]);
        }
        return $resolved;
    }

    /**
     * Backfill: create an itemized Stripe Invoice for an ALREADY-PAID membership
     * order and mark it paid out of band (no re-charge), so historical orders
     * show as itemized invoices in the dashboard. Stamps the order's
     * stripe_invoice_id (only if currently empty). The invoice carries
     * context=membership_backfill so handleInvoicePaid skips it (no re-activation).
     *
     * @param array<string,mixed> $order
     * @param array<int,array{description:string,cents:int,quantity?:int}> $lines
     * @param bool $persist Stamp orders.stripe_invoice_id. Pass false for TEST-mode
     *   previews so a throwaway test invoice id doesn't poison the production row
     *   (which would make the later LIVE run skip the order).
     * @return array{invoice_id:string,hosted_invoice_url:?string,status:string}
     */
    public static function backfillPaidInvoiceForOrder(array $order, array $lines, string $email, string $name, bool $persist = true): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        $memberId = (int) ($order['member_id'] ?? 0);
        $customerId = $memberId > 0
            ? self::ensureCustomerByMember($memberId, $email, $name)
            : self::ensureCustomerByEmail($email, $name);
        if ($customerId === '') {
            throw new \RuntimeException('Could not resolve a Stripe customer for the order.');
        }

        $invoice = StripeService::createInvoice([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'pending_invoice_items_behavior' => 'exclude',
            'description' => 'AGA membership — order ' . ($order['order_number'] ?? ('#' . $orderId)) . ' (historical)',
            'metadata' => [
                'order_type'     => 'membership',
                'context'        => 'membership_backfill',
                'order_id'       => (string) $orderId,
                'order_number'   => (string) ($order['order_number'] ?? ''),
                'member_id'      => (string) $memberId,
                'backfilled'     => '1',
            ],
        ]);
        if (!$invoice || empty($invoice['id'])) {
            throw new \RuntimeException('Stripe invoice creation failed.');
        }
        $invoiceId = (string) $invoice['id'];

        foreach ($lines as $line) {
            $cents = (int) ($line['cents'] ?? 0);
            if ($cents <= 0) {
                continue;
            }
            $lname = trim((string) ($line['description'] ?? '')) ?: 'Membership';
            // amount+description shape — price_data.product_data is rejected by
            // the Invoice Items API (see buildInvoice); the old shape silently
            // produced $0 historical backfill records.
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice'  => $invoiceId,
                'amount'   => $cents * $qty,
                'currency' => 'aud',
                'description' => $qty > 1 ? ($lname . ' × ' . $qty) : $lname,
            ]);
        }

        $finalized = StripeService::finalizeInvoice($invoiceId);
        if (!$finalized || empty($finalized['id'])) {
            throw new \RuntimeException('Stripe invoice finalize failed.');
        }
        $paid = StripeService::payInvoiceOutOfBand($invoiceId);
        if (!$paid || ($paid['status'] ?? '') !== 'paid') {
            throw new \RuntimeException('Stripe invoice could not be marked paid out of band.');
        }

        if ($persist && $orderId > 0) {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('UPDATE orders SET stripe_invoice_id = :inv, updated_at = NOW() WHERE id = :id AND (stripe_invoice_id IS NULL OR stripe_invoice_id = "")');
            $stmt->execute(['inv' => $invoiceId, 'id' => $orderId]);
        }

        return [
            'invoice_id' => $invoiceId,
            'hosted_invoice_url' => $paid['hosted_invoice_url'] ?? null,
            'status' => (string) ($paid['status'] ?? ''),
        ];
    }

    /**
     * @param array<int,array{description:string,cents:int}> $lines
     * @param array<string,string> $metadata
     * @return array{client_secret:string,payment_intent_id:string,invoice_id:string}
     */
    private static function buildInvoice(string $customerId, string $description, array $metadata, array $lines, int $feeCents): array
    {
        $invoice = StripeService::createInvoice([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'pending_invoice_items_behavior' => 'exclude',
            'description' => $description,
            'metadata' => $metadata,
        ]);
        if (!$invoice || empty($invoice['id'])) {
            throw new \RuntimeException('Stripe invoice creation failed.');
        }
        $invoiceId = (string) $invoice['id'];

        // Every line uses the plain amount+description shape — the Invoice
        // Items API does NOT accept Checkout-style price_data.product_data.
        // That wrong shape made Stripe reject every membership line while the
        // fee line (already amount-shaped) survived, silently charging members
        // only the card fee (July 2026: $2.40/$7.48 fee-only renewals). Any
        // failed line now aborts the whole invoice instead of undercharging.
        $expectedCents = 0;
        foreach ($lines as $line) {
            $cents = (int) ($line['cents'] ?? 0);
            if ($cents <= 0) {
                continue;
            }
            $name = trim((string) ($line['description'] ?? ''));
            $item = StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice'  => $invoiceId,
                'amount'   => $cents,
                'currency' => 'aud',
                'description' => $name !== '' ? $name : 'Membership',
            ]);
            if (!$item || empty($item['id'])) {
                StripeService::deleteInvoice($invoiceId); // draft cleanup, best effort
                throw new \RuntimeException('Stripe invoice line "' . ($name !== '' ? $name : 'Membership') . '" could not be added — payment aborted so you are not undercharged. Please try again.');
            }
            $expectedCents += $cents;
        }
        if ($expectedCents <= 0) {
            StripeService::deleteInvoice($invoiceId);
            throw new \RuntimeException('Stripe invoice has no membership lines — payment aborted.');
        }

        if ($feeCents > 0) {
            $feeItem = StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice'  => $invoiceId,
                'amount'   => $feeCents,
                'currency' => 'aud',
                'description' => 'Card processing fee (Stripe)',
            ]);
            if (!$feeItem || empty($feeItem['id'])) {
                StripeService::deleteInvoice($invoiceId);
                throw new \RuntimeException('Stripe invoice fee line could not be added — payment aborted. Please try again.');
            }
            $expectedCents += $feeCents;
        }

        $finalized = StripeService::finalizeInvoice($invoiceId);
        if (!$finalized || empty($finalized['id'])) {
            throw new \RuntimeException('Stripe invoice finalize failed.');
        }

        // Belt and braces: the member must never be charged a different amount
        // than the drawer displays. Any mismatch voids the invoice and aborts.
        $amountDue = (int) ($finalized['amount_due'] ?? -1);
        if ($amountDue !== $expectedCents) {
            StripeService::voidInvoice($invoiceId);
            throw new \RuntimeException(sprintf(
                'Stripe invoice total (%d cents) does not match the expected total (%d cents) — payment aborted.',
                $amountDue,
                $expectedCents
            ));
        }

        $resolved = self::resolveClientSecret($finalized);
        if ($resolved === null) {
            throw new \RuntimeException('Stripe invoice finalized but no PaymentIntent client_secret available.');
        }
        return $resolved;
    }

    /** Reuse the member's Stripe Customer, else find-by-email / create + persist. */
    private static function ensureCustomerByMember(int $memberId, string $email, string $name): string
    {
        $pdo = Database::connection();
        if ($memberId > 0) {
            $stmt = $pdo->prepare('SELECT stripe_customer_id FROM members WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $memberId]);
            $existing = (string) ($stmt->fetchColumn() ?: '');
            if ($existing !== '') {
                // Verify the stored customer exists in the current Stripe mode (test vs live
                // have separate customer namespaces; a live ID is invalid in test mode).
                $check = StripeService::retrieveCustomerSimple($existing);
                if ($check && empty($check['deleted'])) {
                    return $existing;
                }
                // Customer not found in this mode — clear the stale ID and fall through.
                $pdo->prepare('UPDATE members SET stripe_customer_id = NULL WHERE id = :id')
                    ->execute(['id' => $memberId]);
            }
        }

        $customerId = self::findOrCreateCustomer($email, $name, $memberId > 0 ? ['member_id' => (string) $memberId, 'source' => 'membership_renewal'] : ['source' => 'membership_renewal']);
        if ($customerId !== '' && $memberId > 0) {
            $stmt = $pdo->prepare('UPDATE members SET stripe_customer_id = :cid WHERE id = :id AND (stripe_customer_id IS NULL OR stripe_customer_id = "")');
            $stmt->execute(['cid' => $customerId, 'id' => $memberId]);
        }
        return $customerId;
    }

    /** New joiners have no member row yet — find or create a Customer by email. */
    private static function ensureCustomerByEmail(string $email, string $name): string
    {
        return self::findOrCreateCustomer($email, $name, ['source' => 'membership_application']);
    }

    /** @param array<string,string> $metadata */
    private static function findOrCreateCustomer(string $email, string $name, array $metadata): string
    {
        $email = trim($email);
        $name = trim($name);
        if ($email !== '') {
            $found = StripeService::findCustomerByEmail($email);
            if ($found && !empty($found['id'])) {
                return (string) $found['id'];
            }
        }
        $payload = ['metadata' => $metadata];
        if ($email !== '') {
            $payload['email'] = $email;
        }
        if ($name !== '') {
            $payload['name'] = $name;
        }
        $customer = StripeService::createCustomerSimple($payload);
        if (!$customer || empty($customer['id'])) {
            return '';
        }
        return (string) $customer['id'];
    }

    /**
     * Dig the PaymentIntent client_secret out of a finalized invoice (retrieving
     * the PI separately if it isn't expanded inline).
     *
     * @return array{client_secret:string,payment_intent_id:string,invoice_id:string}|null
     */
    private static function resolveClientSecret(array $invoice): ?array
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        $piRef = $invoice['payment_intent'] ?? null;
        if ($invoiceId === '' || !$piRef) {
            return null;
        }
        $paymentIntentId = is_array($piRef) ? (string) ($piRef['id'] ?? '') : (string) $piRef;
        $clientSecret = is_array($piRef) ? (string) ($piRef['client_secret'] ?? '') : '';
        if ($paymentIntentId === '') {
            return null;
        }
        if ($clientSecret === '') {
            $pi = StripeService::retrievePaymentIntentSimple($paymentIntentId);
            $clientSecret = $pi ? (string) ($pi['client_secret'] ?? '') : '';
        }
        if ($clientSecret === '') {
            return null;
        }
        return [
            'invoice_id' => $invoiceId,
            'payment_intent_id' => $paymentIntentId,
            'client_secret' => $clientSecret,
        ];
    }
}
