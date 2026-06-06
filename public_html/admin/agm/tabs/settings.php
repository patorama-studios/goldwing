<?php
use App\Services\Csrf;
use App\Services\StripeSettingsService;

if (!current_admin_can('admin.agm.settings', $user)) {
    echo '<div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">You do not have permission to view AGM Stripe settings.</div>';
    return;
}

$csrf = Csrf::token();
$agmSettings = StripeSettingsService::getSettings(StripeSettingsService::ACCOUNT_AGM);
$active = StripeSettingsService::getActiveKeys(StripeSettingsService::ACCOUNT_AGM);
$mask = fn(string $v) => StripeSettingsService::maskValue($v);
$webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . '/api/stripe_webhook_agm.php';
?>
<div class="space-y-6">
    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
        <h3 class="text-sm font-semibold text-blue-900 mb-1">Secondary Stripe account for AGM</h3>
        <p class="text-xs text-blue-800">All AGM registration payments flow through a separate Stripe account, keeping AGM revenue isolated from membership and store revenue. Create the account in Stripe, then paste the test or live keys below. The active mode is currently <strong><?= e($active['mode']) ?></strong>.</p>
        <p class="text-xs text-blue-800 mt-2">Webhook endpoint: <code class="bg-white px-1.5 py-0.5 rounded"><?= e($webhookUrl) ?></code> &mdash; add this in the AGM Stripe dashboard with events: <code>checkout.session.completed</code>, <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, <code>charge.refunded</code>.</p>
    </div>

    <form method="post" action="/admin/agm/actions.php" class="rounded-2xl border border-gray-200 bg-white p-6 space-y-4">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">

        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-900">
            <input type="checkbox" name="agm_stripe_use_test_mode" value="1" <?= !empty($agmSettings['use_test_mode']) ? 'checked' : '' ?>>
            Use test mode (uncheck to use live keys)
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Test publishable key</span>
                <span class="block text-xs text-gray-500"><?= $mask($agmSettings['test_publishable_key'])['configured'] ? 'Saved (****' . e($mask($agmSettings['test_publishable_key'])['last4']) . ')' : 'Not set' ?></span>
                <input type="text" name="agm_stripe_test_publishable_key" placeholder="pk_test_..." class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Test secret key</span>
                <span class="block text-xs text-gray-500"><?= $mask($agmSettings['test_secret_key'])['configured'] ? 'Saved (****' . e($mask($agmSettings['test_secret_key'])['last4']) . ')' : 'Not set' ?></span>
                <input type="text" name="agm_stripe_test_secret_key" placeholder="sk_test_..." class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Live publishable key</span>
                <span class="block text-xs text-gray-500"><?= $mask($agmSettings['live_publishable_key'])['configured'] ? 'Saved (****' . e($mask($agmSettings['live_publishable_key'])['last4']) . ')' : 'Not set' ?></span>
                <input type="text" name="agm_stripe_live_publishable_key" placeholder="pk_live_..." class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Live secret key</span>
                <span class="block text-xs text-gray-500"><?= $mask($agmSettings['live_secret_key'])['configured'] ? 'Saved (****' . e($mask($agmSettings['live_secret_key'])['last4']) . ')' : 'Not set' ?></span>
                <input type="text" name="agm_stripe_live_secret_key" placeholder="sk_live_..." class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono">
            </label>
        </div>

        <label class="block">
            <span class="text-sm font-medium text-gray-700">Webhook signing secret</span>
            <span class="block text-xs text-gray-500"><?= $mask($agmSettings['webhook_secret'])['configured'] ? 'Saved (****' . e($mask($agmSettings['webhook_secret'])['last4']) . ')' : 'Not set' ?> &mdash; from the AGM Stripe dashboard → Developers → Webhooks for the endpoint above.</span>
            <input type="text" name="agm_stripe_webhook_secret" placeholder="whsec_..." class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono">
        </label>

        <div class="text-xs text-gray-500">Saved keys are not re-displayed. Leave any field blank to keep the existing value.</div>

        <div class="flex items-center justify-end pt-4 border-t border-gray-200">
            <button class="rounded-lg bg-primary text-white px-4 py-2 text-sm font-semibold">Save AGM Stripe settings</button>
        </div>
    </form>
</div>
