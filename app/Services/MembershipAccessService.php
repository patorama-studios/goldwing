<?php
namespace App\Services;

use PDO;
use Throwable;

/**
 * Single source of truth for the "membership lapsed" lockdown.
 *
 * Decides whether a logged-in member's membership has lapsed, and which
 * member-area features are locked while it has. The sidebar, the persistent
 * banner, and the page blur-overlay all read from here so they can never
 * disagree with the dashboard status dot.
 *
 * "Lapsed" mirrors the dashboard status logic in /member/index.php:
 *   - the live membership_periods.status is preferred over the cached
 *     members.status (which can be stale right after a renewal);
 *   - LIFE members never lapse;
 *   - a payment in flight (PI processing/succeeded per the session reconcile
 *     cache) keeps full access while activation settles.
 *
 * Fails OPEN: any DB hiccup leaves the member with full access rather than
 * trapping them behind a blur overlay they can't clear.
 */
class MembershipAccessService
{
    /** Page keys that stay open while lapsed (account + renewal essentials). */
    public const OPEN_PAGES = [
        'dashboard', 'profile', 'billing', 'history', 'settings', 'help',
    ];

    /** Page keys locked behind an active membership. */
    public const LOCKED_PAGES = [
        'calendar',
        'notices', 'notices-view', 'notices-create',
        'wings', 'fallen-wings',
        'member-of-the-year', 'awards',
        'directory', 'committee', 'dealers', 'store',
        'notifications',
    ];

    /** Normalised status values that mean the membership has lapsed. */
    private const LAPSED_STATUSES = ['expired', 'lapsed', 'inactive'];

    /** Per-request memo keyed by user/member id so each page hits the DB once. */
    private static array $stateCache = [];

    /** Is this member-area page key gated behind an active membership? */
    public static function isPageLocked(?string $pageKey): bool
    {
        if ($pageKey === null) {
            return false;
        }
        return in_array(strtolower(trim($pageKey)), self::LOCKED_PAGES, true);
    }

    /** Convenience boolean — has this user's membership lapsed? */
    public static function isLapsed(?array $user): bool
    {
        return (bool) (self::state($user)['lapsed'] ?? false);
    }

    /**
     * Resolve the membership access state for a session user.
     *
     * @return array{lapsed:bool, status:string, member_type:string, end_date:?string, reason:string}
     */
    public static function state(?array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $memberId = (int) ($user['member_id'] ?? 0);
        $cacheKey = $userId . ':' . $memberId;
        if (isset(self::$stateCache[$cacheKey])) {
            return self::$stateCache[$cacheKey];
        }

        $result = [
            'lapsed' => false,
            'status' => '',
            'member_type' => '',
            'end_date' => null,
            'reason' => 'no-member',
        ];

        if ($memberId <= 0) {
            return self::$stateCache[$cacheKey] = $result;
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT status, member_type FROM members WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                return self::$stateCache[$cacheKey] = $result;
            }

            $memberType = strtoupper((string) ($member['member_type'] ?? ''));
            $result['member_type'] = $memberType;

            // LIFE members never lapse — no renewal, no lockdown.
            if ($memberType === 'LIFE') {
                $result['reason'] = 'life';
                return self::$stateCache[$cacheKey] = $result;
            }

            $stmt = $pdo->prepare('SELECT status, end_date FROM membership_periods WHERE member_id = :mid ORDER BY end_date DESC LIMIT 1');
            $stmt->execute([':mid' => $memberId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $result['end_date'] = $period['end_date'] ?? null;

            // Prefer the live period status over the cached members.status.
            $statusKey = strtolower((string) ($period['status'] ?? $member['status'] ?? ''));
            if ($statusKey === '' && !empty($period['end_date'])
                && strtotime((string) $period['end_date']) >= strtotime('today')) {
                $statusKey = 'active';
            }
            $result['status'] = $statusKey;

            // A payment in flight (processing/succeeded per the dashboard's
            // session reconcile cache) keeps full access while activation
            // settles via the webhook — never lock someone who just paid.
            if (self::paymentInFlight()) {
                $result['reason'] = 'payment-in-flight';
                return self::$stateCache[$cacheKey] = $result;
            }

            $result['lapsed'] = in_array($statusKey, self::LAPSED_STATUSES, true);
            $result['reason'] = $result['lapsed'] ? 'lapsed' : 'active';
        } catch (Throwable $e) {
            // Fail open — never trap a member behind a DB hiccup.
            error_log('[MembershipAccessService] state failed: ' . $e->getMessage());
            $result['lapsed'] = false;
            $result['reason'] = 'error';
        }

        return self::$stateCache[$cacheKey] = $result;
    }

    /**
     * True when the dashboard's session reconcile cache shows a membership
     * PaymentIntent that is processing or already succeeded. Cheap (no Stripe
     * call) — the dashboard populates $_SESSION['pay_reconcile'].
     */
    private static function paymentInFlight(): bool
    {
        $cache = $_SESSION['pay_reconcile'] ?? null;
        if (!is_array($cache)) {
            return false;
        }
        $status = (string) ($cache['status'] ?? '');
        return $status === 'succeeded' || $status === 'processing';
    }
}
