<?php
namespace App\Services;

use DateTimeImmutable;
use PDO;

class AgmRegistrationService
{
    public static function computePricingTier(array $event, ?DateTimeImmutable $now = null): string
    {
        if (empty($event['late_fee_starts_at'])) {
            return 'early';
        }
        $now = $now ?? new DateTimeImmutable('now');
        $cutoff = new DateTimeImmutable((string) $event['late_fee_starts_at']);
        return $now >= $cutoff ? 'late' : 'early';
    }

    public static function isRegistrationOpen(array $event, ?DateTimeImmutable $now = null): bool
    {
        if (($event['status'] ?? '') !== 'published') {
            return false;
        }
        $now = $now ?? new DateTimeImmutable('now');
        if (!empty($event['registration_opens_at']) && $now < new DateTimeImmutable((string) $event['registration_opens_at'])) {
            return false;
        }
        if (!empty($event['registration_closes_at']) && $now > new DateTimeImmutable((string) $event['registration_closes_at'])) {
            return false;
        }
        return true;
    }

    public static function generateRegistrationNumber(int $eventId, int $year): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM agm_registrations WHERE agm_event_id = :event_id');
        $stmt->execute(['event_id' => $eventId]);
        $seq = ((int) $stmt->fetchColumn()) + 1;
        return sprintf('AGM-%d-%05d', $year, $seq);
    }

    public static function createRegistration(array $event, array $payload, array $items, array $motorcycles, array $context = []): array
    {
        $pdo = Database::connection();
        $pricingTier = self::computePricingTier($event);
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) ($item['unit_price'] ?? 0) * (int) ($item['quantity'] ?? 0);
        }
        $total = $subtotal;
        $registrationNumber = self::generateRegistrationNumber((int) $event['id'], (int) $event['year']);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO agm_registrations (agm_event_id, registration_number, member_id, user_id, submitted_by_user_id, attendee1_name, attendee1_member_number, attendee1_is_over_65, attendee2_name, attendee2_member_number, attendee2_is_over_65, children_text, contact_phone_1, contact_phone_2, address, postcode, email, chapter, emergency_1_name, emergency_1_phone, emergency_2_name, emergency_2_phone, dietary_requirements, custom_fields_json, pricing_tier, subtotal, total, payment_method, payment_status, ip_address, user_agent, created_at) VALUES (:agm_event_id, :registration_number, :member_id, :user_id, :submitted_by_user_id, :attendee1_name, :attendee1_member_number, :attendee1_is_over_65, :attendee2_name, :attendee2_member_number, :attendee2_is_over_65, :children_text, :contact_phone_1, :contact_phone_2, :address, :postcode, :email, :chapter, :emergency_1_name, :emergency_1_phone, :emergency_2_name, :emergency_2_phone, :dietary_requirements, :custom_fields_json, :pricing_tier, :subtotal, :total, :payment_method, :payment_status, :ip_address, :user_agent, NOW())');

            $stmt->execute([
                'agm_event_id' => (int) $event['id'],
                'registration_number' => $registrationNumber,
                'member_id' => !empty($payload['member_id']) ? (int) $payload['member_id'] : null,
                'user_id' => !empty($payload['user_id']) ? (int) $payload['user_id'] : null,
                'submitted_by_user_id' => !empty($context['submitted_by_user_id']) ? (int) $context['submitted_by_user_id'] : null,
                'attendee1_name' => trim((string) ($payload['attendee1_name'] ?? '')),
                'attendee1_member_number' => $payload['attendee1_member_number'] ?? null,
                'attendee1_is_over_65' => !empty($payload['attendee1_is_over_65']) ? 1 : 0,
                'attendee2_name' => $payload['attendee2_name'] ?? null,
                'attendee2_member_number' => $payload['attendee2_member_number'] ?? null,
                'attendee2_is_over_65' => !empty($payload['attendee2_is_over_65']) ? 1 : 0,
                'children_text' => $payload['children_text'] ?? null,
                'contact_phone_1' => $payload['contact_phone_1'] ?? null,
                'contact_phone_2' => $payload['contact_phone_2'] ?? null,
                'address' => $payload['address'] ?? null,
                'postcode' => $payload['postcode'] ?? null,
                'email' => trim((string) ($payload['email'] ?? '')),
                'chapter' => $payload['chapter'] ?? null,
                'emergency_1_name' => $payload['emergency_1_name'] ?? null,
                'emergency_1_phone' => $payload['emergency_1_phone'] ?? null,
                'emergency_2_name' => $payload['emergency_2_name'] ?? null,
                'emergency_2_phone' => $payload['emergency_2_phone'] ?? null,
                'dietary_requirements' => $payload['dietary_requirements'] ?? null,
                'custom_fields_json' => !empty($payload['custom_fields']) ? json_encode($payload['custom_fields']) : null,
                'pricing_tier' => $pricingTier,
                'subtotal' => $subtotal,
                'total' => $total,
                'payment_method' => $payload['payment_method'] ?? 'stripe',
                'payment_status' => ($payload['payment_method'] ?? 'stripe') === 'bank_transfer' ? 'awaiting_bank_transfer' : 'pending',
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
            ]);
            $registrationId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO agm_registration_items (agm_registration_id, agm_product_id, category, name_snapshot, choice_label_snapshot, unit_price, pricing_tier_snapshot, quantity, line_total, created_at) VALUES (:agm_registration_id, :agm_product_id, :category, :name_snapshot, :choice_label_snapshot, :unit_price, :pricing_tier_snapshot, :quantity, :line_total, NOW())');
            foreach ($items as $item) {
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $itemStmt->execute([
                    'agm_registration_id' => $registrationId,
                    'agm_product_id' => !empty($item['agm_product_id']) ? (int) $item['agm_product_id'] : null,
                    'category' => $item['category'] ?? 'custom',
                    'name_snapshot' => (string) ($item['name'] ?? ''),
                    'choice_label_snapshot' => $item['choice_label'] ?? null,
                    'unit_price' => $unitPrice,
                    'pricing_tier_snapshot' => $pricingTier,
                    'quantity' => $qty,
                    'line_total' => $unitPrice * $qty,
                ]);
            }

            $bikeStmt = $pdo->prepare('INSERT INTO agm_registration_motorcycles (agm_registration_id, position, owner_name, make, model, year_built, registration_plate, is_trike, is_sidecar, has_trailer, created_at) VALUES (:agm_registration_id, :position, :owner_name, :make, :model, :year_built, :registration_plate, :is_trike, :is_sidecar, :has_trailer, NOW())');
            foreach ($motorcycles as $i => $bike) {
                if (empty($bike['make']) && empty($bike['model']) && empty($bike['registration_plate'])) {
                    continue;
                }
                $bikeStmt->execute([
                    'agm_registration_id' => $registrationId,
                    'position' => $i + 1,
                    'owner_name' => $bike['owner_name'] ?? null,
                    'make' => $bike['make'] ?? null,
                    'model' => $bike['model'] ?? null,
                    'year_built' => isset($bike['year_built']) && $bike['year_built'] !== '' ? (int) $bike['year_built'] : null,
                    'registration_plate' => $bike['registration_plate'] ?? null,
                    'is_trike' => !empty($bike['is_trike']) ? 1 : 0,
                    'is_sidecar' => !empty($bike['is_sidecar']) ? 1 : 0,
                    'has_trailer' => !empty($bike['has_trailer']) ? 1 : 0,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        ActivityLogger::log('public', null, !empty($payload['user_id']) ? (int) $payload['user_id'] : null, 'agm.registration_submitted', [
            'registration_id' => $registrationId,
            'event_id' => (int) $event['id'],
            'pricing_tier' => $pricingTier,
            'total' => $total,
        ]);

        return self::getRegistrationById($registrationId);
    }

    public static function getRegistrationById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM agm_registrations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['items'] = self::getItems((int) $row['id']);
        $row['motorcycles'] = self::getMotorcycles((int) $row['id']);
        return $row;
    }

    public static function getRegistrationByStripeSession(string $sessionId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM agm_registrations WHERE stripe_session_id = :sid LIMIT 1');
        $stmt->execute(['sid' => $sessionId]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? self::getRegistrationById($id) : null;
    }

    public static function getRegistrationByPaymentIntent(string $paymentIntentId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM agm_registrations WHERE stripe_payment_intent_id = :pid LIMIT 1');
        $stmt->execute(['pid' => $paymentIntentId]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? self::getRegistrationById($id) : null;
    }

    public static function getItems(int $registrationId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM agm_registration_items WHERE agm_registration_id = :rid ORDER BY id');
        $stmt->execute(['rid' => $registrationId]);
        return $stmt->fetchAll();
    }

    public static function getMotorcycles(int $registrationId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM agm_registration_motorcycles WHERE agm_registration_id = :rid ORDER BY position');
        $stmt->execute(['rid' => $registrationId]);
        return $stmt->fetchAll();
    }

    public static function listForEvent(int $eventId, array $filters = []): array
    {
        $pdo = Database::connection();
        $where = ['agm_event_id = :event_id'];
        $params = ['event_id' => $eventId];
        if (!empty($filters['payment_status'])) {
            $where[] = 'payment_status = :payment_status';
            $params['payment_status'] = $filters['payment_status'];
        }
        if (!empty($filters['pricing_tier'])) {
            $where[] = 'pricing_tier = :pricing_tier';
            $params['pricing_tier'] = $filters['pricing_tier'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(attendee1_name LIKE :search OR attendee2_name LIKE :search OR email LIKE :search OR registration_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $sql = 'SELECT * FROM agm_registrations WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function listForMember(int $memberId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.*, e.title AS event_title, e.year AS event_year, e.slug AS event_slug FROM agm_registrations r INNER JOIN agm_events e ON e.id = r.agm_event_id WHERE r.member_id = :member_id ORDER BY r.created_at DESC');
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->fetchAll();
    }

    public static function attachStripeSession(int $registrationId, string $sessionId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE agm_registrations SET stripe_session_id = :sid, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sid' => $sessionId, 'id' => $registrationId]);
    }

    public static function markPaid(int $registrationId, ?string $paymentIntentId, ?string $sessionId, ?int $actorUserId = null): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM agm_registrations WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $registrationId]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->rollBack();
                return;
            }
            if (($row['payment_status'] ?? '') === 'paid') {
                $pdo->commit();
                return;
            }
            $stmt = $pdo->prepare('UPDATE agm_registrations SET payment_status = "paid", stripe_payment_intent_id = COALESCE(NULLIF(stripe_payment_intent_id, ""), :pid), stripe_session_id = COALESCE(NULLIF(stripe_session_id, ""), :sid), paid_at = NOW(), updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'pid' => $paymentIntentId,
                'sid' => $sessionId,
                'id' => $registrationId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ActivityLogger::log($actorUserId ? 'admin' : 'system', null, $actorUserId, 'agm.registration_paid', [
            'registration_id' => $registrationId,
            'payment_intent' => $paymentIntentId,
        ]);
        self::dispatchConfirmation($registrationId);
    }

    public static function markRefunded(int $registrationId, ?string $stripeRefundId, ?int $actorUserId = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE agm_registrations SET payment_status = "refunded", refunded_at = NOW(), updated_at = NOW() WHERE id = :id AND payment_status <> "refunded"');
        $stmt->execute(['id' => $registrationId]);
        ActivityLogger::log($actorUserId ? 'admin' : 'system', null, $actorUserId, 'agm.registration_refunded', [
            'registration_id' => $registrationId,
            'stripe_refund_id' => $stripeRefundId,
        ]);
    }

    public static function markCancelled(int $registrationId, int $actorUserId, ?string $reason = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE agm_registrations SET payment_status = "cancelled", cancelled_at = NOW(), updated_at = NOW(), admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ""), "\nCancelled: ", :reason)) WHERE id = :id');
        $stmt->execute(['reason' => $reason ?? '', 'id' => $registrationId]);
        ActivityLogger::log('admin', null, $actorUserId, 'agm.registration_cancelled', [
            'registration_id' => $registrationId,
            'reason' => $reason,
        ]);
    }

    private static function dispatchConfirmation(int $registrationId): void
    {
        $registration = self::getRegistrationById($registrationId);
        if (!$registration) {
            return;
        }
        $event = AgmEventService::getEventById((int) $registration['agm_event_id']);
        if (!$event) {
            return;
        }
        $itemsHtml = '<table style="border-collapse:collapse;width:100%"><thead><tr><th align="left">Item</th><th align="right">Qty</th><th align="right">Price</th></tr></thead><tbody>';
        foreach (($registration['items'] ?? []) as $item) {
            $name = htmlspecialchars((string) $item['name_snapshot'], ENT_QUOTES, 'UTF-8');
            if (!empty($item['choice_label_snapshot'])) {
                $name .= ' &mdash; ' . htmlspecialchars((string) $item['choice_label_snapshot'], ENT_QUOTES, 'UTF-8');
            }
            $itemsHtml .= '<tr><td>' . $name . '</td><td align="right">' . (int) $item['quantity'] . '</td><td align="right">A$' . number_format((float) $item['line_total'], 2) . '</td></tr>';
        }
        $itemsHtml .= '</tbody></table>';

        NotificationService::dispatch('agm_registration_confirmation', [
            'primary_email' => $registration['email'] ?? '',
            'admin_emails' => NotificationService::getAdminEmails(),
            'registration_number' => $registration['registration_number'] ?? '',
            'event_title' => $event['title'] ?? '',
            'event_dates' => self::formatDateRange($event),
            'venue_name' => $event['venue_name'] ?? '',
            'attendee_name' => $registration['attendee1_name'] ?? '',
            'items_html' => $itemsHtml,
            'total' => 'A$' . number_format((float) $registration['total'], 2),
            'contact_name' => $event['contact_name'] ?? '',
            'contact_phone' => $event['contact_phone'] ?? '',
            'contact_email' => $event['contact_email'] ?? '',
        ]);
    }

    private static function formatDateRange(array $event): string
    {
        $start = !empty($event['start_date']) ? date('j M Y', strtotime((string) $event['start_date'])) : '';
        $end = !empty($event['end_date']) ? date('j M Y', strtotime((string) $event['end_date'])) : '';
        if ($start === '' && $end === '') {
            return '';
        }
        if ($start === $end || $end === '') {
            return $start;
        }
        return $start . ' to ' . $end;
    }
}
