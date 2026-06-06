<?php
use App\Services\AgmRegistrationService;
use App\Services\Csrf;

$csrf = Csrf::token();
if (!$selectedEvent) {
    return;
}

$filters = [
    'payment_status' => $_GET['payment_status'] ?? '',
    'pricing_tier' => $_GET['pricing_tier'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];
$registrations = AgmRegistrationService::listForEvent((int) $selectedEvent['id'], $filters);

$viewId = isset($_GET['view']) ? (int) $_GET['view'] : 0;
$detail = $viewId > 0 ? AgmRegistrationService::getRegistrationById($viewId) : null;

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="agm-' . $selectedEvent['year'] . '-registrations.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Number','Status','Tier','Attendee 1','Member#','Attendee 2','Member#','Email','Chapter','Phone','Address','Postcode','Dietary','Total','Submitted']);
    foreach ($registrations as $r) {
        fputcsv($out, [
            $r['registration_number'],
            $r['payment_status'],
            $r['pricing_tier'],
            $r['attendee1_name'],
            $r['attendee1_member_number'],
            $r['attendee2_name'],
            $r['attendee2_member_number'],
            $r['email'],
            $r['chapter'],
            $r['contact_phone_1'],
            $r['address'],
            $r['postcode'],
            $r['dietary_requirements'],
            $r['total'],
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<?php if ($detail): ?>
    <div class="rounded-2xl border border-gray-200 bg-white p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-display text-lg font-semibold text-gray-900">Registration <?= e($detail['registration_number']) ?></h2>
            <a href="?tab=submissions&event_id=<?= $selectedEventId ?>" class="text-sm text-primary hover:underline">← Back to list</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
            <div>
                <h3 class="font-semibold text-gray-900 mb-2">Attendees</h3>
                <dl class="space-y-1">
                    <dt class="text-gray-500 text-xs">Attendee 1</dt>
                    <dd><?= e($detail['attendee1_name']) ?> <?= !empty($detail['attendee1_member_number']) ? '<span class="text-xs text-gray-500">#' . e($detail['attendee1_member_number']) . '</span>' : '' ?><?= (int) $detail['attendee1_is_over_65'] ? ' <span class="text-xs">(65+)</span>' : '' ?></dd>
                    <?php if (!empty($detail['attendee2_name'])): ?>
                        <dt class="text-gray-500 text-xs">Attendee 2</dt>
                        <dd><?= e($detail['attendee2_name']) ?> <?= !empty($detail['attendee2_member_number']) ? '<span class="text-xs text-gray-500">#' . e($detail['attendee2_member_number']) . '</span>' : '' ?><?= (int) $detail['attendee2_is_over_65'] ? ' <span class="text-xs">(65+)</span>' : '' ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($detail['children_text'])): ?>
                        <dt class="text-gray-500 text-xs">Children under 18</dt>
                        <dd><?= e($detail['children_text']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900 mb-2">Contact</h3>
                <dl class="space-y-1">
                    <dt class="text-gray-500 text-xs">Email</dt>
                    <dd><?= e($detail['email']) ?></dd>
                    <dt class="text-gray-500 text-xs">Phone</dt>
                    <dd><?= e($detail['contact_phone_1']) ?><?= !empty($detail['contact_phone_2']) ? ' / ' . e($detail['contact_phone_2']) : '' ?></dd>
                    <dt class="text-gray-500 text-xs">Address</dt>
                    <dd><?= e($detail['address']) ?><?= !empty($detail['postcode']) ? ', ' . e($detail['postcode']) : '' ?></dd>
                    <dt class="text-gray-500 text-xs">Chapter</dt>
                    <dd><?= e($detail['chapter']) ?></dd>
                </dl>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900 mb-2">Emergency contacts</h3>
                <dl class="space-y-1">
                    <dt class="text-gray-500 text-xs">Contact 1</dt>
                    <dd><?= e($detail['emergency_1_name']) ?> · <?= e($detail['emergency_1_phone']) ?></dd>
                    <?php if (!empty($detail['emergency_2_name'])): ?>
                        <dt class="text-gray-500 text-xs">Contact 2</dt>
                        <dd><?= e($detail['emergency_2_name']) ?> · <?= e($detail['emergency_2_phone']) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($detail['dietary_requirements'])): ?>
                    <h3 class="font-semibold text-gray-900 mt-3 mb-1">Dietary requirements</h3>
                    <p class="text-gray-700"><?= e($detail['dietary_requirements']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($detail['motorcycles'])): ?>
            <h3 class="font-semibold text-gray-900 mt-6 mb-2">Motorcycles</h3>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b border-gray-200"><th class="py-1">Owner</th><th class="py-1">Make</th><th class="py-1">Model</th><th class="py-1">Year</th><th class="py-1">Rego</th><th class="py-1">Type</th></tr></thead>
                <tbody>
                    <?php foreach ($detail['motorcycles'] as $bike): ?>
                        <tr class="border-b border-gray-100">
                            <td class="py-1"><?= e($bike['owner_name']) ?></td>
                            <td class="py-1"><?= e($bike['make']) ?></td>
                            <td class="py-1"><?= e($bike['model']) ?></td>
                            <td class="py-1"><?= e((string) ($bike['year_built'] ?? '')) ?></td>
                            <td class="py-1 font-mono text-xs"><?= e($bike['registration_plate']) ?></td>
                            <td class="py-1 text-xs"><?= (int) $bike['is_trike'] ? 'Trike ' : '' ?><?= (int) $bike['is_sidecar'] ? 'Sidecar ' : '' ?><?= (int) $bike['has_trailer'] ? 'Trailer' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 class="font-semibold text-gray-900 mt-6 mb-2">Items (<?= ucfirst($detail['pricing_tier']) ?> pricing)</h3>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b border-gray-200"><th class="py-1">Item</th><th class="py-1 text-right">Qty</th><th class="py-1 text-right">Unit</th><th class="py-1 text-right">Total</th></tr></thead>
            <tbody>
                <?php foreach (($detail['items'] ?? []) as $it): ?>
                    <tr class="border-b border-gray-100"><td class="py-1"><?= e($it['name_snapshot']) ?><?php if (!empty($it['choice_label_snapshot'])): ?> — <?= e($it['choice_label_snapshot']) ?><?php endif; ?></td><td class="py-1 text-right"><?= (int) $it['quantity'] ?></td><td class="py-1 text-right">A$<?= number_format((float) $it['unit_price'], 2) ?></td><td class="py-1 text-right">A$<?= number_format((float) $it['line_total'], 2) ?></td></tr>
                <?php endforeach; ?>
                <tr class="font-semibold"><td class="py-2 text-right" colspan="3">Total</td><td class="py-2 text-right">A$<?= number_format((float) $detail['total'], 2) ?></td></tr>
            </tbody>
        </table>

        <div class="mt-6 flex flex-wrap gap-3 pt-4 border-t border-gray-200">
            <div class="text-sm">Payment: <span class="font-semibold"><?= e($detail['payment_method']) ?></span> · <span class="font-semibold"><?= e($detail['payment_status']) ?></span><?php if (!empty($detail['paid_at'])): ?> on <?= e(date('j M Y H:i', strtotime((string) $detail['paid_at']))) ?><?php endif; ?></div>
            <div class="ml-auto flex gap-2">
                <?php if ($detail['payment_status'] !== 'paid' && $detail['payment_status'] !== 'refunded'): ?>
                    <form method="post" action="/admin/agm/actions.php" onsubmit="return confirm('Mark this registration paid? Use this for bank transfers you have confirmed.')">
                        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="mark_registration_paid">
                        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                        <input type="hidden" name="registration_id" value="<?= (int) $detail['id'] ?>">
                        <button class="rounded-lg bg-green-600 text-white px-3 py-1.5 text-sm font-semibold">Mark paid</button>
                    </form>
                <?php endif; ?>
                <?php if ($detail['payment_status'] === 'paid' && current_admin_can('admin.agm.refund', $user)): ?>
                    <form method="post" action="/admin/agm/actions.php" onsubmit="return confirm('Refund this registration in full via Stripe? This cannot be undone.')">
                        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="refund_registration">
                        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                        <input type="hidden" name="registration_id" value="<?= (int) $detail['id'] ?>">
                        <button class="rounded-lg border border-red-300 text-red-700 px-3 py-1.5 text-sm font-medium hover:bg-red-50">Refund via Stripe</button>
                    </form>
                <?php endif; ?>
                <?php if (!in_array($detail['payment_status'], ['cancelled','refunded'], true)): ?>
                    <form method="post" action="/admin/agm/actions.php" onsubmit="var r=prompt('Reason for cancellation (optional):');if(r===null)return false;this.reason.value=r;return true;">
                        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="cancel_registration">
                        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                        <input type="hidden" name="registration_id" value="<?= (int) $detail['id'] ?>">
                        <input type="hidden" name="reason" value="">
                        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium hover:bg-gray-50">Cancel registration</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="get" class="rounded-2xl border border-gray-200 bg-white p-4 flex flex-wrap items-end gap-3">
        <input type="hidden" name="tab" value="submissions">
        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
        <label class="block">
            <span class="text-xs font-medium text-gray-700">Payment status</span>
            <select name="payment_status" class="mt-1 rounded-lg border-gray-300 text-sm">
                <option value="">All</option>
                <?php foreach (['pending','awaiting_bank_transfer','paid','refunded','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filters['payment_status'] === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="text-xs font-medium text-gray-700">Pricing tier</span>
            <select name="pricing_tier" class="mt-1 rounded-lg border-gray-300 text-sm">
                <option value="">All</option>
                <option value="early" <?= $filters['pricing_tier'] === 'early' ? 'selected' : '' ?>>Early</option>
                <option value="late" <?= $filters['pricing_tier'] === 'late' ? 'selected' : '' ?>>Late</option>
            </select>
        </label>
        <label class="block flex-1 min-w-[200px]">
            <span class="text-xs font-medium text-gray-700">Search name / email / number</span>
            <input type="text" name="q" value="<?= e($filters['search']) ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
        </label>
        <button class="rounded-lg bg-primary text-white px-3 py-2 text-sm font-semibold">Filter</button>
        <a href="?tab=submissions&event_id=<?= $selectedEventId ?>&export=csv<?= $filters['payment_status'] ? '&payment_status=' . urlencode($filters['payment_status']) : '' ?><?= $filters['pricing_tier'] ? '&pricing_tier=' . urlencode($filters['pricing_tier']) : '' ?><?= $filters['search'] ? '&q=' . urlencode($filters['search']) : '' ?>" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50">Export CSV</a>
    </form>

    <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b border-gray-200 bg-gray-50">
                    <th class="px-4 py-3">Number</th>
                    <th class="px-4 py-3">Attendees</th>
                    <th class="px-4 py-3">Chapter</th>
                    <th class="px-4 py-3">Tier</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3">Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$registrations): ?>
                    <tr><td class="px-4 py-6 text-gray-500" colspan="7">No registrations match the filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($registrations as $reg): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 cursor-pointer" onclick="window.location='?tab=submissions&event_id=<?= $selectedEventId ?>&view=<?= (int) $reg['id'] ?>'">
                        <td class="px-4 py-3 font-mono text-xs"><?= e($reg['registration_number']) ?></td>
                        <td class="px-4 py-3"><?= e($reg['attendee1_name']) ?><?php if (!empty($reg['attendee2_name'])): ?> &amp; <?= e($reg['attendee2_name']) ?><?php endif; ?><div class="text-xs text-gray-500"><?= e($reg['email']) ?></div></td>
                        <td class="px-4 py-3 text-gray-700"><?= e($reg['chapter']) ?></td>
                        <td class="px-4 py-3 text-xs"><?= e($reg['pricing_tier']) ?></td>
                        <td class="px-4 py-3"><span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium <?= ($reg['payment_status'] === 'paid') ? 'bg-green-100 text-green-800' : (($reg['payment_status'] === 'cancelled' || $reg['payment_status'] === 'refunded') ? 'bg-gray-200 text-gray-700' : 'bg-amber-100 text-amber-800') ?>"><?= e(str_replace('_', ' ', $reg['payment_status'])) ?></span></td>
                        <td class="px-4 py-3 text-right">A$<?= number_format((float) $reg['total'], 2) ?></td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= e(date('j M Y H:i', strtotime((string) $reg['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
