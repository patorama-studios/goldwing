<section class="bg-white rounded-2xl border border-gray-200 p-8 shadow-sm">
  <div class="flex flex-col items-center text-center gap-3">
    <div class="h-12 w-12 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center">
      <span class="material-icons-outlined">check_circle</span>
    </div>
    <h2 class="text-2xl font-bold text-gray-900">Submission received</h2>
    <p class="text-gray-600"><?= e($copy['success_message'] ?? 'Thanks - your nomination has been submitted.') ?></p>
    <a class="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50" href="/member/index.php">Back to dashboard</a>
  </div>
</section>
