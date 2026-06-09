<?php
/**
 * Stripe references block — renders the Stripe ids attached to an order as
 * clickable dashboard.stripe.com links. Used on admin order detail surfaces
 * (admin/store/order_view.php, the selected-membership-order panel on
 * admin/members/view.php, and any future standalone order detail page) so
 * staff can pivot from a Goldwing order straight to the corresponding Stripe
 * record without copy-pasting ids around.
 *
 * Expects an associative array $order with any of:
 *   stripe_payment_intent_id   →  /payments/{id}
 *   stripe_invoice_id          →  /invoices/{id}
 *   stripe_session_id          →  /checkout/sessions/{id}
 *   stripe_subscription_id     →  /subscriptions/{id}
 *   stripe_charge_id           →  /charges/{id}
 *
 * Each present id is rendered as a labelled row with the truncated id and an
 * external link. Missing ids are skipped. If no Stripe ids are present, the
 * block prints a small "No Stripe records linked yet." note instead of
 * rendering an empty card.
 *
 * Pass $variant = 'inline' for a compact list (no card wrapper) — used inside
 * the embedded membership panel in members/view.php where a card-in-card
 * looks heavy. Default ('card') wraps in the same rounded-2xl card style used
 * by the store order page.
 *
 * NOTE: dashboard.stripe.com auto-detects test vs live based on the account
 * cookie, so we don't switch URL prefixes. If you need /test/ links, add a
 * mode-aware prefix here later.
 */

if (!function_exists('render_stripe_references_block')) {
    function render_stripe_references_block(array $order, string $variant = 'card'): string
    {
        $entries = [];
        $candidates = [
            'stripe_payment_intent_id' => ['label' => 'Payment Intent', 'path' => 'payments'],
            'stripe_invoice_id'        => ['label' => 'Invoice',        'path' => 'invoices'],
            'stripe_session_id'        => ['label' => 'Checkout Session', 'path' => 'checkout/sessions'],
            'stripe_subscription_id'   => ['label' => 'Subscription',   'path' => 'subscriptions'],
            'stripe_charge_id'         => ['label' => 'Charge',         'path' => 'charges'],
        ];
        foreach ($candidates as $key => $meta) {
            $val = trim((string) ($order[$key] ?? ''));
            if ($val === '') {
                continue;
            }
            $entries[] = [
                'label' => $meta['label'],
                'id' => $val,
                'url' => 'https://dashboard.stripe.com/' . $meta['path'] . '/' . rawurlencode($val),
            ];
        }

        ob_start();

        if ($variant === 'inline') {
            if (!$entries) {
                echo '<div class="mt-3 text-xs text-gray-500">No Stripe records linked yet.</div>';
            } else {
                echo '<div class="mt-3">';
                echo '<p class="text-xs uppercase tracking-[0.3em] text-gray-500">Stripe references</p>';
                echo '<ul class="mt-1 space-y-1 text-xs">';
                foreach ($entries as $e) {
                    $idHtml = htmlspecialchars($e['id'], ENT_QUOTES, 'UTF-8');
                    $url = htmlspecialchars($e['url'], ENT_QUOTES, 'UTF-8');
                    echo '<li class="flex flex-wrap items-baseline gap-2">';
                    echo '  <span class="font-semibold text-gray-700">' . htmlspecialchars($e['label'], ENT_QUOTES, 'UTF-8') . ':</span>';
                    echo '  <a href="' . $url . '" target="_blank" rel="noopener" class="font-mono break-all text-blue-600 hover:underline">' . $idHtml . '</a>';
                    echo '</li>';
                }
                echo '</ul>';
                echo '</div>';
            }
            return (string) ob_get_clean();
        }

        // 'card' variant — full panel
        echo '<div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">';
        echo '<h3 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">Stripe references</h3>';
        if (!$entries) {
            echo '<p class="text-sm text-slate-500">No Stripe records linked to this order yet.</p>';
        } else {
            echo '<ul class="space-y-3 text-sm">';
            foreach ($entries as $e) {
                $idHtml = htmlspecialchars($e['id'], ENT_QUOTES, 'UTF-8');
                $url = htmlspecialchars($e['url'], ENT_QUOTES, 'UTF-8');
                echo '<li>';
                echo '  <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">' . htmlspecialchars($e['label'], ENT_QUOTES, 'UTF-8') . '</p>';
                echo '  <a href="' . $url . '" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-2 font-mono text-xs text-blue-600 hover:underline break-all">';
                echo $idHtml;
                echo '    <span aria-hidden="true">↗</span>';
                echo '  </a>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }
}
