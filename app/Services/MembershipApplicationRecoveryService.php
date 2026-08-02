<?php

namespace App\Services;

use PDO;

/**
 * Safety net for the /apply.php card flow.
 *
 * A new-member card application charges the card FIRST (the finalized Stripe
 * Invoice's PaymentIntent, context=membership_application) and only writes the
 * member + membership_applications + orders rows afterwards, in a browser AJAX
 * POST back to apply.php. If that POST never completes — an issuer/3DS redirect
 * that reloads the page and wipes the form, a closed tab, a dropped network —
 * the money is captured but nothing lands in the DB. The invoice.paid webhook
 * deliberately no-ops for membership_application (see PaymentWebhookService),
 * so without this there is no server-side recovery and the applicant is lost.
 *
 * This service finds those orphaned paid invoices and reconstructs the PENDING
 * member + application + paid order, then notifies the committee — exactly what
 * a successful apply.php POST would have done.
 *
 * It rebuilds from the ApplicationDraft where there is one: apply.php banks the
 * applicant's whole form server-side in the same click that confirms the card,
 * so a recovered application is complete — bikes, associate details, assist
 * offers and all. Older stranded payments predate drafts and fall back to the
 * invoice metadata, which carries contact details but not those extras.
 *
 * Used by the admin rescue tool (reconcile-stranded-payments.php, immediate) and
 * by cron (recover_stranded_applications.php, delayed). It is idempotent and
 * deduped so it never double-creates a member or races the normal browser POST.
 */
class MembershipApplicationRecoveryService
{
    /**
     * Only touch invoices paid at least this long ago, so the normal apply.php
     * POST (which fires seconds after payment) always wins and we never race it.
     */
    private const GRACE_MINUTES = 15;

    /** How far back to scan Stripe for paid application invoices. */
    private const LOOKBACK_DAYS = 45;

    /**
     * Paid membership_application invoices in Stripe whose applicant is not in
     * the DB. Each row carries what the UI/cron needs to display and recover.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function findOrphans(int $lookbackDays = self::LOOKBACK_DAYS, int $graceMinutes = self::GRACE_MINUTES): array
    {
        $invoices = StripeService::listInvoices([
            'status' => 'paid',
            'created' => ['gte' => time() - ($lookbackDays * 86400)],
            'limit' => 100,
        ]);

        $cutoff = time() - ($graceMinutes * 60);
        $orphans = [];
        $seenEmails = []; // to flag a second paid invoice for the same applicant (likely double payment)

        foreach ($invoices as $inv) {
            $meta = is_array($inv['metadata'] ?? null) ? $inv['metadata'] : [];
            $context = (string) ($meta['context'] ?? ($meta['purpose'] ?? ''));
            if ($context !== 'membership_application') {
                continue;
            }
            if (!self::invoiceIsPaid($inv)) {
                continue;
            }
            $paidAt = (int) ($inv['status_transitions']['paid_at'] ?? ($inv['created'] ?? 0));
            if ($paidAt > $cutoff) {
                continue; // still inside the grace window — let the browser POST land first
            }

            $invoiceId = (string) ($inv['id'] ?? '');
            $email = strtolower(trim((string) ($inv['customer_email'] ?? ($meta['email'] ?? ''))));

            if (self::alreadyRecorded($invoiceId, $email) !== null) {
                continue; // the applicant already made it into the DB
            }

            $duplicate = ($email !== '' && isset($seenEmails[$email]));
            if ($email !== '') {
                $seenEmails[$email] = true;
            }

            $orphans[] = [
                'invoice_id' => $invoiceId,
                'payment_intent_id' => is_array($inv['payment_intent'] ?? null)
                    ? (string) ($inv['payment_intent']['id'] ?? '')
                    : (string) ($inv['payment_intent'] ?? ''),
                'customer_id' => (string) ($inv['customer'] ?? ''),
                'email' => $email,
                'name' => trim((string) ($meta['first_name'] ?? '') . ' ' . (string) ($meta['last_name'] ?? '')),
                'amount_paid_cents' => (int) ($inv['amount_paid'] ?? 0),
                'paid_at' => $paidAt,
                'hosted_invoice_url' => (string) ($inv['hosted_invoice_url'] ?? ''),
                'duplicate_payment' => $duplicate,
                'metadata' => $meta,
                'invoice' => $inv,
            ];
        }

        return $orphans;
    }

    /**
     * Recover a single orphaned invoice into a PENDING member + application +
     * paid order. Idempotent: if the applicant already exists (matched on the
     * invoice id stamped on an order, or on the applicant email), it is a no-op.
     *
     * @param array<string,mixed> $invoice A Stripe invoice array (from findOrphans or retrieveInvoice).
     * @return array{ok:bool,msg:string,member_id:?int,order_id:?int,duplicate:bool}
     */
    public static function recoverInvoice(array $invoice): array
    {
        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($invoiceId === '') {
            return ['ok' => false, 'msg' => 'Invoice id missing.', 'member_id' => null, 'order_id' => null, 'duplicate' => false];
        }

        // Re-fetch fresh so a stale/partial row can't drive activation off wrong data.
        $fresh = StripeService::retrieveInvoice($invoiceId);
        if ($fresh) {
            $invoice = $fresh;
        }
        if (!self::invoiceIsPaid($invoice)) {
            return ['ok' => false, 'msg' => 'Stripe does not report this invoice as paid — skipped.', 'member_id' => null, 'order_id' => null, 'duplicate' => false];
        }

        $meta = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $context = (string) ($meta['context'] ?? ($meta['purpose'] ?? ''));
        if ($context !== 'membership_application') {
            return ['ok' => false, 'msg' => 'Not a membership application invoice — skipped.', 'member_id' => null, 'order_id' => null, 'duplicate' => false];
        }

        $email = strtolower(trim((string) ($invoice['customer_email'] ?? ($meta['email'] ?? ''))));

        // Dedupe: never double-create. Matched by the invoice id stamped on an
        // order, or by the applicant email (the normal POST creates the member
        // with that email). A second paid invoice for the same applicant lands
        // here as a duplicate — one member, and the extra payment is surfaced.
        $existing = self::alreadyRecorded($invoiceId, $email);
        if ($existing !== null) {
            return [
                'ok' => false,
                'duplicate' => true,
                'member_id' => $existing['member_id'],
                'order_id' => null,
                'msg' => 'Applicant already in the system (member #' . $existing['member_id'] . ', via ' . $existing['via']
                    . '). Not re-created. If this invoice is a second charge for the same person, review it for a refund.',
            ];
        }

        // No outer transaction, on purpose: createMembershipOrder() opens its own
        // (nextOrderNumber), and PDO/MySQL can't nest — apply.php calls it outside
        // a transaction for the same reason. member + application persist first;
        // the order is best-effort (as in apply.php), and dedupe on the applicant
        // email stops any re-run from creating a second member.
        $pdo = Database::connection();
        try {
            $result = self::createFromInvoice($pdo, $invoice, $email, $meta);
        } catch (\Throwable $e) {
            error_log('[MembershipApplicationRecoveryService] recover failed for ' . $invoiceId . ': ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Recovery error: ' . $e->getMessage(), 'member_id' => null, 'order_id' => null, 'duplicate' => false];
        }

        ApplicationDraftService::clearForEmail($email);

        // Notifications + audit run outside the DB transaction — a mail failure
        // must never undo a recovered application.
        self::notify($result['member_id'], $result['member_name'], $email, $result['member_type'], $result['order_number']);
        AuditService::log(null, 'application_recovered', 'Recovered stranded Stripe membership application (invoice ' . $invoiceId . ').');
        ActivityLogger::log('system', null, $result['member_id'], 'membership.application_recovered', [
            'member_id' => $result['member_id'],
            'order_id' => $result['order_id'],
            'stripe_invoice_id' => $invoiceId,
            'stripe_payment_intent_id' => $result['payment_intent_id'],
            'amount_paid_cents' => (int) ($invoice['amount_paid'] ?? 0),
        ]);

        return [
            'ok' => true,
            'duplicate' => false,
            'member_id' => $result['member_id'],
            'order_id' => $result['order_id'],
            'msg' => 'Created PENDING member #' . $result['member_id'] . ' + application. Committee notified.',
        ];
    }

    /** @param array<string,mixed> $inv */
    private static function invoiceIsPaid(array $inv): bool
    {
        if ((string) ($inv['status'] ?? '') === 'paid') {
            return true;
        }
        $paid = (int) ($inv['amount_paid'] ?? 0);
        $due = (int) ($inv['amount_due'] ?? 0);
        return $paid > 0 && $paid >= $due;
    }

    /**
     * Is this applicant already in the DB? Matched by the invoice id stamped on
     * a membership order, then by applicant email.
     *
     * @return array{via:string,member_id:int}|null
     */
    private static function alreadyRecorded(string $invoiceId, string $email): ?array
    {
        $pdo = Database::connection();
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare("SELECT member_id FROM orders WHERE stripe_invoice_id = :inv AND order_type = 'membership' LIMIT 1");
            $stmt->execute(['inv' => $invoiceId]);
            $row = $stmt->fetch();
            if ($row) {
                return ['via' => 'order', 'member_id' => (int) ($row['member_id'] ?? 0)];
            }
        }
        if ($email !== '') {
            $stmt = $pdo->prepare('SELECT id FROM members WHERE LOWER(email) = :email ORDER BY id DESC LIMIT 1');
            $stmt->execute(['email' => $email]);
            $row = $stmt->fetch();
            if ($row) {
                return ['via' => 'email', 'member_id' => (int) ($row['id'] ?? 0)];
            }
        }
        return null;
    }

    /**
     * Build the PENDING member + membership_applications + paid order. Mirrors
     * apply.php's happy-path INSERTs, from the invoice metadata + Customer email.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,string> $meta
     * @return array{member_id:int,order_id:?int,member_name:string,member_type:string,order_number:string,payment_intent_id:string}
     */
    private static function createFromInvoice(PDO $pdo, array $invoice, string $email, array $meta): array
    {
        $fullSelected = (string) ($meta['membership_full'] ?? '') === '1';
        $associateSelected = (string) ($meta['membership_associate'] ?? '') === '1';
        $memberType = $fullSelected ? 'FULL' : ($associateSelected ? 'ASSOCIATE' : 'FULL');

        $firstName = trim((string) ($meta['first_name'] ?? ''));
        $lastName = trim((string) ($meta['last_name'] ?? ''));
        if ($email === '') {
            $email = strtolower(trim((string) ($invoice['customer_email'] ?? '')));
        }

        $paymentIntentId = is_array($invoice['payment_intent'] ?? null)
            ? (string) ($invoice['payment_intent']['id'] ?? '')
            : (string) ($invoice['payment_intent'] ?? '');
        $invoiceId = (string) ($invoice['id'] ?? '');

        // The applicant's own form, banked server-side before the card was
        // charged. Used for the identity/contact/extras fields — the membership
        // selections below stay on the invoice metadata, because that is what
        // was actually billed.
        $draft = ApplicationDraftService::find($invoiceId, $email) ?? [];
        $draftVal = static function (string $key) use ($draft): string {
            return trim((string) ($draft[$key] ?? ''));
        };
        // Draft first, invoice metadata second — either may be blank.
        $pick = static function (string $draftKey, string $metaKey) use ($draftVal, $meta): ?string {
            $v = $draftVal($draftKey);
            if ($v === '') {
                $v = trim((string) ($meta[$metaKey] ?? ''));
            }
            return $v !== '' ? $v : null;
        };
        if ($draftVal('first_name') !== '') {
            $firstName = $draftVal('first_name');
        }
        if ($draftVal('last_name') !== '') {
            $lastName = $draftVal('last_name');
        }

        $fullMagazineType = strtoupper(trim((string) ($meta['full_magazine_type'] ?? 'PRINTED')));
        $fullPeriodKey = strtoupper(trim((string) ($meta['full_period_key'] ?? '')));
        $associatePeriodKey = strtoupper(trim((string) ($meta['associate_period_key'] ?? '')));
        $associateMagazine = $fullSelected ? $fullMagazineType : 'PRINTED';

        // Prices: prefer the live matrix (what apply.php would compute), else fall
        // back to what Stripe actually collected so the order total is never $0.
        $fullPriceCents = ($fullSelected && $fullPeriodKey !== '')
            ? MembershipPricingService::resolveJoinPriceCents($fullMagazineType, 'FULL', $fullPeriodKey)
            : null;
        $associatePriceCents = ($associateSelected && $associatePeriodKey !== '')
            ? MembershipPricingService::resolveJoinPriceCents($associateMagazine, 'ASSOCIATE', $associatePeriodKey)
            : null;

        $processingFeeCents = (int) ($meta['processing_fee_cents'] ?? 0);
        $totalWithFeeCents = (int) ($meta['total_with_fee_cents'] ?? 0);
        $membershipCents = (int) ($fullPriceCents ?? 0) + (int) ($associatePriceCents ?? 0);
        if ($membershipCents <= 0) {
            // Derive membership total from what Stripe collected, minus the fee line.
            $amountPaid = (int) ($invoice['amount_paid'] ?? 0);
            $membershipCents = max(0, ($totalWithFeeCents > 0 ? $totalWithFeeCents : $amountPaid) - $processingFeeCents);
        }

        // --- members row (PENDING, no member number — admin assigns at approval) ---
        $hasDob = MemberRepository::hasMemberColumn($pdo, 'date_of_birth');
        $dobColumnSql = $hasDob ? ', date_of_birth' : '';
        $dobValueSql = $hasDob ? ', :date_of_birth' : '';
        // Assist offers + magazine exclusions are checkboxes: only present in the
        // draft when the applicant ticked them, and absent from invoice metadata
        // entirely (a metadata-only rebuild leaves them all 0, as before).
        $flag = static fn(string $key): int => $draftVal($key) !== '' ? 1 : 0;
        $stmt = $pdo->prepare('INSERT INTO members (member_type, status, member_number_base, member_number_suffix, full_member_id, chapter_id, first_name, last_name, email, phone' . $dobColumnSql . ', address_line1, address_line2, city, state, postal_code, country, privacy_level, assist_ute, assist_phone, assist_bed, assist_tools, exclude_printed, exclude_electronic, created_at) VALUES (:member_type, :status, NULL, 0, NULL, NULL, :first_name, :last_name, :email, :phone' . $dobValueSql . ', :address1, :address2, :city, :state, :postal, :country, :privacy, :assist_ute, :assist_phone, :assist_bed, :assist_tools, :exclude_printed, :exclude_electronic, NOW())');
        $params = [
            'member_type' => $memberType,
            'status' => 'PENDING',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email !== '' ? $email : null,
            'phone' => $pick('phone', 'phone'),
            'address1' => $pick('address_line1', 'address_line1'),
            'address2' => $pick('address_line2', 'address_line2'),
            'city' => $pick('city', 'city'),
            'state' => $pick('state', 'state'),
            'postal' => $pick('postal_code', 'postal_code'),
            'country' => $pick('country', 'country'),
            'privacy' => $pick('privacy_level', 'privacy_level') ?? 'A',
            'assist_ute' => $flag('assist_ute'),
            'assist_phone' => $flag('assist_phone'),
            'assist_bed' => $flag('assist_bed'),
            'assist_tools' => $flag('assist_tools'),
            'exclude_printed' => $flag('exclude_printed'),
            'exclude_electronic' => $flag('exclude_electronic'),
        ];
        if ($hasDob) {
            $draftDob = MemberRepository::composeDateOfBirth($draftVal('dob_year'), $draftVal('dob_month'), $draftVal('dob_day'));
            $params['date_of_birth'] = $draftDob ?: self::metaVal($meta, 'dob');
        }
        $stmt->execute($params);
        $memberId = (int) $pdo->lastInsertId();
        if ($memberId <= 0) {
            throw new \RuntimeException('member insert returned no id');
        }

        // --- the applicant's bikes (draft only — never fits in metadata) ---
        $decodeVehicles = static function (string $json): array {
            $rows = json_decode($json, true);
            return is_array($rows) ? $rows : [];
        };
        $fullVehicles = $decodeVehicles($draftVal('full_vehicle_payload'));
        $associateVehicles = $decodeVehicles($draftVal('associate_vehicle_payload'));
        self::insertBikes($pdo, $memberId, $fullVehicles);

        // --- membership_applications row (PENDING) ---
        $applicationNotes = [
            'referral_source' => $pick('referral_source', 'referral_source') ?? '',
            'associate' => [
                'first_name' => $pick('associate_first_name', 'associate_first_name') ?? '',
                'last_name' => $pick('associate_last_name', 'associate_last_name') ?? '',
                'email' => $pick('associate_email', 'associate_email') ?? '',
                'phone' => $draftVal('associate_phone'),
                'date_of_birth' => MemberRepository::composeDateOfBirth($draftVal('associate_dob_year'), $draftVal('associate_dob_month'), $draftVal('associate_dob_day')),
                'address_line1' => $draftVal('associate_address_line1'),
                'city' => $draftVal('associate_city'),
                'state' => $draftVal('associate_state'),
                'postal_code' => $draftVal('associate_postal_code'),
                'country' => $draftVal('associate_country'),
                'address_diff' => $draftVal('associate_address_diff'),
            ],
            'vehicles' => [
                'full' => $fullVehicles,
                'associate' => $associateVehicles,
            ],
            'membership' => [
                'full_selected' => $fullSelected,
                'associate_selected' => $associateSelected,
                'associate_add' => (string) ($meta['associate_add'] ?? ''),
                'full' => ['magazine_type' => $fullMagazineType, 'period_key' => $fullPeriodKey, 'price_cents' => $fullPriceCents],
                'associate' => ['magazine_type' => $associateMagazine, 'period_key' => $associatePeriodKey, 'price_cents' => $associatePriceCents],
                'total_cents' => $membershipCents,
                'processing_fee_cents' => $processingFeeCents,
                'total_with_fee_cents' => $totalWithFeeCents,
            ],
            'requested_chapter_id' => $pick('requested_chapter_id', 'requested_chapter_id') !== null
                ? (int) $pick('requested_chapter_id', 'requested_chapter_id')
                : null,
            'payment_method' => 'card',
            // Flags so the committee (and any audit) can see this application was
            // rebuilt from a stranded Stripe payment, not a normal submission.
            'recovered_from_stripe' => true,
            'recovered_from_draft' => $draft !== [],
            'source_invoice_id' => $invoiceId,
            'source_payment_intent_id' => $paymentIntentId,
        ];
        $stmt = $pdo->prepare('INSERT INTO membership_applications (member_id, member_type, status, notes, created_at) VALUES (:member_id, :member_type, :status, :notes, NOW())');
        $stmt->execute([
            'member_id' => $memberId,
            'member_type' => $memberType,
            'status' => 'PENDING',
            'notes' => json_encode($applicationNotes, JSON_UNESCAPED_SLASHES) ?: null,
        ]);

        // --- paid membership order (stamped with the invoice/PI so it's the
        //     dedupe key next time, shows on the profile, and reconcile sees it) ---
        $orderItems = [];
        if ($fullSelected) {
            $orderItems[] = ['product_id' => null, 'name' => 'Full membership ' . $fullPeriodKey, 'quantity' => 1, 'unit_price' => round((int) ($fullPriceCents ?? 0) / 100, 2), 'is_physical' => 0];
        }
        if ($associateSelected) {
            $orderItems[] = ['product_id' => null, 'name' => 'Associate membership ' . $associatePeriodKey, 'quantity' => 1, 'unit_price' => round((int) ($associatePriceCents ?? 0) / 100, 2), 'is_physical' => 0];
        }

        // Best-effort, exactly as apply.php: a failure here must not sink the
        // recovered member/application — approval re-creates the order if absent.
        $order = null;
        try {
            $order = MembershipOrderService::createMembershipOrder($memberId, 0, round($membershipCents / 100, 2), [
                'payment_method' => 'card',
                'payment_status' => 'accepted',
                'fulfillment_status' => 'pending',
                'currency' => 'AUD',
                'items' => $orderItems,
                'term' => $fullSelected ? $fullPeriodKey : $associatePeriodKey,
                'admin_notes' => 'Recovered stranded Stripe payment (invoice ' . $invoiceId . ')',
                'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                'stripe_invoice_id' => $invoiceId !== '' ? $invoiceId : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[MembershipApplicationRecoveryService] order creation failed for member #' . $memberId . ': ' . $e->getMessage());
        }

        return [
            'member_id' => $memberId,
            'order_id' => $order ? (int) ($order['id'] ?? 0) : null,
            'member_name' => trim($firstName . ' ' . $lastName),
            'member_type' => $memberType,
            'order_number' => $order ? (string) ($order['order_number'] ?? '') : '',
            'payment_intent_id' => $paymentIntentId,
        ];
    }

    /**
     * Write the applicant's bikes, mirroring apply.php's column probing so this
     * works on any schema vintage. Best-effort: a bike that won't insert must
     * never cost us the recovered application.
     *
     * @param array<int,mixed> $vehicles
     */
    private static function insertBikes(PDO $pdo, int $memberId, array $vehicles): void
    {
        if (!$vehicles) {
            return;
        }
        try {
            $columns = $pdo->query('SHOW COLUMNS FROM member_bikes')->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            return;
        }
        $hasRego = in_array('rego', $columns, true);
        $hasColour = in_array('colour', $columns, true);
        $hasColor = in_array('color', $columns, true);
        $hasPrimary = in_array('is_primary', $columns, true);
        $primarySet = false;

        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }
            $make = trim((string) ($vehicle['make'] ?? ''));
            $model = trim((string) ($vehicle['model'] ?? ''));
            if ($make === '' || $model === '') {
                continue;
            }
            $yearValue = trim((string) ($vehicle['year'] ?? ''));
            $colour = trim((string) ($vehicle['colour'] ?? ($vehicle['color'] ?? '')));
            $rego = substr(trim((string) ($vehicle['rego'] ?? '')), 0, 20);

            $cols = ['member_id', 'make', 'model', 'year', 'created_at'];
            $vals = [':member_id', ':make', ':model', ':year', 'NOW()'];
            $params = [
                'member_id' => $memberId,
                'make' => $make,
                'model' => $model,
                'year' => ($yearValue !== '' && is_numeric($yearValue)) ? (int) $yearValue : null,
            ];
            if ($hasRego) {
                $cols[] = 'rego';
                $vals[] = ':rego';
                $params['rego'] = $rego !== '' ? $rego : null;
            }
            if ($colour !== '' && ($hasColour || $hasColor)) {
                $col = $hasColour ? 'colour' : 'color';
                $cols[] = $col;
                $vals[] = ':' . $col;
                $params[$col] = $colour;
            }
            if ($hasPrimary && !$primarySet) {
                $cols[] = 'is_primary';
                $vals[] = ':is_primary';
                $params['is_primary'] = 1;
            }
            try {
                $stmt = $pdo->prepare('INSERT INTO member_bikes (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')');
                $stmt->execute($params);
                $primarySet = true;
            } catch (\Throwable $e) {
                error_log('[MembershipApplicationRecoveryService] bike insert failed for member #' . $memberId . ': ' . $e->getMessage());
            }
        }
    }

    /** Trim + null-coalesce a metadata string (metadata values are always strings). */
    private static function metaVal(array $meta, string $key): ?string
    {
        $v = trim((string) ($meta[$key] ?? ''));
        return $v !== '' ? $v : null;
    }

    /** Committee "new application" alert + applicant acknowledgement, same as apply.php. */
    private static function notify(int $memberId, string $memberName, string $email, string $memberType, string $orderNumber): void
    {
        $memberTypeLabel = $memberType === 'ASSOCIATE' ? 'Associate' : 'Full';
        $siteName = (string) SettingsService::getGlobal('site.name', 'Australian Goldwing Association');
        $adminEmails = NotificationService::getAdminEmails();
        $reviewLink = BaseUrlService::buildUrl('/admin/index.php?page=applications');
        $displayName = $memberName !== '' ? $memberName : ($email !== '' ? $email : ('member #' . $memberId));

        try {
            NotificationService::dispatch('application_admin_new_submission', [
                'primary_email' => '',
                'admin_emails' => $adminEmails,
                'applicant_name' => NotificationService::escape($displayName),
                'applicant_email' => NotificationService::escape($email !== '' ? $email : '—'),
                'applicant_phone' => NotificationService::escape('—'),
                'member_type' => NotificationService::escape($memberTypeLabel),
                'review_link' => NotificationService::escape($reviewLink),
            ]);
        } catch (\Throwable $e) {
            error_log('[MembershipApplicationRecoveryService] admin notification failed: ' . $e->getMessage());
        }

        if ($email !== '') {
            try {
                NotificationService::dispatch('application_member_submitted', [
                    'primary_email' => $email,
                    'admin_emails' => $adminEmails,
                    'member_name' => NotificationService::escape($memberName !== '' ? $memberName : $displayName),
                    'member_type' => NotificationService::escape($memberTypeLabel),
                    'site_name' => NotificationService::escape($siteName),
                ]);
            } catch (\Throwable $e) {
                error_log('[MembershipApplicationRecoveryService] applicant notification failed: ' . $e->getMessage());
            }
        }
    }
}
