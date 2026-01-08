<section class="bg-card-light rounded-2xl p-6 md:p-8 shadow-sm border border-gray-100 space-y-4">
  <div class="space-y-2">
    <h1 class="font-display text-3xl md:text-4xl font-bold text-gray-900"><?= e($copy['heading'] ?? 'Member of the Year Award') ?></h1>
    <p class="text-sm text-gray-500"><?= e($copy['subheading'] ?? '') ?></p>
    <p class="text-sm text-gray-700"><span class="font-semibold"><?= e($copy['trophy_name'] ?? '') ?></span></p>
    <p class="text-sm text-gray-600"><?= e($copy['description'] ?? '') ?></p>
    <p class="text-sm text-gray-500"><?= e($copy['tagline'] ?? '') ?></p>
  </div>
</section>

<section class="bg-white rounded-2xl border border-gray-200 p-6 md:p-8 shadow-sm">
  <?php if (!empty($errors)): ?>
    <div class="rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 mb-6">
      <ul class="list-disc list-inside space-y-1">
        <?php foreach ($errors as $error): ?>
          <li><?= e($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="space-y-8">
    <input type="hidden" name="csrf_token" value="<?= e(\App\Services\Csrf::token()) ?>">

    <div class="space-y-4">
      <h2 class="text-lg font-bold text-gray-900">Nominator Details</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="text-sm font-medium text-gray-700">First name <span class="text-red-500">*</span></label>
          <input type="text" name="nominator_first_name" value="<?= e($formValues['nominator_first_name'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
        </div>
        <div>
          <label class="text-sm font-medium text-gray-700">Last name <span class="text-red-500">*</span></label>
          <input type="text" name="nominator_last_name" value="<?= e($formValues['nominator_last_name'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
        </div>
      </div>
      <div>
        <label class="text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
        <input type="email" name="nominator_email" value="<?= e($formValues['nominator_email'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
      </div>
    </div>

    <div class="space-y-4">
      <h2 class="text-lg font-bold text-gray-900">Nominee Details</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="text-sm font-medium text-gray-700">First name <span class="text-red-500">*</span></label>
          <input type="text" name="nominee_first_name" value="<?= e($formValues['nominee_first_name'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
        </div>
        <div>
          <label class="text-sm font-medium text-gray-700">Last name <span class="text-red-500">*</span></label>
          <input type="text" name="nominee_last_name" value="<?= e($formValues['nominee_last_name'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
        </div>
      </div>
      <div>
        <label class="text-sm font-medium text-gray-700">Chapter <span class="text-red-500">*</span></label>
        <!-- TODO: Replace with a chapter dropdown sourced from the chapters table. -->
        <input type="text" name="nominee_chapter" value="<?= e($formValues['nominee_chapter'] ?? '') ?>" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required>
      </div>
      <div>
        <label class="text-sm font-medium text-gray-700">Details to be considered for award nomination <span class="text-red-500">*</span></label>
        <textarea name="nomination_details" rows="8" minlength="50" maxlength="3000" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-primary focus:ring-2 focus:ring-primary/20" required><?= e($formValues['nomination_details'] ?? '') ?></textarea>
        <p class="text-xs text-gray-500 mt-1">Minimum 50 characters, maximum 3000 characters.</p>
      </div>
    </div>

    <div class="flex justify-center">
      <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-primary px-6 py-2 text-sm font-semibold text-gray-900 transition hover:bg-primary/90">Submit nomination</button>
    </div>
  </form>
</section>
