<?php
namespace App\Services;

use DateTimeImmutable;

class MembershipService
{
    public static function calculateExpiry(string $startDate, int $termYears): string
    {
        $start = new DateTimeImmutable($startDate);
        $year = (int) $start->format('Y');
        $month = (int) $start->format('n');

        if ($month >= 8) {
            $year += 1;
        }
        $expiryYear = $year + ($termYears - 1);
        return sprintf('%04d-07-31', $expiryYear);
    }

    public static function displayMembershipNumber(int $base, int $suffix): string
    {
        $fullFormat = (string) SettingsService::getGlobal('membership.member_number_format_full', '{base}');
        $associateFormat = (string) SettingsService::getGlobal('membership.member_number_format_associate', '{base}.{suffix}');
        $basePadding = (int) SettingsService::getGlobal('membership.member_number_base_padding', 0);
        $suffixPadding = (int) SettingsService::getGlobal('membership.member_number_suffix_padding', 0);
        $template = $suffix > 0 ? $associateFormat : $fullFormat;
        $formatted = self::formatMemberNumberFromTemplate($template, $base, $suffix, $basePadding, $suffixPadding);
        if ($formatted !== '') {
            return $formatted;
        }
        return $suffix === 0 ? (string) $base : $base . '.' . $suffix;
    }

    public static function parseMemberNumberString(string $value): ?array
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $fullFormat = (string) SettingsService::getGlobal('membership.member_number_format_full', '{base}');
        $associateFormat = (string) SettingsService::getGlobal('membership.member_number_format_associate', '{base}.{suffix}');

        $patterns = array_filter([
            self::buildMemberNumberRegex($associateFormat),
            self::buildMemberNumberRegex($fullFormat),
        ]);

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $normalized, $matches)) {
                continue;
            }
            $base = isset($matches['base']) ? (int) $matches['base'] : 0;
            $suffix = isset($matches['suffix']) ? (int) $matches['suffix'] : 0;
            if ($base <= 0 || $suffix < 0) {
                return null;
            }
            return [
                'base' => $base,
                'suffix' => $suffix,
                'display' => self::displayMembershipNumber($base, $suffix),
            ];
        }

        $fallback = str_replace(' ', '', $normalized);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $fallback)) {
            return null;
        }
        $parts = explode('.', $fallback, 2);
        $base = (int) $parts[0];
        $suffix = count($parts) === 2 ? (int) $parts[1] : 0;
        if ($base <= 0 || $suffix < 0) {
            return null;
        }
        return [
            'base' => $base,
            'suffix' => $suffix,
            'display' => self::displayMembershipNumber($base, $suffix),
        ];
    }

    private static function formatMemberNumberFromTemplate(string $template, int $base, int $suffix, int $basePadding, int $suffixPadding): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }
        $hasBase = strpos($template, '{base}') !== false || strpos($template, '{base_padded}') !== false;
        if (!$hasBase) {
            return '';
        }
        $needsSuffix = strpos($template, '{suffix}') !== false || strpos($template, '{suffix_padded}') !== false;
        if ($suffix > 0 && !$needsSuffix) {
            return '';
        }
        $baseText = (string) $base;
        $suffixText = (string) $suffix;
        $basePadded = $basePadding > 0 ? str_pad($baseText, $basePadding, '0', STR_PAD_LEFT) : $baseText;
        $suffixPadded = $suffixPadding > 0 ? str_pad($suffixText, $suffixPadding, '0', STR_PAD_LEFT) : $suffixText;

        return str_replace(
            ['{base_padded}', '{base}', '{suffix_padded}', '{suffix}'],
            [$basePadded, $baseText, $suffixPadded, $suffixText],
            $template
        );
    }

    private static function buildMemberNumberRegex(string $template): ?string
    {
        $template = trim($template);
        if ($template === '') {
            return null;
        }
        $hasBase = strpos($template, '{base}') !== false || strpos($template, '{base_padded}') !== false;
        if (!$hasBase) {
            return null;
        }
        $pattern = preg_quote($template, '/');
        $pattern = str_replace(preg_quote('{base}', '/'), '(?P<base>\d+)', $pattern);
        $pattern = str_replace(preg_quote('{base_padded}', '/'), '(?P<base>\d+)', $pattern);
        $pattern = str_replace(preg_quote('{suffix}', '/'), '(?P<suffix>\d+)', $pattern);
        $pattern = str_replace(preg_quote('{suffix_padded}', '/'), '(?P<suffix>\d+)', $pattern);
        return '/^' . $pattern . '$/';
    }

    public static function createMembershipPeriod(int $memberId, string $term, string $startDate): int
    {
        $expiry = null;
        if ($term !== 'LIFE') {
            $termYears = $term === '3Y' ? 3 : 1;
            $expiry = self::calculateExpiry($startDate, $termYears);
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO membership_periods (member_id, term, start_date, end_date, status, created_at) VALUES (:member_id, :term, :start_date, :end_date, :status, NOW())');
        $stmt->execute([
            'member_id' => $memberId,
            'term' => $term,
            'start_date' => $startDate,
            'end_date' => $expiry,
            'status' => 'PENDING_PAYMENT',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function markPaid(int $periodId, string $paymentId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE membership_periods SET status = :status, payment_id = :payment_id, paid_at = NOW() WHERE id = :id');
        $stmt->execute([
            'status' => 'ACTIVE',
            'payment_id' => $paymentId,
            'id' => $periodId,
        ]);
        $stmt = $pdo->prepare('UPDATE members SET status = :status WHERE id = (SELECT member_id FROM membership_periods WHERE id = :id)');
        $stmt->execute([
            'status' => 'ACTIVE',
            'id' => $periodId,
        ]);
    }
}
