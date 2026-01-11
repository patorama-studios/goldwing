<?php
namespace App\Services;

use DateTimeImmutable;
use PDO;

class MembershipOrderService
{
    public static function createMembershipOrder(int $memberId, int $membershipPeriodId, float $amount, array $options = []): ?array
    {
        $pdo = Database::connection();
        $channelCode = (string) ($options['channel_code'] ?? 'primary');
        $channel = PaymentSettingsService::getChannelByCode($channelCode);
        $channelId = (int) ($options['channel_id'] ?? ($channel['id'] ?? 0));
        if ($channelId <= 0) {
            return null;
        }

        $currency = (string) ($options['currency'] ?? 'AUD');
        $paymentMethod = self::normalizePaymentMethod((string) ($options['payment_method'] ?? ''));
        $paymentStatus = self::normalizePaymentStatus((string) ($options['payment_status'] ?? 'pending'));
        $fulfillmentStatus = self::normalizeFulfillmentStatus((string) ($options['fulfillment_status'] ?? 'pending'));
        $status = self::mapOrderStatus($paymentStatus);

        $orderNumber = (string) ($options['order_number'] ?? '');
        if ($orderNumber === '') {
            $orderNumber = self::nextOrderNumber($options['actor_user_id'] ?? null);
        }
        if ($orderNumber === '') {
            return null;
        }

        $userId = isset($options['user_id']) ? (int) $options['user_id'] : self::lookupMemberUserId($pdo, $memberId);
        if ($userId <= 0) {
            $userId = null;
        }

        $adminNotes = self::sanitizeNotes($options['admin_notes'] ?? null);
        $internalNotes = self::sanitizeNotes($options['internal_notes'] ?? null);

        $stmt = $pdo->prepare('INSERT INTO orders (order_number, user_id, member_id, status, payment_status, fulfillment_status, order_type, membership_period_id, payment_method, currency, subtotal, tax_total, shipping_total, total, channel_id, shipping_required, shipping_address_json, admin_notes, internal_notes, created_at) VALUES (:order_number, :user_id, :member_id, :status, :payment_status, :fulfillment_status, "membership", :membership_period_id, :payment_method, :currency, :subtotal, 0, 0, :total, :channel_id, 0, NULL, :admin_notes, :internal_notes, NOW())');
        $stmt->execute([
            'order_number' => $orderNumber,
            'user_id' => $userId,
            'member_id' => $memberId,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'membership_period_id' => $membershipPeriodId > 0 ? $membershipPeriodId : null,
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
            'currency' => $currency,
            'subtotal' => $amount,
            'total' => $amount,
            'channel_id' => $channelId,
            'admin_notes' => $adminNotes,
            'internal_notes' => $internalNotes,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        if ($orderId <= 0) {
            return null;
        }

        $items = $options['items'] ?? [];
        if (!$items) {
            $items = [
                [
                    'product_id' => $options['product_id'] ?? null,
                    'name' => self::defaultItemName($options),
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'is_physical' => 0,
                ],
            ];
        }
        OrderService::insertItems($orderId, $items);

        return [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'member_id' => $memberId,
            'membership_period_id' => $membershipPeriodId,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'total' => $amount,
            'currency' => $currency,
        ];
    }

    public static function activateMembershipForOrder(array $order, array $metadata = []): bool
    {
        $memberId = (int) ($order['member_id'] ?? 0);
        $periodId = (int) ($order['membership_period_id'] ?? 0);
        if ($periodId <= 0) {
            $periodId = isset($metadata['period_id']) ? (int) $metadata['period_id'] : 0;
        }
        if ($memberId <= 0 || $periodId <= 0) {
            return false;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE id = :id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['id' => $periodId, 'member_id' => $memberId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            return false;
        }

        $term = strtoupper((string) ($period['term'] ?? '1Y'));
        $today = new DateTimeImmutable('today');
        $startDate = $today->format('Y-m-d');
        $endDate = null;

        if ($term !== 'LIFE') {
            $stmt = $pdo->prepare('SELECT end_date FROM membership_periods WHERE member_id = :member_id AND status = "ACTIVE" AND end_date IS NOT NULL ORDER BY end_date DESC LIMIT 1');
            $stmt->execute(['member_id' => $memberId]);
            $activeEnd = $stmt->fetchColumn();
            if ($activeEnd) {
                $activeEndDate = new DateTimeImmutable((string) $activeEnd);
                if ($activeEndDate >= $today) {
                    $startDate = $activeEndDate->modify('+1 day')->format('Y-m-d');
                }
            }
            $termYears = 1;
            if ($term === '3Y') {
                $termYears = 3;
            } elseif ($term === '2Y') {
                $termYears = 2;
            }
            $endDate = MembershipService::calculateExpiry($startDate, $termYears);
        }

        $paymentReference = (string) ($metadata['payment_reference'] ?? '');
        if ($paymentReference === '') {
            $paymentReference = (string) ($order['stripe_payment_intent_id'] ?? '');
        }
        if ($paymentReference === '') {
            $paymentReference = (string) ($order['order_number'] ?? '');
        }
        $paymentReference = $paymentReference !== '' ? $paymentReference : null;

        $stmt = $pdo->prepare('UPDATE membership_periods SET status = "ACTIVE", start_date = :start_date, end_date = :end_date, payment_id = :payment_id, paid_at = NOW() WHERE id = :id');
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'payment_id' => $paymentReference,
            'id' => $periodId,
        ]);

        $stmt = $pdo->prepare('UPDATE members SET status = "ACTIVE", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $memberId]);

        $stmt = $pdo->prepare('UPDATE orders SET status = "paid", payment_status = "accepted", fulfillment_status = "active", paid_at = COALESCE(paid_at, NOW()), updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => (int) ($order['id'] ?? 0)]);

        ActivityLogger::log('system', null, $memberId, 'membership.activated', [
            'order_id' => $order['id'] ?? null,
            'order_number' => $order['order_number'] ?? null,
            'period_id' => $periodId,
        ]);

        return true;
    }

    public static function markOrderRejected(int $orderId, ?string $reason = null): void
    {
        $pdo = Database::connection();
        $internal = self::sanitizeNotes($reason);
        $stmt = $pdo->prepare('UPDATE orders SET status = "cancelled", payment_status = "rejected", updated_at = NOW(), internal_notes = CASE WHEN :note IS NULL OR :note = "" THEN internal_notes WHEN internal_notes IS NULL OR internal_notes = "" THEN :note ELSE CONCAT(internal_notes, "\n", :note) END WHERE id = :id');
        $stmt->execute([
            'note' => $internal,
            'id' => $orderId,
        ]);
    }

    public static function markOrderFailed(int $orderId, ?string $reason = null): void
    {
        $pdo = Database::connection();
        $internal = self::sanitizeNotes($reason);
        $stmt = $pdo->prepare('UPDATE orders SET status = "cancelled", payment_status = "failed", updated_at = NOW(), internal_notes = CASE WHEN :note IS NULL OR :note = "" THEN internal_notes WHEN internal_notes IS NULL OR internal_notes = "" THEN :note ELSE CONCAT(internal_notes, "\n", :note) END WHERE id = :id');
        $stmt->execute([
            'note' => $internal,
            'id' => $orderId,
        ]);
    }

    public static function markOrderRefunded(array $order, ?string $reason = null): void
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        $pdo = Database::connection();
        $internal = self::sanitizeNotes($reason);
        $stmt = $pdo->prepare('UPDATE orders SET status = "refunded", payment_status = "refunded", fulfillment_status = "expired", refunded_at = NOW(), updated_at = NOW(), internal_notes = CASE WHEN :note IS NULL OR :note = "" THEN internal_notes WHEN internal_notes IS NULL OR internal_notes = "" THEN :note ELSE CONCAT(internal_notes, "\n", :note) END WHERE id = :id');
        $stmt->execute([
            'note' => $internal,
            'id' => $orderId,
        ]);

        $periodId = (int) ($order['membership_period_id'] ?? 0);
        $memberId = (int) ($order['member_id'] ?? 0);
        if ($periodId > 0 && $memberId > 0) {
            $stmt = $pdo->prepare('UPDATE membership_periods SET status = "LAPSED" WHERE id = :id');
            $stmt->execute(['id' => $periodId]);
        }
    }

    public static function nextOrderNumber(?int $actorUserId = null): string
    {
        $pdo = Database::connection();
        $year = (int) (new DateTimeImmutable('now'))->format('Y');
        $prefix = (string) SettingsService::getGlobal('membership.order_prefix', 'M');
        $prefix = $prefix !== '' ? strtoupper($prefix) : 'M';

        $pdo->beginTransaction();
        try {
            $counterRow = self::getSettingForUpdate($pdo, 'membership', 'order_counter');
            $yearRow = self::getSettingForUpdate($pdo, 'membership', 'order_counter_year');

            $counter = (int) self::decodeSettingValue($counterRow['value_json'] ?? null);
            $counterYear = (int) self::decodeSettingValue($yearRow['value_json'] ?? null);

            if ($counterYear !== $year) {
                $counterYear = $year;
                $counter = 0;
            }
            $counter++;

            self::writeSetting($pdo, $counterRow, 'membership', 'order_counter', $counter, $actorUserId);
            self::writeSetting($pdo, $yearRow, 'membership', 'order_counter_year', $counterYear, $actorUserId);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return '';
        }

        return sprintf('%s-%04d-%06d', $prefix, $year, $counter);
    }

    private static function lookupMemberUserId(PDO $pdo, int $memberId): int
    {
        $stmt = $pdo->prepare('SELECT user_id FROM members WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $memberId]);
        return (int) $stmt->fetchColumn();
    }

    private static function defaultItemName(array $options): string
    {
        $name = trim((string) ($options['item_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $term = strtoupper(trim((string) ($options['term'] ?? '')));
        if ($term !== '') {
            return 'Membership ' . $term;
        }
        return 'Membership';
    }

    private static function normalizePaymentMethod(string $method): string
    {
        $method = strtolower(trim($method));
        if ($method === '') {
            return '';
        }
        $map = [
            'card' => 'stripe',
            'stripe' => 'stripe',
            'bank transfer' => 'bank_transfer',
            'bank_transfer' => 'bank_transfer',
            'manual' => 'manual',
            'cash' => 'cash',
            'complimentary' => 'complimentary',
            'life member' => 'complimentary',
        ];
        return $map[$method] ?? $method;
    }

    private static function normalizePaymentStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['pending', 'accepted', 'rejected', 'failed', 'refunded'];
        return in_array($status, $allowed, true) ? $status : 'pending';
    }

    private static function normalizeFulfillmentStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['pending', 'active', 'expired'];
        return in_array($status, $allowed, true) ? $status : 'pending';
    }

    private static function mapOrderStatus(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'accepted' => 'paid',
            'refunded' => 'refunded',
            'rejected', 'failed' => 'cancelled',
            default => 'pending',
        };
    }

    private static function sanitizeNotes($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $note = trim((string) $value);
        return $note === '' ? null : $note;
    }

    private static function getSettingForUpdate(PDO $pdo, string $category, string $key): ?array
    {
        $stmt = $pdo->prepare('SELECT id, value_json FROM settings_global WHERE category = :category AND key_name = :key LIMIT 1 FOR UPDATE');
        $stmt->execute(['category' => $category, 'key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        return null;
    }

    private static function writeSetting(PDO $pdo, ?array $row, string $category, string $key, $value, ?int $actorUserId): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($row && isset($row['id'])) {
            $stmt = $pdo->prepare('UPDATE settings_global SET value_json = :value_json, updated_by_user_id = :user_id, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'value_json' => $encoded,
                'user_id' => $actorUserId,
                'id' => (int) $row['id'],
            ]);
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO settings_global (category, key_name, value_json, updated_by_user_id, updated_at) VALUES (:category, :key_name, :value_json, :user_id, NOW()) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()');
        $stmt->execute([
            'category' => $category,
            'key_name' => $key,
            'value_json' => $encoded,
            'user_id' => $actorUserId,
        ]);
    }

    private static function decodeSettingValue(?string $valueJson)
    {
        if ($valueJson === null || $valueJson === '') {
            return null;
        }
        $decoded = json_decode($valueJson, true);
        return $decoded;
    }
}
