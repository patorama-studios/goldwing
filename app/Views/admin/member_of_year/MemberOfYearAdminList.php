<section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
  <form method="get" class="p-6 border-b border-gray-100 space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <label class="text-sm font-medium text-gray-700">
        Year
        <select name="year" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
          <option value="all" <?= $filters['year'] === 'all' ? 'selected' : '' ?>>All</option>
          <?php foreach ($yearOptions as $yearOption): ?>
            <option value="<?= e((string) $yearOption) ?>" <?= (string) $filters['year'] === (string) $yearOption ? 'selected' : '' ?>><?= e((string) $yearOption) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm font-medium text-gray-700">
        Status
        <select name="status" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
          <?php foreach ($statusOptions as $statusOption): ?>
            <option value="<?= e($statusOption) ?>" <?= $filters['status'] === $statusOption ? 'selected' : '' ?>><?= e($statusOption === 'all' ? 'All' : ucfirst($statusOption)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm font-medium text-gray-700 md:col-span-2">
        Search
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nominator, nominee, chapter" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20">
      </label>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-gray-900 transition hover:bg-primary/80">Apply filters</button>
      <a href="/admin/member-of-the-year" class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700">Reset</a>
      <!-- TODO: Add Export CSV action. -->
    </div>
  </form>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="text-left text-xs uppercase text-gray-500 border-b">
        <tr>
          <th class="py-3 px-4">ID</th>
          <th class="py-3 px-4">Year</th>
          <th class="py-3 px-4">Submitted</th>
          <th class="py-3 px-4">Nominator</th>
          <th class="py-3 px-4">Nominator Email</th>
          <th class="py-3 px-4">Nominee</th>
          <th class="py-3 px-4">Chapter</th>
          <th class="py-3 px-4">Status</th>
          <th class="py-3 px-4 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php if (!$nominations): ?>
          <tr>
            <td class="py-6 px-4 text-center text-gray-500" colspan="9">No nominations found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($nominations as $nomination): ?>
            <?php
              $nominatorName = trim(($nomination['nominator_first_name'] ?? '') . ' ' . ($nomination['nominator_last_name'] ?? ''));
              $nomineeName = trim(($nomination['nominee_first_name'] ?? '') . ' ' . ($nomination['nominee_last_name'] ?? ''));
              $status = strtolower((string) ($nomination['status'] ?? 'new'));
            ?>
            <tr>
              <td class="py-3 px-4 text-gray-700">#<?= e((string) $nomination['id']) ?></td>
              <td class="py-3 px-4 text-gray-700"><?= e((string) $nomination['submission_year']) ?></td>
              <td class="py-3 px-4 text-gray-600"><?= e(format_datetime($nomination['submitted_at'] ?? null)) ?></td>
              <td class="py-3 px-4 text-gray-700"><?= e($nominatorName !== '' ? $nominatorName : 'N/A') ?></td>
              <td class="py-3 px-4 text-gray-600"><?= e($nomination['nominator_email'] ?? 'N/A') ?></td>
              <td class="py-3 px-4 text-gray-700"><?= e($nomineeName !== '' ? $nomineeName : 'N/A') ?></td>
              <td class="py-3 px-4 text-gray-600"><?= e($nomination['nominee_chapter'] ?? 'N/A') ?></td>
              <td class="py-3 px-4">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= status_badge_classes($status) ?>"><?= e(ucfirst($status)) ?></span>
              </td>
              <td class="py-3 px-4 text-right">
                <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700 hover:border-gray-300" href="/admin/member-of-the-year/view.php?id=<?= e((int) $nomination['id']) ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-6 py-4 border-t border-gray-100 text-sm text-gray-600">
      <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
      <div class="flex items-center gap-2">
        <?php if ($page > 1): ?>
          <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" href="/admin/member-of-the-year?<?= e(buildQuery(['page' => $page - 1])) ?>">&larr; Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="inline-flex items-center gap-1 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700" href="/admin/member-of-the-year?<?= e(buildQuery(['page' => $page + 1])) ?>">Next &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
