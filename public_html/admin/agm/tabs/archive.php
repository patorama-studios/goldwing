<?php
use App\Services\AgmRegistrationService;

if (!$archivedEvents) {
    ?>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center">
        <p class="text-sm text-gray-500">No archived AGM events yet. When you archive an event from the Event Setup tab, it shows here with its registrations preserved as a read-only history.</p>
    </div>
    <?php
    return;
}
?>
<div class="rounded-2xl border border-gray-200 bg-white p-6">
    <h2 class="font-display text-lg font-semibold text-gray-900 mb-4">Archived events</h2>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b border-gray-200">
                <th class="py-2">Year</th>
                <th class="py-2">Title</th>
                <th class="py-2">Venue</th>
                <th class="py-2 text-right">Registrations</th>
                <th class="py-2 text-right">Revenue</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($archivedEvents as $ev): ?>
                <?php
                    $regs = AgmRegistrationService::listForEvent((int) $ev['id']);
                    $revenue = 0.0;
                    foreach ($regs as $r) {
                        if (($r['payment_status'] ?? '') === 'paid') {
                            $revenue += (float) $r['total'];
                        }
                    }
                ?>
                <tr class="border-b border-gray-100">
                    <td class="py-2"><?= (int) $ev['year'] ?></td>
                    <td class="py-2"><?= e($ev['title']) ?></td>
                    <td class="py-2 text-gray-500"><?= e($ev['venue_name'] ?? '') ?></td>
                    <td class="py-2 text-right"><?= count($regs) ?></td>
                    <td class="py-2 text-right">A$<?= number_format($revenue, 2) ?></td>
                    <td class="py-2 text-right"><a href="?tab=submissions&event_id=<?= (int) $ev['id'] ?>" class="text-primary text-xs hover:underline">View submissions →</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
