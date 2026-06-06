<?php
use App\Services\Csrf;

$csrf = Csrf::token();
$editing = $selectedEvent;
$isCreating = !$editing;
$defaults = $editing ?? [
    'year' => (int) date('Y') + 1,
    'slug' => '',
    'title' => '',
    'subtitle' => '',
    'hosting_chapter' => '',
    'venue_name' => '',
    'venue_address' => '',
    'venue_phone' => '',
    'start_date' => '',
    'end_date' => '',
    'registration_opens_at' => '',
    'registration_closes_at' => '',
    'late_fee_starts_at' => '',
    'contact_name' => '',
    'contact_phone' => '',
    'contact_email' => '',
    'bank_transfer_instructions' => '',
    'allow_bank_transfer' => 1,
    'allow_stripe' => 1,
    'status' => 'draft',
];
?>
<div class="rounded-2xl border border-gray-200 bg-white p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-display text-lg font-semibold text-gray-900"><?= $isCreating ? 'Create new AGM event' : 'Edit ' . e($editing['title']) ?></h2>
        <?php if (!$isCreating): ?>
            <div class="flex gap-2">
                <a href="?tab=event" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">+ New event</a>
                <?php if (($editing['status'] ?? '') !== 'published'): ?>
                    <form method="post" action="/admin/agm/actions.php" onsubmit="return confirm('Publish this event and set it as the current AGM? This will make it visible on the public site.')">
                        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="publish_event">
                        <input type="hidden" name="event_id" value="<?= (int) $editing['id'] ?>">
                        <button class="rounded-lg bg-primary text-white px-3 py-1.5 text-sm font-semibold">Publish &amp; set current</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="post" action="/admin/agm/actions.php" class="space-y-6">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="<?= $isCreating ? 'create_event' : 'update_event' ?>">
        <input type="hidden" name="tab" value="event">
        <?php if (!$isCreating): ?>
            <input type="hidden" name="event_id" value="<?= (int) $editing['id'] ?>">
        <?php endif; ?>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Basics</legend>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Year *</span>
                    <input type="number" name="year" required min="2024" max="2099" value="<?= e((string) $defaults['year']) ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Slug *</span>
                    <input type="text" name="slug" required pattern="[a-z0-9-]+" value="<?= e($defaults['slug']) ?>" placeholder="perth-2026" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Status</span>
                    <select name="status" class="mt-1 block w-full rounded-lg border-gray-300">
                        <?php foreach (['draft','published','closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $defaults['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Title *</span>
                <input type="text" name="title" required value="<?= e($defaults['title']) ?>" placeholder="Perth AGM 2026" class="mt-1 block w-full rounded-lg border-gray-300">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Subtitle</span>
                <input type="text" name="subtitle" value="<?= e($defaults['subtitle'] ?? '') ?>" placeholder="Friday 1st May to Sunday 3rd May 2026" class="mt-1 block w-full rounded-lg border-gray-300">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Hosting chapter</span>
                <input type="text" name="hosting_chapter" value="<?= e($defaults['hosting_chapter'] ?? '') ?>" placeholder="Perth Chapter" class="mt-1 block w-full rounded-lg border-gray-300">
            </label>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Venue</legend>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Venue name</span>
                    <input type="text" name="venue_name" value="<?= e($defaults['venue_name'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Venue phone</span>
                    <input type="text" name="venue_phone" value="<?= e($defaults['venue_phone'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
            </div>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Venue address</span>
                <input type="text" name="venue_address" value="<?= e($defaults['venue_address'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
            </label>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Dates &amp; deadlines</legend>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Event start date</span>
                    <input type="date" name="start_date" value="<?= e($defaults['start_date'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Event end date</span>
                    <input type="date" name="end_date" value="<?= e($defaults['end_date'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Registration opens at</span>
                    <input type="datetime-local" name="registration_opens_at" value="<?= e(str_replace(' ', 'T', substr((string) ($defaults['registration_opens_at'] ?? ''), 0, 16))) ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Registration closes at</span>
                    <input type="datetime-local" name="registration_closes_at" value="<?= e(str_replace(' ', 'T', substr((string) ($defaults['registration_closes_at'] ?? ''), 0, 16))) ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-sm font-medium text-gray-700">Late fee starts at</span>
                    <span class="block text-xs text-gray-500 mb-1">After this datetime, registrations are priced at the "late" tier from the products list.</span>
                    <input type="datetime-local" name="late_fee_starts_at" value="<?= e(str_replace(' ', 'T', substr((string) ($defaults['late_fee_starts_at'] ?? ''), 0, 16))) ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Contact &amp; payment options</legend>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Contact name</span>
                    <input type="text" name="contact_name" value="<?= e($defaults['contact_name'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Contact phone</span>
                    <input type="text" name="contact_phone" value="<?= e($defaults['contact_phone'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Contact email</span>
                    <input type="email" name="contact_email" value="<?= e($defaults['contact_email'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300">
                </label>
            </div>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Bank transfer instructions</span>
                <span class="block text-xs text-gray-500 mb-1">Shown to attendees who pick the bank-transfer option. Include BSB, account name, reference format, etc.</span>
                <textarea name="bank_transfer_instructions" rows="4" class="mt-1 block w-full rounded-lg border-gray-300"><?= e($defaults['bank_transfer_instructions'] ?? '') ?></textarea>
            </label>
            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="allow_stripe" value="1" <?= !empty($defaults['allow_stripe']) ? 'checked' : '' ?>>
                    Accept Stripe card payments
                </label>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="allow_bank_transfer" value="1" <?= !empty($defaults['allow_bank_transfer']) ? 'checked' : '' ?>>
                    Accept bank transfer
                </label>
            </div>
        </fieldset>

        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
            <?php if (!$isCreating): ?>
                <form method="post" action="/admin/agm/actions.php" class="inline" onsubmit="return confirm('Archive this AGM event? Past registrations remain visible in the Archive tab.')">
                    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="archive_event">
                    <input type="hidden" name="event_id" value="<?= (int) $editing['id'] ?>">
                    <button class="rounded-lg border border-red-300 text-red-700 px-4 py-2 text-sm font-medium hover:bg-red-50">Archive event</button>
                </form>
            <?php endif; ?>
            <button class="rounded-lg bg-primary text-white px-4 py-2 text-sm font-semibold"><?= $isCreating ? 'Create event' : 'Save event' ?></button>
        </div>
    </form>
</div>
