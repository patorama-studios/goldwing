<?php
use App\Services\AgmEventService;
use App\Services\Csrf;

$csrf = Csrf::token();
if (!$selectedEvent) {
    return;
}

$fields = AgmEventService::getFormFields((int) $selectedEvent['id']);
$editingFieldId = isset($_GET['edit_field']) ? (int) $_GET['edit_field'] : 0;
$editingField = null;
foreach ($fields as $f) {
    if ((int) $f['id'] === $editingFieldId) {
        $editingField = $f;
        break;
    }
}
$defaults = $editingField ?? [
    'field_key' => '',
    'label' => '',
    'helper_text' => '',
    'field_group' => 'other',
    'field_type' => 'text',
    'options_json' => null,
    'is_required' => 0,
    'sort_order' => 0,
    'is_active' => 1,
];
$optionsText = '';
if (!empty($defaults['options_json'])) {
    $arr = json_decode($defaults['options_json'], true);
    if (is_array($arr)) {
        $optionsText = implode("\n", $arr);
    }
}
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-500 mb-4">Add extra questions specific to this AGM (e.g. "Sunday social ride interest", "Raffle ticket quantity"). Baseline fields (name, address, bikes, emergency contacts, dietary) are part of the standard form and don't need to be defined here.</p>
            <?php if (!$fields): ?>
                <p class="text-sm text-gray-500">No custom fields yet for this event.</p>
            <?php else: ?>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200">
                            <th class="py-2">Key</th>
                            <th class="py-2">Label</th>
                            <th class="py-2">Type</th>
                            <th class="py-2">Group</th>
                            <th class="py-2">Flags</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fields as $f): ?>
                            <tr class="border-b border-gray-100 <?= !(int) $f['is_active'] ? 'opacity-50' : '' ?>">
                                <td class="py-2 font-mono text-xs"><?= e($f['field_key']) ?></td>
                                <td class="py-2"><?= e($f['label']) ?><?php if (!empty($f['helper_text'])): ?><div class="text-xs text-gray-500"><?= e($f['helper_text']) ?></div><?php endif; ?></td>
                                <td class="py-2"><?= e($f['field_type']) ?></td>
                                <td class="py-2"><?= e($f['field_group']) ?></td>
                                <td class="py-2 text-xs"><?= (int) $f['is_required'] ? 'required' : 'optional' ?></td>
                                <td class="py-2 text-right">
                                    <a href="?tab=fields&event_id=<?= $selectedEventId ?>&edit_field=<?= (int) $f['id'] ?>" class="text-primary text-xs hover:underline">edit</a>
                                    <form method="post" action="/admin/agm/actions.php" class="inline" onsubmit="return confirm('Delete this field?')">
                                        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_field">
                                        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                                        <input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>">
                                        <button class="text-red-600 text-xs hover:underline ml-2">delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg:col-span-1">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 sticky top-4">
            <h3 class="font-display text-base font-semibold text-gray-900 mb-3"><?= $editingField ? 'Edit field' : 'Add field' ?></h3>
            <form method="post" action="/admin/agm/actions.php" class="space-y-3">
                <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_field">
                <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                <?php if ($editingField): ?>
                    <input type="hidden" name="field_id" value="<?= (int) $editingField['id'] ?>">
                <?php endif; ?>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Field key *</span>
                    <span class="block text-xs text-gray-500">Lowercase, no spaces. e.g. <code>social_ride_interest</code></span>
                    <input type="text" name="field_key" required pattern="[a-z0-9_]+" value="<?= e($defaults['field_key']) ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Label *</span>
                    <input type="text" name="label" required value="<?= e($defaults['label']) ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Helper text</span>
                    <input type="text" name="helper_text" value="<?= e($defaults['helper_text'] ?? '') ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Type</span>
                        <select name="field_type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                            <?php foreach (['text','number','checkbox','select','textarea'] as $t): ?>
                                <option value="<?= $t ?>" <?= $defaults['field_type'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Group</span>
                        <select name="field_group" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                            <?php foreach (['personal','bike','emergency','other'] as $g): ?>
                                <option value="<?= $g ?>" <?= $defaults['field_group'] === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Options (one per line)</span>
                    <span class="block text-xs text-gray-500">For "select" type only.</span>
                    <textarea name="options_text" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono"><?= e($optionsText) ?></textarea>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Sort order</span>
                    <input type="number" name="sort_order" value="<?= (int) $defaults['sort_order'] ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                </label>
                <div class="space-y-2 text-sm">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_required" value="1" <?= !empty($defaults['is_required']) ? 'checked' : '' ?>> Required</label><br>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= !empty($defaults['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>
                <div class="pt-3 flex gap-2">
                    <?php if ($editingField): ?>
                        <a href="?tab=fields&event_id=<?= $selectedEventId ?>" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium">Cancel</a>
                    <?php endif; ?>
                    <button class="flex-1 rounded-lg bg-primary text-white px-3 py-1.5 text-sm font-semibold"><?= $editingField ? 'Update field' : 'Add field' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
