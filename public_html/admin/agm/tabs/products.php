<?php
use App\Services\AgmEventService;
use App\Services\Csrf;

$csrf = Csrf::token();
if (!$selectedEvent) {
    return;
}

$products = AgmEventService::getProducts((int) $selectedEvent['id']);
$editingProductId = isset($_GET['edit_product']) ? (int) $_GET['edit_product'] : 0;
$editingProduct = null;
foreach ($products as $p) {
    if ((int) $p['id'] === $editingProductId) {
        $editingProduct = $p;
        break;
    }
}
$defaults = $editingProduct ?? [
    'category' => 'registration',
    'name' => '',
    'description' => '',
    'early_price' => '',
    'late_price' => '',
    'member_only' => 0,
    'non_member_only' => 0,
    'requires_choice' => 0,
    'choices_json' => null,
    'quantity_limit' => '',
    'per_registration_limit' => '',
    'sort_order' => 0,
    'is_active' => 1,
];
$choicesText = '';
if (!empty($defaults['choices_json'])) {
    $arr = json_decode($defaults['choices_json'], true);
    if (is_array($arr)) {
        $choicesText = implode("\n", $arr);
    }
}

$grouped = ['registration' => [], 'merchandise' => [], 'meal' => [], 'custom' => []];
foreach ($products as $p) {
    $grouped[$p['category']][] = $p;
}

$siblingEvents = array_filter($events, fn($e) => (int) $e['id'] !== (int) $selectedEvent['id']);
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <?php foreach ($grouped as $category => $rows): ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-6">
                <h3 class="font-display text-base font-semibold text-gray-900 mb-3"><?= e(ucfirst($category)) ?></h3>
                <?php if (!$rows): ?>
                    <p class="text-sm text-gray-500">No <?= e($category) ?> items yet.</p>
                <?php else: ?>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b border-gray-200">
                                <th class="py-2">Name</th>
                                <th class="py-2 text-right">Early</th>
                                <th class="py-2 text-right">Late</th>
                                <th class="py-2">Flags</th>
                                <th class="py-2 text-right">Order</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $p): ?>
                                <tr class="border-b border-gray-100 <?= !(int) $p['is_active'] ? 'opacity-50' : '' ?>">
                                    <td class="py-2">
                                        <div class="font-medium text-gray-900"><?= e($p['name']) ?></div>
                                        <?php if (!empty($p['description'])): ?><div class="text-xs text-gray-500"><?= e($p['description']) ?></div><?php endif; ?>
                                        <?php if (!empty($p['choices_json'])): $cs = json_decode($p['choices_json'], true) ?: []; ?>
                                            <div class="text-xs text-gray-500 mt-1">Choices: <?= e(implode(', ', $cs)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 text-right">A$<?= number_format((float) $p['early_price'], 2) ?></td>
                                    <td class="py-2 text-right"><?= $p['late_price'] !== null ? 'A$' . number_format((float) $p['late_price'], 2) : '—' ?></td>
                                    <td class="py-2 text-xs text-gray-600">
                                        <?= (int) $p['member_only'] ? '<span class="inline-block rounded bg-blue-100 text-blue-800 px-1.5 mr-1">member</span>' : '' ?>
                                        <?= (int) $p['non_member_only'] ? '<span class="inline-block rounded bg-purple-100 text-purple-800 px-1.5 mr-1">non-member</span>' : '' ?>
                                        <?= !(int) $p['is_active'] ? '<span class="inline-block rounded bg-gray-100 text-gray-800 px-1.5 mr-1">inactive</span>' : '' ?>
                                    </td>
                                    <td class="py-2 text-right text-gray-500"><?= (int) $p['sort_order'] ?></td>
                                    <td class="py-2 text-right">
                                        <a href="?tab=products&event_id=<?= $selectedEventId ?>&edit_product=<?= (int) $p['id'] ?>" class="text-primary text-xs hover:underline">edit</a>
                                        <form method="post" action="/admin/agm/actions.php" class="inline" onsubmit="return confirm('Delete this product? Existing registration items snapshot the data so historical orders are unaffected.')">
                                            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                                            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                            <button class="text-red-600 text-xs hover:underline ml-2">delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($siblingEvents): ?>
            <form method="post" action="/admin/agm/actions.php" class="rounded-2xl border border-blue-200 bg-blue-50 p-4 flex flex-wrap items-center gap-3" onsubmit="return confirm('Clone all products and form fields from the selected event into this one?')">
                <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="clone_from_previous">
                <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                <span class="text-sm text-blue-900 font-medium">Clone products &amp; fields from:</span>
                <select name="source_event_id" class="rounded-lg border-blue-300 text-sm">
                    <?php foreach ($siblingEvents as $e): ?>
                        <option value="<?= (int) $e['id'] ?>"><?= e($e['year'] . ' — ' . $e['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="rounded-lg bg-blue-600 text-white px-3 py-1.5 text-sm font-semibold">Clone</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-1">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 sticky top-4">
            <h3 class="font-display text-base font-semibold text-gray-900 mb-3"><?= $editingProduct ? 'Edit product' : 'Add product' ?></h3>
            <form method="post" action="/admin/agm/actions.php" class="space-y-3">
                <input type="hidden" name="_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                <?php if ($editingProduct): ?>
                    <input type="hidden" name="product_id" value="<?= (int) $editingProduct['id'] ?>">
                <?php endif; ?>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Category</span>
                    <select name="category" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                        <?php foreach (['registration','merchandise','meal','custom'] as $c): ?>
                            <option value="<?= $c ?>" <?= $defaults['category'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Name *</span>
                    <input type="text" name="name" required value="<?= e($defaults['name']) ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Description</span>
                    <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm"><?= e($defaults['description'] ?? '') ?></textarea>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Early price (AUD)</span>
                        <input type="number" step="0.01" name="early_price" value="<?= e((string) $defaults['early_price']) ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Late price</span>
                        <input type="number" step="0.01" name="late_price" value="<?= e((string) ($defaults['late_price'] ?? '')) ?>" placeholder="optional" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Choices (one per line)</span>
                    <span class="block text-xs text-gray-500">For e.g. Friday-dinner pasta options. Leave blank if not applicable.</span>
                    <textarea name="choices_text" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono"><?= e($choicesText) ?></textarea>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Total qty cap</span>
                        <input type="number" name="quantity_limit" value="<?= e((string) ($defaults['quantity_limit'] ?? '')) ?>" placeholder="optional" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Per-registration cap</span>
                        <input type="number" name="per_registration_limit" value="<?= e((string) ($defaults['per_registration_limit'] ?? '')) ?>" placeholder="optional" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Sort order</span>
                    <input type="number" name="sort_order" value="<?= (int) $defaults['sort_order'] ?>" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                </label>
                <div class="space-y-2 text-sm">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="requires_choice" value="1" <?= !empty($defaults['requires_choice']) ? 'checked' : '' ?>> Requires a choice</label><br>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="member_only" value="1" <?= !empty($defaults['member_only']) ? 'checked' : '' ?>> Members only</label><br>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="non_member_only" value="1" <?= !empty($defaults['non_member_only']) ? 'checked' : '' ?>> Non-members only</label><br>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= !empty($defaults['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>
                <div class="pt-3 flex gap-2">
                    <?php if ($editingProduct): ?>
                        <a href="?tab=products&event_id=<?= $selectedEventId ?>" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium">Cancel</a>
                    <?php endif; ?>
                    <button class="flex-1 rounded-lg bg-primary text-white px-3 py-1.5 text-sm font-semibold"><?= $editingProduct ? 'Update product' : 'Add product' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
