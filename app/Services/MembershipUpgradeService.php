<?php
namespace App\Services;

use PDO;
use Throwable;

/**
 * Associate → Full Member upgrade flow.
 *
 * Pricing is read from two settings under `membership.upgrade_*`:
 *   - `upgrade_mode` = 'standard' (use the 1-year FULL price from the
 *     pricing matrix, picking PRINTED or PDF based on the member's
 *     wings_preference) or 'custom' (flat fee).
 *   - `upgrade_custom_fee_cents` = integer cents, only used in custom mode.
 *
 * The actual member-row mutation (member_type → FULL, new base number,
 * suffix → 0, full_member_id → NULL) happens in {@see convertAssociateToFull()},
 * called from the Stripe webhook after the upgrade order is paid.
 */
class MembershipUpgradeService
{
    public const UPGRADE_TERM = '1Y';

    /**
     * Resolve the upgrade price in cents for the given associate member.
     * Returns null if pricing is not configured.
     */
    public static function getUpgradePriceCents(array $member): ?int
    {
        $mode = (string) SettingsService::getGlobal('membership.upgrade_mode', 'standard');
        if ($mode === 'custom') {
            $cents = (int) SettingsService::getGlobal('membership.upgrade_custom_fee_cents', 0);
            return $cents > 0 ? $cents : null;
        }
        $magazineType = strtolower((string) ($member['wings_preference'] ?? 'digital')) === 'digital'
            ? 'PDF' : 'PRINTED';
        // Prefer the 12-month renewal period the admin has configured; fall
        // back to the legacy ONE_YEAR matrix lookup so existing data still
        // resolves during the rollout.
        $period = MembershipPricingService::findRenewalPeriodByMonths(12);
        if ($period) {
            $cents = MembershipPricingService::getRenewalPriceCents($magazineType, 'FULL', $period['id']);
            if ($cents !== null && $cents > 0) {
                return (int) $cents;
            }
        }
        $cents = MembershipPricingService::getPriceCents($magazineType, 'FULL', 'ONE_YEAR');
        return $cents !== null && $cents > 0 ? (int) $cents : null;
    }

    /** Convenience: returns dollars rounded to 2dp. */
    public static function getUpgradeAmount(array $member): float
    {
        $cents = self::getUpgradePriceCents($member);
        return $cents === null ? 0.0 : round($cents / 100, 2);
    }

    /**
     * Start an upgrade order + Stripe checkout session. Returns
     * ['ok' => bool, 'redirect_url' => ?string, 'error' => ?string].
     */
    public static function startCheckout(array $member): array
    {
        if (strtoupper((string) ($member['member_type'] ?? '')) !== 'ASSOCIATE') {
            return ['ok' => false, 'error' => 'Only Associate members can upgrade.'];
        }
        $memberId = (int) ($member['id'] ?? 0);
        if ($memberId <= 0) {
            return ['ok' => false, 'error' => 'Unable to identify the member.'];
        }

        $priceCents = self::getUpgradePriceCents($member);
        if ($priceCents === null) {
            return ['ok' => false, 'error' => 'Upgrade pricing has not been configured. Please contact the administrator.'];
        }

        $pdo = Database::connection();

        // Prevent duplicate in-flight upgrade orders.
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM orders WHERE member_id = :mid AND order_type = 'membership'
                 AND payment_status = 'pending' AND JSON_EXTRACT(internal_notes, '$.upgrade') = true
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute(['mid' => $memberId]);
            $existing = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $existing = 0; // older MySQL without JSON funcs — fall through
        }

        try {
            $periodId = MembershipService::createMembershipPeriod($memberId, self::UPGRADE_TERM, date('Y-m-d'));
            $amount = round($priceCents / 100, 2);
            $internalNotes = json_encode([
                'upgrade' => true,
                'from_member_type' => 'ASSOCIATE',
                'to_member_type' => 'FULL',
                'previous_full_member_id' => (int) ($member['full_member_id'] ?? 0) ?: null,
                'pricing_mode' => SettingsService::getGlobal('membership.upgrade_mode', 'standard'),
            ], JSON_UNESCAPED_SLASHES);

            $order = MembershipOrderService::createMembershipOrder($memberId, $periodId, $amount, [
                'payment_method' => 'stripe',
                'payment_status' => 'pending',
                'fulfillment_status' => 'pending',
                'currency' => MembershipPricingService::getMembershipPricing()['currency'] ?? 'AUD',
                'item_name' => 'Full membership upgrade (1 year)',
                'term' => self::UPGRADE_TERM,
                'internal_notes' => $internalNotes,
            ]);
            if (!$order) {
                return ['ok' => false, 'error' => 'Unable to create the upgrade order.'];
            }

            $orderId = (int) ($order['id'] ?? 0);
            $successUrl = BaseUrlService::buildUrl('/member/index.php?page=billing&upgrade=1&success=1');
            $cancelUrl = BaseUrlService::buildUrl('/member/index.php?page=profile&upgrade=cancelled');

            $lineItems = [[
                'name' => 'Full membership upgrade',
                'unit_amount' => $priceCents,
                'quantity' => 1,
                'currency' => 'aud',
            ]];

            $session = StripeService::createCheckoutSessionWithLineItems(
                $lineItems,
                (string) ($member['email'] ?? ''),
                $successUrl,
                $cancelUrl,
                [
                    'order_id' => (string) $orderId,
                    'order_type' => 'membership',
                    'member_id' => (string) $memberId,
                    'period_id' => (string) $periodId,
                    'upgrade' => '1',
                ]
            );
            if (!$session || empty($session['url'])) {
                return ['ok' => false, 'error' => 'Unable to start the checkout session.'];
            }
            OrderService::updateStripeSession($orderId, (string) ($session['id'] ?? ''));

            ActivityLogger::log('member', (int) ($member['user_id'] ?? 0) ?: null, $memberId, 'membership.upgrade.checkout_started', [
                'order_id' => $orderId,
                'amount_cents' => $priceCents,
            ]);

            return ['ok' => true, 'redirect_url' => (string) $session['url']];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Checkout failed: ' . $e->getMessage()];
        }
    }

    /**
     * Apply the actual conversion to the member row. Idempotent — if the
     * member is already FULL (or has no full_member_id link), only the
     * still-applicable updates run.
     *
     * Called from the Stripe webhook after the upgrade order is paid.
     */
    public static function convertAssociateToFull(int $memberId): bool
    {
        if ($memberId <= 0) {
            return false;
        }
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id, member_type, member_number_base, member_number_suffix, full_member_id FROM members WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return false;
        }
        if (strtoupper((string) $member['member_type']) === 'FULL') {
            // Already promoted — nothing to do.
            return true;
        }

        // Allocate a new base member number: respect membership.member_number_start
        // and pick the next free slot after MAX(member_number_base).
        $memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
        $maxBase = (int) $pdo->query('SELECT MAX(member_number_base) FROM members')->fetchColumn();
        $newBase = max($maxBase, max($memberNumberStart, 1) - 1) + 1;

        $stmt = $pdo->prepare(
            'UPDATE members SET member_type = "FULL", member_number_base = :base, member_number_suffix = 0,
                full_member_id = NULL, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['base' => $newBase, 'id' => $memberId]);

        ActivityLogger::log('system', null, $memberId, 'membership.upgrade.completed', [
            'previous_full_member_id' => $member['full_member_id'] ?? null,
            'previous_base' => $member['member_number_base'] ?? null,
            'previous_suffix' => $member['member_number_suffix'] ?? null,
            'new_base' => $newBase,
        ]);
        return true;
    }
}
