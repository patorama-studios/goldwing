<?php
$title = $topbarTitle ?? 'Goldwing';
$impersonation = function_exists('impersonation_context') ? impersonation_context() : null;
$impersonationName = '';
if ($impersonation) {
    $user = $user ?? current_user();
    $impersonationName = trim((string) ($user['name'] ?? 'Member'));
    if ($impersonationName === '') {
        $impersonationName = 'Member';
    }
}
?>
<?php if ($impersonation): ?>
  <div class="md:hidden bg-amber-50 border-b border-amber-200 px-4 py-2 flex items-center justify-between gap-3">
    <form method="post" action="/member/impersonation_stop.php">
      <input type="hidden" name="csrf_token" value="<?= e(\App\Services\Csrf::token()) ?>">
      <button class="text-xs font-semibold text-amber-800 hover:text-amber-900" type="submit">
        &larr; Back to Admin
      </button>
    </form>
    <span class="text-[11px] font-medium text-amber-700">Viewing as Member: <?= e($impersonationName) ?></span>
  </div>
<?php endif; ?>
<div class="md:hidden h-16 bg-card-light flex items-center justify-between px-4 border-b border-gray-200 sticky top-0 z-30">
  <span class="font-display font-bold text-lg text-gray-900"><?= e($title) ?></span>
  <button type="button" class="inline-flex items-center justify-center rounded-full p-2 text-gray-600 hover:bg-gray-100" data-backend-nav-toggle aria-expanded="false" aria-label="Toggle navigation">
    <span class="material-icons-outlined">menu</span>
  </button>
</div>
<div class="fixed inset-0 bg-black/40 z-20 hidden md:hidden" data-backend-nav-overlay></div>
