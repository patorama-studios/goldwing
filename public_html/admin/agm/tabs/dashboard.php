<?php
use App\Services\AgmRegistrationService;

if (!$selectedEvent) {
    return;
}

$registrations = AgmRegistrationService::listForEvent((int) $selectedEvent['id']);
$totalCount = count($registrations);
$paidCount = 0;
$pendingCount = 0;
$revenuePaid = 0.0;
$revenueProjected = 0.0;
foreach ($registrations as $reg) {
    $revenueProjected += (float) $reg['total'];
    if (($reg['payment_status'] ?? '') === 'paid') {
        $paidCount++;
        $revenuePaid += (float) $reg['total'];
    } elseif (in_array($reg['payment_status'] ?? '', ['pending', 'awaiting_bank_transfer'], true)) {
        $pendingCount++;
    }
}

$now = new DateTimeImmutable('now');
$daysToClose = null;
if (!empty($selectedEvent['registration_closes_at'])) {
    $closeAt = new DateTimeImmutable((string) $selectedEvent['registration_closes_at']);
    $daysToClose = (int) $now->diff($closeAt)->format('%r%a');
}
$daysToLate = null;
if (!empty($selectedEvent['late_fee_starts_at'])) {
    $lateAt = new DateTimeImmutable((string) $selectedEvent['late_fee_starts_at']);
    $daysToLate = (int) $now->diff($lateAt)->format('%r%a');
}
?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <p class="text-xs uppercase tracking-wider text-gray-500">Total registrations</p>
        <p class="mt-2 text-3xl font-bold text-gray-900"><?= number_format($totalCount) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <p class="text-xs uppercase tracking-wider text-gray-500">Paid</p>
        <p class="mt-2 text-3xl font-bold text-green-700"><?= number_format($paidCount) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <p class="text-xs uppercase tracking-wider text-gray-500">Pending payment</p>
        <p class="mt-2 text-3xl font-bold text-amber-700"><?= number_format($pendingCount) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <p class="text-xs uppercase tracking-wider text-gray-500">Revenue (paid)</p>
        <p class="mt-2 text-3xl font-bold text-gray-900">A$<?= number_format($revenuePaid, 2) ?></p>
        <p class="text-xs text-gray-500 mt-1">Projected total A$<?= number_format($revenueProjected, 2) ?></p>
    </div>
</div>

<div class="rounded-2xl border border-gray-200 bg-white p-6">
    <h2 class="font-display text-lg font-semibold text-gray-900"><?= e($selectedEvent['title']) ?></h2>
    <p class="text-sm text-gray-500 mt-1">
        <?= e($selectedEvent['venue_name'] ?? '') ?> · <?= e($selectedEvent['venue_address'] ?? '') ?>
    </p>
    <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        <div>
            <dt class="text-gray-500">Status</dt>
            <dd class="font-semibold text-gray-900"><?= e(ucfirst($selectedEvent['status'])) ?></dd>
        </div>
        <div>
            <dt class="text-gray-500">Event dates</dt>
            <dd class="font-semibold text-gray-900"><?= e($selectedEvent['start_date'] ?? '?') ?> – <?= e($selectedEvent['end_date'] ?? '?') ?></dd>
        </div>
        <div>
            <dt class="text-gray-500">Registration closes</dt>
            <dd class="font-semibold text-gray-900"><?= e($selectedEvent['registration_closes_at'] ?? 'no deadline') ?><?php if ($daysToClose !== null): ?> <span class="text-xs text-gray-500 block"><?= $daysToClose >= 0 ? $daysToClose . ' days remaining' : 'closed ' . abs($daysToClose) . ' days ago' ?></span><?php endif; ?></dd>
        </div>
        <div>
            <dt class="text-gray-500">Late fee begins</dt>
            <dd class="font-semibold text-gray-900"><?= e($selectedEvent['late_fee_starts_at'] ?? 'no late fee') ?><?php if ($daysToLate !== null): ?> <span class="text-xs text-gray-500 block"><?= $daysToLate >= 0 ? 'in ' . $daysToLate . ' days' : 'late pricing active' ?></span><?php endif; ?></dd>
        </div>
    </dl>
</div>

<?php if ($registrations): ?>
    <div class="rounded-2xl border border-gray-200 bg-white p-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-display text-base font-semibold text-gray-900">Recent registrations</h3>
            <a href="?tab=submissions&event_id=<?= $selectedEventId ?>" class="text-sm text-primary hover:underline">View all submissions →</a>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b border-gray-200">
                    <th class="py-2">Number</th>
                    <th class="py-2">Attendee</th>
                    <th class="py-2">Status</th>
                    <th class="py-2 text-right">Total</th>
                    <th class="py-2">Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($registrations, 0, 10) as $reg): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-2 font-mono text-xs"><?= e($reg['registration_number']) ?></td>
                        <td class="py-2"><?= e($reg['attendee1_name']) ?><?php if (!empty($reg['attendee2_name'])): ?> &amp; <?= e($reg['attendee2_name']) ?><?php endif; ?></td>
                        <td class="py-2"><span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium <?= ($reg['payment_status'] === 'paid') ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= e(str_replace('_', ' ', $reg['payment_status'])) ?></span></td>
                        <td class="py-2 text-right">A$<?= number_format((float) $reg['total'], 2) ?></td>
                        <td class="py-2 text-gray-500 text-xs"><?= e(date('j M Y H:i', strtotime((string) $reg['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
