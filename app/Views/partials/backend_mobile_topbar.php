<?php
$title = $topbarTitle ?? 'Goldwing';
?>
<div class="md:hidden h-16 bg-card-light flex items-center justify-between px-4 border-b border-gray-200 sticky top-0 z-30">
  <span class="font-display font-bold text-lg text-gray-900"><?= e($title) ?></span>
  <button type="button" class="inline-flex items-center justify-center rounded-full p-2 text-gray-600 hover:bg-gray-100" data-backend-nav-toggle aria-expanded="false" aria-label="Toggle navigation">
    <span class="material-icons-outlined">menu</span>
  </button>
</div>
<div class="fixed inset-0 bg-black/40 z-20 hidden md:hidden" data-backend-nav-overlay></div>
