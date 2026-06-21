<?php
namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Single entry point for admin-initiated member status / renewal-date edits.
 *
 * members.status and the latest membership_periods row's status both feed the
 * dashboard lockdown reader (MembershipAccessService::effectiveStatusFrom).
 * Historically the admin UI wrote to one column at a time, which let the two
 * drift — e.g. activating a previously-lapsed member would leave
 * membership_periods.status='LAPSED' and the member would still see the
 * "renew now" lockdown despite members.status='ACTIVE'. All admin
 * status/renewal-date writes go through this class so both columns stay in
 * sync.
 */
class MembershipStatusService
{
    /**
     * Apply an admin-initiated status and/or renewal-date update.
     *
     * @param array $update {
     *   'status'?:   lowercase legacy — pending|active|expired|cancelled|suspended (omit to leave alone)
     *   'end_date'?: Y-m-d, or '' / null to clear (omit to leave alone)
     * }
     * @return array Before/after summary suitable for ActivityLogger.
     */
    public static function applyAdminUpdate(int $memberId, array $update): array
    {
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $before = self::snapshot($pdo, $memberId);
            if (!$before['member']) {
                throw new RuntimeException('Member not found.');
            }

            $hasStatus = array_key_exists('status', $update);
            $hasEndDate = array_key_exists('end_date', $update);

            $newMemberStatus = $hasStatus
                ? strtolower(trim((string) $update['status']))
                : null;
            $newEndDate = $hasEndDate
                ? (trim((string) ($update['end_date'] ?? '')) ?: null)
                : null;

            if ($hasStatus) {
                if (!MemberRepository::update($memberId, ['status' => $newMemberStatus])) {
                    throw new RuntimeException('Could not save member status.');
                }
            }

            $period = $before['period'];
            if ($period) {
                $periodUpdates = [];
                $params = ['id' => $period['id']];

                if ($hasEndDate) {
                    $periodUpdates[] = 'end_date = :end_date';
                    $params['end_date'] = $newEndDate;
                }

                $effectiveEndDate = $hasEndDate ? $newEndDate : ($period['end_date'] ?? null);
                $newPeriodStatus = self::derivePeriodStatus(
                    $newMemberStatus,
                    $effectiveEndDate,
                    $period['status'] ?? null
                );
                if ($newPeriodStatus !== null
                    && strtoupper((string) ($period['status'] ?? '')) !== $newPeriodStatus) {
                    $periodUpdates[] = 'status = :status';
                    $params['status'] = $newPeriodStatus;
                }

                if ($periodUpdates) {
                    $sql = 'UPDATE membership_periods SET '
                        . implode(', ', $periodUpdates) . ' WHERE id = :id';
                    $pdo->prepare($sql)->execute($params);
                }
            }

            $after = self::snapshot($pdo, $memberId);
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'member_status' => [
                    'from' => $before['member']['status'] ?? null,
                    'to'   => $after['member']['status'] ?? null,
                ],
                'period_status' => [
                    'from' => $before['period']['status'] ?? null,
                    'to'   => $after['period']['status'] ?? null,
                ],
                'end_date' => [
                    'from' => $before['period']['end_date'] ?? null,
                    'to'   => $after['period']['end_date'] ?? null,
                ],
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Decide what membership_periods.status should be after the admin's edit.
     * Returns null if no change is warranted.
     *
     * Status intent wins: 'active' → ACTIVE, lapsed-class → LAPSED, etc.
     * When only end_date changed, flip period status if it now obviously
     * contradicts the new date (LAPSED with future date → ACTIVE; ACTIVE with
     * past date → LAPSED). Otherwise leave it alone.
     */
    private static function derivePeriodStatus(
        ?string $newMemberStatus,
        ?string $effectiveEndDate,
        ?string $currentPeriodStatus
    ): ?string {
        if ($newMemberStatus !== null) {
            return match ($newMemberStatus) {
                'active'   => 'ACTIVE',
                'pending'  => 'PENDING',
                'expired', 'cancelled', 'suspended' => 'LAPSED',
                default    => null,
            };
        }
        if ($effectiveEndDate === null) {
            return null;
        }
        $current = strtoupper((string) $currentPeriodStatus);
        $future = strtotime($effectiveEndDate) >= strtotime('today');
        if ($future && $current === 'LAPSED') {
            return 'ACTIVE';
        }
        if (!$future && $current === 'ACTIVE') {
            return 'LAPSED';
        }
        return null;
    }

    private static function snapshot(PDO $pdo, int $memberId): array
    {
        $stmt = $pdo->prepare('SELECT id, status FROM members WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare('SELECT id, status, end_date FROM membership_periods WHERE member_id = :mid ORDER BY start_date DESC, id DESC LIMIT 1');
        $stmt->execute(['mid' => $memberId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return ['member' => $member, 'period' => $period];
    }
}
