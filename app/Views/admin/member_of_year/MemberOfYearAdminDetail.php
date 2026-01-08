<?php
$nominatorName = trim(($nomination['nominator_first_name'] ?? '') . ' ' . ($nomination['nominator_last_name'] ?? ''));
$nomineeName = trim(($nomination['nominee_first_name'] ?? '') . ' ' . ($nomination['nominee_last_name'] ?? ''));
$statusValue = strtolower((string) ($nomination['status'] ?? 'new'));
$submittedByLabel = 'N/A';
if (!empty($submittedByUser)) {
    $submittedByLabel = 'User #' . (int) ($submittedByUser['id'] ?? 0) . ' ' . trim(($submittedByUser['name'] ?? '') . ' (' . ($submittedByUser['email'] ?? '') . ')');
} elseif (!empty($nomination['submitted_by_user_id'])) {
    $submittedByLabel = 'User #' . (int) $nomination['submitted_by_user_id'];
}
?>

<div class="flex items-center justify-between gap-4">
  <div>
    <a class="text-xs font-bold tracking-widest text-gray-400 uppercase inline-flex items-center gap-2 mb-3 hover:text-gray-500 transition-colors" href="/admin/member-of-the-year">
      <span class="material-icons-outlined text-base">arrow_back</span>
      Back to submissions
    </a>
    <h1 class="text-2xl font-bold text-gray-900">Submission #<?= e((string) $nomination['id']) ?></h1>
    <p class="text-sm text-gray-500">Member of the Year nomination details.</p>
  </div>
  <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= status_badge_classes($statusValue) ?>"><?= e(ucfirst($statusValue)) ?></span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm space-y-4">
    <h2 class="text-lg font-bold text-gray-900">Nominator</h2>
    <div class="space-y-2 text-sm text-gray-700">
      <p><span class="font-semibold">Name:</span> <?= e($nominatorName !== '' ? $nominatorName : 'N/A') ?></p>
      <p><span class="font-semibold">Email:</span> <?= e($nomination['nominator_email'] ?? 'N/A') ?></p>
    </div>
  </section>
  <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm space-y-4">
    <h2 class="text-lg font-bold text-gray-900">Nominee</h2>
    <div class="space-y-2 text-sm text-gray-700">
      <p><span class="font-semibold">Name:</span> <?= e($nomineeName !== '' ? $nomineeName : 'N/A') ?></p>
      <p><span class="font-semibold">Chapter:</span> <?= e($nomination['nominee_chapter'] ?? 'N/A') ?></p>
    </div>
  </section>
</div>

<section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm space-y-4">
  <h2 class="text-lg font-bold text-gray-900">Nomination details</h2>
  <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-700">
    <?= nl2br(e($nomination['nomination_details'] ?? '')) ?>
  </div>
</section>

<section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm space-y-4">
  <h2 class="text-lg font-bold text-gray-900">Submission metadata</h2>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-700">
    <p><span class="font-semibold">Year:</span> <?= e((string) $nomination['submission_year']) ?></p>
    <p><span class="font-semibold">Submitted at:</span> <?= e(format_datetime($nomination['submitted_at'] ?? null)) ?></p>
    <p><span class="font-semibold">Submitted by:</span> <?= e($submittedByLabel) ?></p>
    <p><span class="font-semibold">IP address:</span> <?= e($nomination['ip_address'] ?? 'N/A') ?></p>
    <p class="md:col-span-2"><span class="font-semibold">User agent:</span> <?= e($nomination['user_agent'] ?? 'N/A') ?></p>
  </div>
</section>

<section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm space-y-4">
  <h2 class="text-lg font-bold text-gray-900">Admin review</h2>
  <form method="post" action="/admin/member-of-the-year/actions.php" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= e(\App\Services\Csrf::token()) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= e((string) $nomination['id']) ?>">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <label class="text-sm font-medium text-gray-700">
        Status
        <select name="status" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
          <?php foreach ($statusOptions as $statusOption): ?>
            <option value="<?= e($statusOption) ?>" <?= $statusOption === $statusValue ? 'selected' : '' ?>><?= e(ucfirst($statusOption)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label class="text-sm font-medium text-gray-700">
      Admin notes
      <textarea name="admin_notes" rows="6" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20"><?= e($nomination['admin_notes'] ?? '') ?></textarea>
    </label>
    <div class="flex items-center gap-2">
      <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900 transition hover:bg-primary/80">Save changes</button>
    </div>
  </form>
</section>
