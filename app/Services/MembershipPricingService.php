<?php
namespace App\Services;

use App\Services\Database;
use App\Services\SettingsService;

class MembershipPricingService
{
    public const MAGAZINE_TYPES = ['PRINTED', 'PDF'];
    public const MEMBERSHIP_TYPES = ['FULL', 'ASSOCIATE'];

    public static function periodDefinitions(): array
    {
        return [
            'THREE_YEARS' => [
                'label' => '3 Years (August to July)',
                'join_after' => null,
            ],
            'TWO_TWO_THIRDS' => [
                'label' => '2.2/3 Years (Join after 1st December)',
                'join_after' => 'december',
            ],
            'TWO_ONE_THIRDS' => [
                'label' => '2.1/3 Years (Join after 1st April)',
                'join_after' => 'april',
            ],
            'ONE_YEAR' => [
                'label' => '1 Year (August to July)',
                'join_after' => null,
            ],
            'TWO_THIRDS' => [
                'label' => '2/3 of a Year (Join after 1st December)',
                'join_after' => 'december',
            ],
            'ONE_THIRD' => [
                'label' => '1/3 of a Year (Join after 1st April)',
                'join_after' => 'april',
            ],
        ];
    }

    public static function pricingNote(): string
    {
        return 'All memberships expire 31st of July and membership renewal notifications are sent out to all current members.';
    }

    public static function defaultPricingRows(): array
    {
        return [
            ['magazine_type' => 'PRINTED', 'membership_type' => 'FULL', 'period_key' => 'THREE_YEARS', 'amount_cents' => 22500, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'FULL', 'period_key' => 'TWO_TWO_THIRDS', 'amount_cents' => 20400, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'FULL', 'period_key' => 'TWO_ONE_THIRDS', 'amount_cents' => 18300, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'FULL', 'period_key' => 'ONE_YEAR', 'amount_cents' => 9000, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'FULL', 'period_key' => 'TWO_THIRDS', 'amount_cents' => 6500, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'FULL', 'period_key' => 'ONE_THIRD', 'amount_cents' => 4000, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'ASSOCIATE', 'period_key' => 'THREE_YEARS', 'amount_cents' => 6000, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'ASSOCIATE', 'period_key' => 'TWO_TWO_THIRDS', 'amount_cents' => 5500, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'ASSOCIATE', 'period_key' => 'TWO_ONE_THIRDS', 'amount_cents' => 5000, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'ASSOCIATE', 'period_key' => 'ONE_YEAR', 'amount_cents' => 3000, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'ASSOCIATE', 'period_key' => 'TWO_THIRDS', 'amount_cents' => 2500, 'currency' => 'AUD'],
            ['magazine_type' => 'PRINTED', 'membership_type' => 'ASSOCIATE', 'period_key' => 'ONE_THIRD', 'amount_cents' => 2000, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'FULL', 'period_key' => 'THREE_YEARS', 'amount_cents' => 16500, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'FULL', 'period_key' => 'TWO_TWO_THIRDS', 'amount_cents' => 15000, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'FULL', 'period_key' => 'TWO_ONE_THIRDS', 'amount_cents' => 13500, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'FULL', 'period_key' => 'ONE_YEAR', 'amount_cents' => 7000, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'FULL', 'period_key' => 'TWO_THIRDS', 'amount_cents' => 5200, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'FULL', 'period_key' => 'ONE_THIRD', 'amount_cents' => 3400, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'ASSOCIATE', 'period_key' => 'THREE_YEARS', 'amount_cents' => 6000, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'ASSOCIATE', 'period_key' => 'TWO_TWO_THIRDS', 'amount_cents' => 5500, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'ASSOCIATE', 'period_key' => 'TWO_ONE_THIRDS', 'amount_cents' => 5000, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'ASSOCIATE', 'period_key' => 'ONE_YEAR', 'amount_cents' => 3000, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'ASSOCIATE', 'period_key' => 'TWO_THIRDS', 'amount_cents' => 2500, 'currency' => 'AUD'],
            ['magazine_type' => 'PDF', 'membership_type' => 'ASSOCIATE', 'period_key' => 'ONE_THIRD', 'amount_cents' => 2000, 'currency' => 'AUD'],
        ];
    }

    public static function getMembershipPricing(): array
    {
        $stored = SettingsService::getGlobal('membership.pricing_matrix', null);
        $rows = [];
        $currency = 'AUD';

        if (is_array($stored)) {
            if (isset($stored['rows']) && is_array($stored['rows'])) {
                $rows = $stored['rows'];
                $currency = (string) ($stored['currency'] ?? 'AUD');
            } elseif (isset($stored[0])) {
                $rows = $stored;
            }
        }

        if (!$rows) {
            $rows = self::defaultPricingRows();
        }

        $normalized = self::normalizeRows($rows, $currency);
        $matrix = self::rowsToMatrix($normalized['rows']);
        return [
            'currency' => $normalized['currency'],
            'rows' => $normalized['rows'],
            'matrix' => $matrix,
        ];
    }

    public static function updateMembershipPricing(int $actorUserId, array $payload): void
    {
        $rows = [];
        $currency = 'AUD';

        if (isset($payload['rows']) && is_array($payload['rows'])) {
            $rows = $payload['rows'];
            $currency = (string) ($payload['currency'] ?? 'AUD');
        } elseif (isset($payload['matrix']) && is_array($payload['matrix'])) {
            $rows = self::matrixToRows($payload['matrix'], $payload['currency'] ?? 'AUD');
            $currency = (string) ($payload['currency'] ?? 'AUD');
        }

        $normalized = self::normalizeRows($rows, $currency);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            SettingsService::setGlobal($actorUserId, 'membership.pricing_matrix', [
                'currency' => $normalized['currency'],
                'rows' => $normalized['rows'],
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function getPriceCents(string $magazineType, string $membershipType, string $periodKey): ?int
    {
        $pricing = self::getMembershipPricing();
        $matrix = $pricing['matrix'] ?? [];
        return $matrix[$magazineType][$membershipType][$periodKey] ?? null;
    }

    private static function rowsToMatrix(array $rows): array
    {
        $matrix = [];
        foreach ($rows as $row) {
            $magazine = (string) ($row['magazine_type'] ?? '');
            $membership = (string) ($row['membership_type'] ?? '');
            $period = (string) ($row['period_key'] ?? '');
            $amount = isset($row['amount_cents']) ? (int) $row['amount_cents'] : null;
            if ($magazine === '' || $membership === '' || $period === '' || $amount === null) {
                continue;
            }
            if (!isset($matrix[$magazine])) {
                $matrix[$magazine] = [];
            }
            if (!isset($matrix[$magazine][$membership])) {
                $matrix[$magazine][$membership] = [];
            }
            $matrix[$magazine][$membership][$period] = $amount;
        }
        return $matrix;
    }

    private static function matrixToRows(array $matrix, string $currency): array
    {
        $rows = [];
        foreach ($matrix as $magazineType => $memberships) {
            foreach ($memberships as $membershipType => $periods) {
                foreach ($periods as $periodKey => $amount) {
                    $rows[] = [
                        'magazine_type' => (string) $magazineType,
                        'membership_type' => (string) $membershipType,
                        'period_key' => (string) $periodKey,
                        'amount_cents' => (int) $amount,
                        'currency' => $currency,
                    ];
                }
            }
        }
        return $rows;
    }

    private static function normalizeRows(array $rows, string $currency): array
    {
        $defaults = self::defaultPricingRows();
        $matrix = self::rowsToMatrix($defaults);

        foreach ($rows as $row) {
            $magazine = strtoupper((string) ($row['magazine_type'] ?? ''));
            $membership = strtoupper((string) ($row['membership_type'] ?? ''));
            $period = strtoupper((string) ($row['period_key'] ?? ''));
            if ($magazine === '' || $membership === '' || $period === '') {
                continue;
            }
            $amount = isset($row['amount_cents']) ? (int) $row['amount_cents'] : null;
            if ($amount === null) {
                continue;
            }
            if (!isset($matrix[$magazine])) {
                $matrix[$magazine] = [];
            }
            if (!isset($matrix[$magazine][$membership])) {
                $matrix[$magazine][$membership] = [];
            }
            $matrix[$magazine][$membership][$period] = max(0, $amount);
        }

        return [
            'currency' => $currency !== '' ? $currency : 'AUD',
            'rows' => self::matrixToRows($matrix, $currency !== '' ? $currency : 'AUD'),
        ];
    }
}
