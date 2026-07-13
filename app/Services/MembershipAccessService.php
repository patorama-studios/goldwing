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

    /** Normalised status values that mean the membership is locked down. */
    public const LAPSED_STATUSES = ['expired', 'lapsed', 'cancelled', 'suspended', 'inactive'];

    /**
     * Grace period after a membership's end_date before features lock off.
     * The member stays expired-but-open during this window: full feature
     * access, a warning banner, and they can still log in and update details.
     * The expiry cron must use the same value (it reads this constant) so the
     * "still active" window and the cron's LAPSED flip can never disagree.
     */
    public const GRACE_MONTHS = 2;

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

    /** Does this normalised status value mean the member is locked out? */
    public static function isLockedStatus(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::LAPSED_STATUSES, true);
    }

    /**
     * Resolve the single effective status from the two status fields.
     *
     * members.status is authoritative for the locked states (expired,
     * cancelled, suspended, inactive): an admin setting any of these in the
     * single-member view — or the expiry cron — wins immediately, even if the
     * latest membership_period row still reads ACTIVE. For active/pending
     * members we trust the live period status instead, which keeps a freshly
     * renewed member (members.status already 'active') from being false-locked
     * by a stale value. Pure function so the dashboard and the lockdown agree.
     */
    public static function effectiveStatusFrom(?array $member, ?array $period): string
    {
        $memberStatus = strtolower(trim((string) ($member['status'] ?? '')));
        $periodStatus = strtolower(trim((string) ($period['status'] ?? '')));

        // Admin / cron override on members.status wins.
        if (self::isLockedStatus($memberStatus)) {
            return $memberStatus;
        }

        // Otherwise defer to the live period status.
        if ($periodStatus !== '') {
            return $periodStatus;
        }
        if (!empty($period['end_date'])
            && strtotime((string) $period['end_date']) >= strtotime('today')) {
            return 'active';
        }
        return $memberStatus;
    }

    /**
     * Resolve the membership access state for a session user.
     *
     * `lapsed`   - features are locked off (grace exhausted, or an admin/cron
     *              set a locked status). `in_grace` - expired but still inside
     *              the GRACE_MONTHS window: features stay open, show a warning.
     * `lockoff_date` - the day features lock off (end_date + GRACE_MONTHS).
     *
     * @return array{lapsed:bool, in_grace:bool, status:string, member_type:string, end_date:?string, lockoff_date:?string, reason:string}
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
            'in_grace' => false,
            'status' => '',
            'member_type' => '',
            'end_date' => null,
            'lockoff_date' => null,
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

            // Prefer real (activated/expired) periods over never-paid
            // PENDING_PAYMENT rows — a pending renewal's provisional end date
            // must not drive the lockdown (it reads "expired on <future date>"
            // and locks a still-covered member out). Mirrors the dashboard
            // expiry query in /member/index.php.
            $stmt = $pdo->prepare('SELECT status, end_date FROM membership_periods WHERE member_id = :mid ORDER BY (status <> "PENDING_PAYMENT") DESC, end_date DESC LIMIT 1');
            $stmt->execute([':mid' => $memberId]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $endDate = $period['end_date'] ?? null;
            $result['end_date'] = $endDate;
            $result['lockoff_date'] = $endDate
                ? date('Y-m-d', strtotime($endDate . ' +' . self::GRACE_MONTHS . ' months'))
                : null;

            // members.status wins for admin/cron-set locked states; otherwise
            // the live period status is used. See effectiveStatusFrom().
            $statusKey = self::effectiveStatusFrom($member, $period);
            $result['status'] = $statusKey;

            // A payment in flight (processing/succeeded per the dashboard's
            // session reconcile cache) keeps full access while activation
            // settles via the webhook — never lock someone who just paid.
            if (self::paymentInFlight()) {
                $result['reason'] = 'payment-in-flight';
                return self::$stateCache[$cacheKey] = $result;
            }

            // A submitted bank-transfer renewal awaiting an admin's manual
            // payment check locks the member out until it's approved (which
            // activates them) or denied. Account/billing/renewal pages stay
            // open (see OPEN_PAGES) so they can still see status and details.
            // Only applies once their real coverage has actually ended — a
            // member renewing EARLY (current period still running) keeps full
            // access while the transfer awaits approval.
            $today = strtotime('today');
            $hasCurrentCoverage = $endDate && strtotime((string) $endDate) >= $today;
            if (!self::isLockedStatus($statusKey) && !$hasCurrentCoverage
                && self::hasPendingBankTransferRenewal($pdo, $memberId)) {
                $result['status'] = 'awaiting-payment';
                $result['lapsed'] = true;
                $result['reason'] = 'awaiting-payment';
                return self::$stateCache[$cacheKey] = $result;
            }

            // Locked off when an admin/cron set a locked status, OR the grace
            // window has run out (date safety-net in case the cron is late).
            // Between end_date and lockoff_date the member is "in grace":
            // expired but still has full access — we only warn them.
            $pastLockoff = !empty($result['lockoff_date'])
                && strtotime((string) $result['lockoff_date']) < $today;
            $expiredByDate = $endDate && strtotime((string) $endDate) < $today;

            $result['lapsed'] = self::isLockedStatus($statusKey) || $pastLockoff;
            $result['in_grace'] = !$result['lapsed'] && $expiredByDate;
            $result['reason'] = $result['lapsed']
                ? 'lapsed'
                : ($result['in_grace'] ? 'grace' : 'active');
        } catch (Throwable $e) {
            // Fail open — never trap a member behind a DB hiccup.
            error_log('[MembershipAccessService] state failed: ' . $e->getMessage());
            $result['lapsed'] = false;
            $result['reason'] = 'error';
        }

        return self::$stateCache[$cacheKey] = $result;
    }

    /**
     * True when the member has a bank-transfer membership order still awaiting
     * an admin's manual payment confirmation. Fails open on any DB error so a
     * hiccup never traps a member behind the lockdown.
     */
    private static function hasPendingBankTransferRenewal(PDO $pdo, int $memberId): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM orders
                 WHERE member_id = :mid AND order_type = 'membership'
                       AND payment_method = 'bank_transfer' AND payment_status = 'pending'
                 LIMIT 1"
            );
            $stmt->execute([':mid' => $memberId]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
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
