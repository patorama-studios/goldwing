<?php
use App\Services\Csrf;

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM store_discounts WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'message' => 'Invalid CSRF token.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_discount') {
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $type = $_POST['type'] ?? 'percent';
            $value = (float) ($_POST['value'] ?? 0);
            $startDate = trim($_POST['start_date'] ?? '') ?: null;
            $endDate = trim($_POST['end_date'] ?? '') ?: null;
            $maxUses = trim($_POST['max_uses'] ?? '');
            $minSpend = trim($_POST['min_spend'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $discountId = (int) ($_POST['discount_id'] ?? 0);

            if ($code === '' || !in_array($type, ['percent', 'fixed'], true)) {
                $alerts[] = ['type' => 'error', 'message' => 'Code and type are required.'];
            } else {
                $stmt = $pdo->prepare('SELECT id FROM store_discounts WHERE code = :code AND id != :id LIMIT 1');
                $stmt->execute(['code' => $code, 'id' => $discountId]);
                if ($stmt->fetch()) {
                    $alerts[] = ['type' => 'error', 'message' => 'Discount code already exists.'];
                } else {
                    $payload = [
                        'code' => $code,
                        'type' => $type,
                        'value' => max(0.0, $value),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'max_uses' => $maxUses !== '' ? (int) $maxUses : null,
                        'min_spend' => $minSpend !== '' ? (float) $minSpend : null,
                        'is_active' => $isActive,
                    ];
                    if ($discountId > 0) {
                        $payload['id'] = $discountId;
                        $stmt = $pdo->prepare('UPDATE store_discounts SET code = :code, type = :type, value = :value, start_date = :start_date, end_date = :end_date, max_uses = :max_uses, min_spend = :min_spend, is_active = :is_active, updated_at = NOW() WHERE id = :id');
                        $stmt->execute($payload);
                        $alerts[] = ['type' => 'success', 'message' => 'Discount updated.'];
                        $editing = null;
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO store_discounts (code, type, value, start_date, end_date, max_uses, min_spend, is_active, created_at) VALUES (:code, :type, :value, :start_date, :end_date, :max_uses, :min_spend, :is_active, NOW())');
                        $stmt->execute($payload);
                        $alerts[] = ['type' => 'success', 'message' => 'Discount created.'];
                    }
                }
            }
        }
        if ($action === 'delete_discount') {
            $discountId = (int) ($_POST['discount_id'] ?? 0);
            if ($discountId > 0) {
                $stmt = $pdo->prepare('DELETE FROM store_discounts WHERE id = :id');
                $stmt->execute(['id' => $discountId]);
                $alerts[] = ['type' => 'success', 'message' => 'Discount removed.'];
            }
        }
    }
}

$discounts = $pdo->query('SELECT * FROM store_discounts ORDER BY created_at DESC')->fetchAll();
$pageSubtitle = 'Create discounts that apply to product subtotal only.';
?>
<section class="grid gap-6 lg:grid-cols-[1.2fr_2fr]">
  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
    <h2 class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 mb-4">
      <?= $editing ? 'Edit discount' : 'Add discount' ?>
    </h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="save_discount">
      <?php if ($editing): ?>
        <input type="hidden" name="discount_id" value="<?= e((string) $editing['id']) ?>">
      <?php endif; ?>
      <div>
        <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Code</label>
        <input name="code" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($editing['code'] ?? '') ?>" required>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Type</label>
          <select name="type" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
            <option value="percent" <?= ($editing['type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option>
            <option value="fixed" <?= ($editing['type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed</option>
          </select>
        </div>
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Value</label>
          <input name="value" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($editing['value'] ?? '')) ?>" required>
        </div>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Start date</label>
          <input name="start_date" type="date" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($editing['start_date'] ?? '') ?>">
        </div>
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">End date</label>
          <input name="end_date" type="date" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e($editing['end_date'] ?? '') ?>">
        </div>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Max uses</label>
          <input name="max_uses" type="number" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($editing['max_uses'] ?? '')) ?>">
        </div>
        <div>
          <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Min spend</label>
          <input name="min_spend" type="number" step="0.01" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) ($editing['min_spend'] ?? '')) ?>">
        </div>
      </div>
      <label class="flex items-center gap-3 text-sm text-slate-600">
        <input type="checkbox" name="is_active" class="rounded border-gray-200" <?= !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : '' ?>>
        Active
      </label>
      <button type="submit" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-ink">
        <?= $editing ? 'Update discount' : 'Create discount' ?>
      </button>
      <?php if ($editing): ?>
        <a href="/admin/store/discounts" class="block text-center text-sm text-slate-500">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Discounts</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-left text-xs uppercase text-gray-500 border-b">
          <tr>
            <th class="py-2 pr-3">Code</th>
            <th class="py-2 pr-3">Type</th>
            <th class="py-2 pr-3">Value</th>
            <th class="py-2 pr-3">Uses</th>
            <th class="py-2 pr-3">Active</th>
            <th class="py-2">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach ($discounts as $discount): ?>
            <tr>
              <td class="py-2 pr-3 text-gray-900 font-medium"><?= e($discount['code']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e($discount['type']) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e(store_money((float) $discount['value'])) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= e((string) $discount['used_count']) ?>/<?= e((string) ($discount['max_uses'] ?? 'Unlimited')) ?></td>
              <td class="py-2 pr-3 text-gray-600"><?= $discount['is_active'] ? 'Yes' : 'No' ?></td>
              <td class="py-2 flex items-center gap-2">
                <a class="text-sm text-blue-600" href="/admin/store/discounts?edit=<?= e((string) $discount['id']) ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Delete this discount?');">
                  <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
                  <input type="hidden" name="action" value="delete_discount">
                  <input type="hidden" name="discount_id" value="<?= e((string) $discount['id']) ?>">
                  <button class="text-sm text-red-600" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$discounts): ?>
            <tr>
              <td colspan="6" class="py-4 text-center text-gray-500">No discounts yet.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
