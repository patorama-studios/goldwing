<?php
namespace App\Services;

use App\Services\Database;
use App\Services\SettingsService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Membership pricing — three concepts, one service.
 *
 * 1. **Renewal periods**: an admin-defined list (label + duration_months) used
 *    by the renewal flow. Each (magazine × type × period) cell has its own
 *    price. Renewals run from the configured anchor date (default 1 Aug) for
 *    duration_months whole months. No pro-rata.
 * 2. **Pro-rata**: a continuous engine for *new* joins. Admin sets one annual
 *    price per (magazine × type) cell; the system charges
 *    months_remaining_to_expiry ÷ 12 × annual_price, rounded per config.
 * 3. **Legacy matrix view**: every existing caller of getPriceCents() and
 *    getMembershipPricing() keeps working — legacy keys (`ONE_YEAR`,
 *    `THREE_YEARS`, `ONE_THIRD`, `TWO_THIRDS`, ...) are resolved against the
 *    new config so nothing breaks during the transition.
 */
class MembershipPricingService
{
    public const MAGAZINE_TYPES = ['PRINTED', 'PDF'];
    public const MEMBERSHIP_TYPES = ['FULL', 'ASSOCIATE'];

    public const CONFIG_KEY = 'membership.pricing.config';
    public const LEGACY_MATRIX_KEY = 'membership.pricing_matrix';

    public const DEFAULT_ANCHOR_MONTH = 8;   // August
    public const DEFAULT_ANCHOR_DAY = 1;
    public const DEFAULT_EXPIRY_MONTH = 7;   // July
    public const DEFAULT_EXPIRY_DAY = 31;

    private const LEGACY_PERIOD_MONTHS = [
        'ONE_THIRD' => 4,
        'TWO_THIRDS' => 8,
        'ONE_YEAR' => 12,
        'TWO_ONE_THIRDS' => 28,
        'TWO_TWO_THIRDS' => 32,
        'THREE_YEARS' => 36,
    ];

    private const LEGACY_PERIOD_LABELS = [
        'ONE_THIRD' => '1/3 of a year',
        'TWO_THIRDS' => '2/3 of a year',
        'ONE_YEAR' => '1 year',
        'TWO_ONE_THIRDS' => '2.1/3 years',
        'TWO_TWO_THIRDS' => '2.2/3 years',
        'THREE_YEARS' => '3 years',
    ];

    public static function defaultConfig(): array
    {
        return [
            'anchor_month' => self::DEFAULT_ANCHOR_MONTH,
            'anchor_day' => self::DEFAULT_ANCHOR_DAY,
            'expiry_month' => self::DEFAULT_EXPIRY_MONTH,
            'expiry_day' => self::DEFAULT_EXPIRY_DAY,
            'currency' => 'AUD',
            'prorata_enabled' => true,
            'prorata_minimum_months' => 1,
            'prorata_rounding' => 'nearest_dollar', // or 'nearest_cent'
            'renewal_periods' => [
                ['id' => 'P_1Y', 'label' => '1 Year', 'duration_months' => 12, 'sort_order' => 10, 'active' => true],
                ['id' => 'P_3Y', 'label' => '3 Years', 'duration_months' => 36, 'sort_order' => 30, 'active' => true],
            ],
            'renewal_prices' => [
                'PRINTED' => [
                    'FULL' => ['P_1Y' => 7500, 'P_3Y' => 21000],
                    'ASSOCIATE' => ['P_1Y' => 1500, 'P_3Y' => 3000],
                ],
                'PDF' => [
                    'FULL' => ['P_1Y' => 5500, 'P_3Y' => 15000],
                    'ASSOCIATE' => ['P_1Y' => 1500, 'P_3Y' => 3000],
                ],
            ],
            'prorata_annual_prices' => [
                'PRINTED' => ['FULL' => 7500, 'ASSOCIATE' => 1500],
                'PDF' => ['FULL' => 5500, 'ASSOCIATE' => 1500],
            ],
        ];
    }

    public static function pricingNote(): string
    {
        $config = self::getConfig();
        $monthName = self::monthName($config['expiry_month']);
        return sprintf(
            'All memberships expire %d %s. Renewals run for whole years from %d %s. New members joining mid-year pay a pro-rata price for the months remaining until expiry.',
            (int) $config['expiry_day'],
            $monthName,
            (int) $config['anchor_day'],
            self::monthName($config['anchor_month'])
        );
    }

    public static function getConfig(): array
    {
        $stored = SettingsService::getGlobal(self::CONFIG_KEY, null);
        if (is_array($stored) && isset($stored['renewal_periods'])) {
            return self::normalizeConfig($stored);
        }
        // Migrate from the legacy pricing_matrix on first read.
        $legacy = SettingsService::getGlobal(self::LEGACY_MATRIX_KEY, null);
        if (is_array($legacy)) {
            return self::normalizeConfig(self::migrateFromLegacyMatrix($legacy));
        }
        return self::normalizeConfig(self::defaultConfig());
    }

    public static function updateConfig(int $actorUserId, array $payload): void
    {
        $normalized = self::normalizeConfig($payload);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            SettingsService::setGlobal($actorUserId, self::CONFIG_KEY, $normalized);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Renewal pricing                                                     */
    /* ------------------------------------------------------------------ */

    /** @return array<int,array{id:string,label:string,duration_months:int,sort_order:int,active:bool}> */
    public static function getRenewalPeriods(bool $activeOnly = true): array
    {
        $periods = self::getConfig()['renewal_periods'];
        if ($activeOnly) {
            $periods = array_values(array_filter($periods, static fn($p) => !empty($p['active'])));
        }
        return $periods;
    }

    public static function getRenewalPriceCents(string $magazineType, string $membershipType, string $periodId): ?int
    {
        $magazineType = strtoupper($magazineType);
        $membershipType = strtoupper($membershipType);
        $prices = self::getConfig()['renewal_prices'];
        $cents = $prices[$magazineType][$membershipType][$periodId] ?? null;
        return is_int($cents) ? $cents : null;
    }

    /** Resolve a renewal period by ID, or find one whose duration_months matches. */
    public static function findRenewalPeriodByMonths(int $months): ?array
    {
        foreach (self::getRenewalPeriods(true) as $period) {
            if ((int) $period['duration_months'] === $months) {
                return $period;
            }
        }
        return null;
    }

    /**
     * Renewal price in cents for a magazine type / member type / term length.
     * Prefers an admin-defined renewal period matching the exact duration,
     * then falls back to the legacy fixed-term lookup (24M = 2 × annual).
     * Returns 0 when no price is configured.
     */
    public static function renewalAmountCents(string $magazineType, string $membershipType, int $months): int
    {
        $period = self::findRenewalPeriodByMonths($months);
        if ($period) {
            $cents = self::getRenewalPriceCents($magazineType, $membershipType, $period['id']);
            if ($cents !== null) {
                return (int) $cents;
            }
        }
        if ($months === 36) {
            return (int) (self::getPriceCents($magazineType, $membershipType, 'THREE_YEARS') ?? 0);
        }
        $oneYear = (int) (self::getPriceCents($magazineType, $membershipType, 'ONE_YEAR') ?? 0);
        if ($months === 24) {
            return $oneYear * 2;
        }
        return $oneYear;
    }

    /* ------------------------------------------------------------------ */
    /* Pro-rata for new joins                                              */
    /* ------------------------------------------------------------------ */

    public static function getProRataAnnualCents(string $magazineType, string $membershipType): ?int
    {
        $magazineType = strtoupper($magazineType);
        $membershipType = strtoupper($membershipType);
        $prices = self::getConfig()['prorata_annual_prices'];
        $cents = $prices[$magazineType][$membershipType] ?? null;
        return is_int($cents) ? $cents : null;
    }

    /**
     * Months remaining from $from (inclusive) until the next expiry date,
     * clamped to [minimum, 12].
     */
    public static function monthsRemainingUntilExpiry(?DateTimeImmutable $from = null): int
    {
        $config = self::getConfig();
        $tz = new DateTimeZone('Australia/Sydney');
        $from = $from ?: new DateTimeImmutable('today', $tz);
        $from = $from->setTime(0, 0, 0);

        $expiryThisYear = self::makeDate((int) $from->format('Y'), $config['expiry_month'], $config['expiry_day'], $tz);
        $expiry = $from <= $expiryThisYear
            ? $expiryThisYear
            : self::makeDate((int) $from->format('Y') + 1, $config['expiry_month'], $config['expiry_day'], $tz);

        $months = (int) $from->diff($expiry)->m
            + ((int) $from->diff($expiry)->y) * 12;
        // diff().m gives only the months part — but the difference may also
        // include extra days that push us into another month. Round up so a
        // member joining on day 1 of a month gets the full month.
        $extraDays = (int) $from->diff($expiry)->d;
        if ($extraDays > 0) {
            $months += 1;
        }
        $minimum = max(1, (int) ($config['prorata_minimum_months'] ?? 1));
        return max($minimum, min(12, $months));
    }

    /**
     * Calculate the pro-rata price for the months remaining until expiry.
     * Returns null only when no annual base price is configured for the
     * given (magazine × type). Callers decide whether to actually charge it
     * by also checking $config['prorata_enabled'].
     */
    public static function calculateProRataCents(
        string $magazineType,
        string $membershipType,
        ?DateTimeImmutable $joinDate = null
    ): ?int {
        $annual = self::getProRataAnnualCents($magazineType, $membershipType);
        if ($annual === null || $annual === 0) {
            return null;
        }
        $config = self::getConfig();
        $months = self::monthsRemainingUntilExpiry($joinDate);
        $raw = $annual * ($months / 12);
        $rounding = (string) ($config['prorata_rounding'] ?? 'nearest_dollar');
        if ($rounding === 'nearest_dollar') {
            return (int) (round($raw / 100) * 100);
        }
        return (int) round($raw);
    }

    /**
     * Build a 12-month preview table for the admin UI / public showcase.
     * Returns one row per month starting at the anchor month, with the
     * pro-rata price someone joining on the 1st of that month would pay.
     */
    public static function buildProRataPreview(string $magazineType, string $membershipType): array
    {
        $config = self::getConfig();
        $tz = new DateTimeZone('Australia/Sydney');
        $year = (int) (new DateTimeImmutable('now', $tz))->format('Y');
        $rows = [];
        $anchorMonth = (int) $config['anchor_month'];
        for ($i = 0; $i < 12; $i++) {
            $month = (($anchorMonth - 1 + $i) % 12) + 1;
            $rowYear = $year + ($anchorMonth + $i > 12 ? 1 : 0);
            $date = self::makeDate($rowYear, $month, 1, $tz);
            $cents = self::calculateProRataCents($magazineType, $membershipType, $date);
            $rows[] = [
                'month' => $month,
                'month_name' => self::monthName($month, true),
                'months_remaining' => self::monthsRemainingUntilExpiry($date),
                'amount_cents' => $cents,
                'is_current' => $month === (int) (new DateTimeImmutable('now', $tz))->format('n'),
            ];
        }
        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* New-joiner option resolution                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Return the join options a brand-new member should see, including the
     * dynamic price for joining as of $joinDate. Each option is one of:
     *   - kind=prorata_only   : pro-rata until the next expiry (key=JOIN_ONLY)
     *   - kind=prorata_plus   : pro-rata + N more whole years of the named
     *                           renewal period (key=JOIN_PLUS_<period_id>)
     *
     * The keys are stable across calls so they can be validated server-side.
     *
     * @return array<int,array{key:string,label:string,kind:string,duration_months:int,cents:int,breakdown:array<string,int>,period_id:?string}>
     */
    public static function getJoinOptions(string $magazineType, string $membershipType, ?DateTimeImmutable $joinDate = null): array
    {
        $config = self::getConfig();
        $tz = new DateTimeZone('Australia/Sydney');
        $joinDate = $joinDate ?: new DateTimeImmutable('today', $tz);
        $monthsRemaining = self::monthsRemainingUntilExpiry($joinDate);
        $monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];

        $options = [];

        // Pro-rata option: only when enabled. When disabled, new joiners just
        // pick a full renewal period like renewers do.
        if (!empty($config['prorata_enabled'])) {
            $proRataCents = self::calculateProRataCents($magazineType, $membershipType, $joinDate);
            if ($proRataCents !== null) {
                $expiryLabel = sprintf('%d %s', (int) $config['expiry_day'], $monthNames[(int) $config['expiry_month']]);
                $options[] = [
                    'key' => 'JOIN_ONLY',
                    'label' => sprintf('Join now until %s — %d month%s', $expiryLabel, $monthsRemaining, $monthsRemaining === 1 ? '' : 's'),
                    'kind' => 'prorata_only',
                    'duration_months' => $monthsRemaining,
                    'cents' => (int) $proRataCents,
                    'breakdown' => ['prorata_cents' => (int) $proRataCents],
                    'period_id' => null,
                ];
            }
        }

        $proRataBase = !empty($config['prorata_enabled'])
            ? (self::calculateProRataCents($magazineType, $membershipType, $joinDate) ?? 0)
            : 0;

        foreach (self::getRenewalPeriods(true) as $period) {
            $renewalCents = self::getRenewalPriceCents($magazineType, $membershipType, $period['id']);
            if ($renewalCents === null) {
                continue;
            }
            if (!empty($config['prorata_enabled'])) {
                $options[] = [
                    'key' => 'JOIN_PLUS_' . $period['id'],
                    'label' => sprintf('Join now + %s extension', $period['label']),
                    'kind' => 'prorata_plus',
                    'duration_months' => $monthsRemaining + (int) $period['duration_months'],
                    'cents' => (int) ($proRataBase + $renewalCents),
                    'breakdown' => [
                        'prorata_cents' => (int) $proRataBase,
                        'renewal_cents' => (int) $renewalCents,
                    ],
                    'period_id' => $period['id'],
                ];
            } else {
                $options[] = [
                    'key' => 'JOIN_PLUS_' . $period['id'],
                    'label' => $period['label'],
                    'kind' => 'renewal_only',
                    'duration_months' => (int) $period['duration_months'],
                    'cents' => (int) $renewalCents,
                    'breakdown' => ['renewal_cents' => (int) $renewalCents],
                    'period_id' => $period['id'],
                ];
            }
        }

        return $options;
    }

    /**
     * Resolve the price for a join option key produced by getJoinOptions().
     * Falls back to the legacy matrix for older keys like ONE_YEAR.
     */
    public static function resolveJoinPriceCents(string $magazineType, string $membershipType, string $key, ?DateTimeImmutable $joinDate = null): ?int
    {
        $magazineType = strtoupper($magazineType);
        $membershipType = strtoupper($membershipType);
        $config = self::getConfig();
        $proRataEnabled = !empty($config['prorata_enabled']);
        if ($key === 'JOIN_ONLY') {
            if (!$proRataEnabled) {
                return null; // option shouldn't exist; refuse to charge.
            }
            return self::calculateProRataCents($magazineType, $membershipType, $joinDate);
        }
        if (str_starts_with($key, 'JOIN_PLUS_')) {
            $periodId = substr($key, strlen('JOIN_PLUS_'));
            $renewal = self::getRenewalPriceCents($magazineType, $membershipType, $periodId);
            if ($renewal === null) {
                return null;
            }
            $proRata = $proRataEnabled
                ? (self::calculateProRataCents($magazineType, $membershipType, $joinDate) ?? 0)
                : 0;
            return (int) ($proRata + $renewal);
        }
        // Legacy: ONE_YEAR / THREE_YEARS / ONE_THIRD / etc. — fall through.
        return self::getPriceCents($magazineType, $membershipType, $key);
    }

    /* ------------------------------------------------------------------ */
    /* Backwards compatibility (legacy callers)                            */
    /* ------------------------------------------------------------------ */

    /** Legacy shape: ['currency', 'rows', 'matrix']. */
    public static function getMembershipPricing(): array
    {
        $config = self::getConfig();
        $matrix = self::buildLegacyMatrix($config);
        $rows = [];
        foreach ($matrix as $magazine => $byType) {
            foreach ($byType as $type => $byPeriod) {
                foreach ($byPeriod as $periodKey => $cents) {
                    $rows[] = [
                        'magazine_type' => $magazine,
                        'membership_type' => $type,
                        'period_key' => $periodKey,
                        'amount_cents' => $cents,
                        'currency' => $config['currency'],
                    ];
                }
            }
        }
        return [
            'currency' => $config['currency'],
            'rows' => $rows,
            'matrix' => $matrix,
        ];
    }

    /** Legacy: a flat list of period definitions used by /apply form filters. */
    public static function periodDefinitions(): array
    {
        $config = self::getConfig();
        // Combine the admin's renewal periods with the implicit pro-rata
        // periods so existing callers keep getting a hash they can iterate.
        $out = [];
        foreach ($config['renewal_periods'] as $period) {
            $out[$period['id']] = [
                'label' => $period['label'],
                'duration_months' => (int) $period['duration_months'],
                'join_after' => null,
                'kind' => 'renewal',
            ];
        }
        if (!empty($config['prorata_enabled'])) {
            // Synthesise legacy pro-rata keys so the old apply.php select
            // box still has options to render. The save handler will ignore
            // these on submit and use the live pro-rata calculator instead.
            foreach (self::LEGACY_PERIOD_MONTHS as $key => $months) {
                if (isset($out[$key])) {
                    continue;
                }
                $joinAfter = $months % 12 === 0 ? null : ($months % 12 === 4 ? 'april' : 'december');
                $out[$key] = [
                    'label' => self::LEGACY_PERIOD_LABELS[$key],
                    'duration_months' => $months,
                    'join_after' => $joinAfter,
                    'kind' => 'prorata',
                ];
            }
        }
        return $out;
    }

    public static function getPriceCents(string $magazineType, string $membershipType, string $periodKey): ?int
    {
        $matrix = self::buildLegacyMatrix(self::getConfig());
        return $matrix[strtoupper($magazineType)][strtoupper($membershipType)][$periodKey] ?? null;
    }

    /**
     * Legacy seed used by the "Reset to defaults" button in the old admin UI
     * (still referenced by SettingsService). Returns the same 24-row shape as
     * before, regenerated from the new defaults so it stays in sync.
     */
    public static function defaultPricingRows(): array
    {
        $config = self::defaultConfig();
        $matrix = self::buildLegacyMatrix($config);
        $rows = [];
        foreach ($matrix as $magazine => $byType) {
            foreach ($byType as $type => $byPeriod) {
                foreach ($byPeriod as $periodKey => $cents) {
                    $rows[] = [
                        'magazine_type' => $magazine,
                        'membership_type' => $type,
                        'period_key' => $periodKey,
                        'amount_cents' => $cents,
                        'currency' => 'AUD',
                    ];
                }
            }
        }
        return $rows;
    }

    /** Legacy update path — converts an old payload to a config update. */
    public static function updateMembershipPricing(int $actorUserId, array $payload): void
    {
        $config = self::getConfig();
        $matrix = [];
        if (isset($payload['matrix']) && is_array($payload['matrix'])) {
            $matrix = $payload['matrix'];
        } elseif (isset($payload['rows']) && is_array($payload['rows'])) {
            foreach ($payload['rows'] as $row) {
                $m = strtoupper((string) ($row['magazine_type'] ?? ''));
                $t = strtoupper((string) ($row['membership_type'] ?? ''));
                $k = (string) ($row['period_key'] ?? '');
                if ($m === '' || $t === '' || $k === '') {
                    continue;
                }
                $matrix[$m][$t][$k] = (int) ($row['amount_cents'] ?? 0);
            }
        }
        if (!$matrix) {
            return;
        }
        // Fold the legacy matrix back into the new config. We update renewal
        // prices for any periods whose duration matches a legacy key (12mo →
        // existing 1Y period, 36mo → existing 3Y period) and update the
        // pro-rata annual prices from any 12mo / ONE_YEAR rows.
        foreach ($matrix as $magazine => $byType) {
            foreach ($byType as $type => $byPeriod) {
                foreach ($byPeriod as $periodKey => $cents) {
                    $months = self::LEGACY_PERIOD_MONTHS[$periodKey] ?? null;
                    if ($months === 12) {
                        $config['prorata_annual_prices'][$magazine][$type] = (int) $cents;
                    }
                    if ($months !== null) {
                        $matchedPeriod = null;
                        foreach ($config['renewal_periods'] as $period) {
                            if ((int) $period['duration_months'] === $months && !empty($period['active'])) {
                                $matchedPeriod = $period['id'];
                                break;
                            }
                        }
                        if ($matchedPeriod !== null) {
                            $config['renewal_prices'][$magazine][$type][$matchedPeriod] = (int) $cents;
                        }
                    }
                }
            }
        }
        self::updateConfig($actorUserId, $config);
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    private static function normalizeConfig(array $cfg): array
    {
        $defaults = self::defaultConfig();
        $out = $defaults;
        $out['anchor_month'] = self::clampInt($cfg['anchor_month'] ?? $defaults['anchor_month'], 1, 12);
        $out['anchor_day'] = self::clampInt($cfg['anchor_day'] ?? $defaults['anchor_day'], 1, 28);
        $out['expiry_month'] = self::clampInt($cfg['expiry_month'] ?? $defaults['expiry_month'], 1, 12);
        $out['expiry_day'] = self::clampInt($cfg['expiry_day'] ?? $defaults['expiry_day'], 1, 31);
        $out['currency'] = (string) ($cfg['currency'] ?? 'AUD') ?: 'AUD';
        $out['prorata_enabled'] = !empty($cfg['prorata_enabled']);
        $out['prorata_minimum_months'] = self::clampInt($cfg['prorata_minimum_months'] ?? 1, 1, 12);
        $rounding = (string) ($cfg['prorata_rounding'] ?? 'nearest_dollar');
        $out['prorata_rounding'] = in_array($rounding, ['nearest_dollar', 'nearest_cent'], true)
            ? $rounding : 'nearest_dollar';

        $rawPeriods = is_array($cfg['renewal_periods'] ?? null) ? $cfg['renewal_periods'] : $defaults['renewal_periods'];
        $periods = [];
        $usedIds = [];
        $idx = 0;
        foreach ($rawPeriods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $label = trim((string) ($period['label'] ?? ''));
            $duration = self::clampInt($period['duration_months'] ?? 0, 1, 120);
            if ($label === '' || $duration === 0) {
                continue;
            }
            $id = self::sanitisePeriodId((string) ($period['id'] ?? ''));
            if ($id === '' || isset($usedIds[$id])) {
                $id = self::generatePeriodId($duration, $idx);
                while (isset($usedIds[$id])) {
                    $idx++;
                    $id = self::generatePeriodId($duration, $idx);
                }
            }
            $usedIds[$id] = true;
            $idx++;
            $periods[] = [
                'id' => $id,
                'label' => $label,
                'duration_months' => $duration,
                'sort_order' => self::clampInt($period['sort_order'] ?? ($idx * 10), 0, 9999),
                'active' => !empty($period['active']),
            ];
        }
        if (!$periods) {
            $periods = $defaults['renewal_periods'];
        }
        usort($periods, static fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        $out['renewal_periods'] = $periods;

        $renewalPrices = [];
        $rawRenewalPrices = is_array($cfg['renewal_prices'] ?? null) ? $cfg['renewal_prices'] : [];
        foreach (self::MAGAZINE_TYPES as $magazine) {
            foreach (self::MEMBERSHIP_TYPES as $type) {
                foreach ($periods as $period) {
                    $cents = $rawRenewalPrices[$magazine][$type][$period['id']] ?? null;
                    $renewalPrices[$magazine][$type][$period['id']] = is_numeric($cents) ? max(0, (int) $cents) : 0;
                }
            }
        }
        $out['renewal_prices'] = $renewalPrices;

        $proRataPrices = [];
        $rawProRata = is_array($cfg['prorata_annual_prices'] ?? null) ? $cfg['prorata_annual_prices'] : [];
        foreach (self::MAGAZINE_TYPES as $magazine) {
            foreach (self::MEMBERSHIP_TYPES as $type) {
                $cents = $rawProRata[$magazine][$type] ?? null;
                $proRataPrices[$magazine][$type] = is_numeric($cents) ? max(0, (int) $cents) : 0;
            }
        }
        $out['prorata_annual_prices'] = $proRataPrices;

        return $out;
    }

    private static function buildLegacyMatrix(array $config): array
    {
        $matrix = [];
        // 1) Renewal prices map onto legacy keys when duration matches.
        foreach (self::MAGAZINE_TYPES as $magazine) {
            foreach (self::MEMBERSHIP_TYPES as $type) {
                foreach ($config['renewal_periods'] as $period) {
                    if (empty($period['active'])) {
                        continue;
                    }
                    $cents = $config['renewal_prices'][$magazine][$type][$period['id']] ?? 0;
                    // New-style key (admin period id) — always present.
                    $matrix[$magazine][$type][$period['id']] = (int) $cents;
                    // Legacy compatible key (e.g. ONE_YEAR / THREE_YEARS) — only
                    // when this period's duration matches an old constant.
                    $months = (int) $period['duration_months'];
                    $legacyKey = array_search($months, self::LEGACY_PERIOD_MONTHS, true);
                    if (is_string($legacyKey)) {
                        $matrix[$magazine][$type][$legacyKey] = (int) $cents;
                    }
                }
            }
        }
        // 2) Pro-rata fills in the rest of the legacy keys (and overrides
        //    `ONE_YEAR` if no renewal period uses 12 months).
        if (!empty($config['prorata_enabled'])) {
            foreach (self::MAGAZINE_TYPES as $magazine) {
                foreach (self::MEMBERSHIP_TYPES as $type) {
                    $annual = (int) ($config['prorata_annual_prices'][$magazine][$type] ?? 0);
                    foreach (self::LEGACY_PERIOD_MONTHS as $key => $months) {
                        if (isset($matrix[$magazine][$type][$key])) {
                            continue;
                        }
                        $raw = $annual * ($months / 12);
                        $rounded = $config['prorata_rounding'] === 'nearest_dollar'
                            ? (int) (round($raw / 100) * 100)
                            : (int) round($raw);
                        $matrix[$magazine][$type][$key] = $rounded;
                    }
                }
            }
        }
        return $matrix;
    }

    private static function migrateFromLegacyMatrix(array $legacy): array
    {
        $rows = [];
        if (isset($legacy['rows']) && is_array($legacy['rows'])) {
            $rows = $legacy['rows'];
        } elseif (isset($legacy[0])) {
            $rows = $legacy;
        }
        $config = self::defaultConfig();
        $oneYearPrices = [];
        $threeYearPrices = [];
        foreach ($rows as $row) {
            $magazine = strtoupper((string) ($row['magazine_type'] ?? ''));
            $type = strtoupper((string) ($row['membership_type'] ?? ''));
            $key = (string) ($row['period_key'] ?? '');
            $cents = (int) ($row['amount_cents'] ?? 0);
            if ($magazine === '' || $type === '' || $key === '') {
                continue;
            }
            if ($key === 'ONE_YEAR') {
                $oneYearPrices[$magazine][$type] = $cents;
            } elseif ($key === 'THREE_YEARS') {
                $threeYearPrices[$magazine][$type] = $cents;
            }
        }
        foreach (self::MAGAZINE_TYPES as $magazine) {
            foreach (self::MEMBERSHIP_TYPES as $type) {
                if (isset($oneYearPrices[$magazine][$type])) {
                    $config['prorata_annual_prices'][$magazine][$type] = $oneYearPrices[$magazine][$type];
                    $config['renewal_prices'][$magazine][$type]['P_1Y'] = $oneYearPrices[$magazine][$type];
                }
                if (isset($threeYearPrices[$magazine][$type])) {
                    $config['renewal_prices'][$magazine][$type]['P_3Y'] = $threeYearPrices[$magazine][$type];
                }
            }
        }
        return $config;
    }

    private static function makeDate(int $year, int $month, int $day, DateTimeZone $tz): DateTimeImmutable
    {
        $day = min($day, (int) (new DateTimeImmutable("$year-$month-01", $tz))->format('t'));
        return (new DateTimeImmutable("$year-$month-$day", $tz))->setTime(0, 0, 0);
    }

    private static function clampInt($value, int $min, int $max): int
    {
        $i = (int) $value;
        if ($i < $min) return $min;
        if ($i > $max) return $max;
        return $i;
    }

    private static function sanitisePeriodId(string $id): string
    {
        $id = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $id) ?? '');
        return substr($id, 0, 32);
    }

    private static function generatePeriodId(int $months, int $offset): string
    {
        $years = (int) floor($months / 12);
        $rem = $months % 12;
        if ($rem === 0 && $years >= 1) {
            return 'P_' . $years . 'Y' . ($offset > 0 ? '_' . $offset : '');
        }
        return 'P_M' . $months . ($offset > 0 ? '_' . $offset : '');
    }

    private static function monthName(int $month, bool $short = false): string
    {
        $names = ['', 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];
        $month = max(1, min(12, $month));
        return $short ? substr($names[$month], 0, 3) : $names[$month];
    }
}
