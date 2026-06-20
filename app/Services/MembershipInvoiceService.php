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

        foreach ($lines as $line) {
            $cents = (int) ($line['cents'] ?? 0);
            if ($cents <= 0) {
                continue;
            }
            $name = trim((string) ($line['description'] ?? ''));
            StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice'  => $invoiceId,
                'currency' => 'aud',
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => ['name' => $name !== '' ? $name : 'Membership'],
                    'unit_amount' => $cents,
                ],
            ]);
        }

        if ($feeCents > 0) {
            StripeService::createInvoiceItem([
                'customer' => $customerId,
                'invoice'  => $invoiceId,
                'amount'   => $feeCents,
                'currency' => 'aud',
                'description' => 'Card processing fee',
            ]);
        }

        $finalized = StripeService::finalizeInvoice($invoiceId);
        if (!$finalized || empty($finalized['id'])) {
            throw new \RuntimeException('Stripe invoice finalize failed.');
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
                return $existing;
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
