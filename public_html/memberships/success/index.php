<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\BaseUrlService;
use App\Services\SettingsService;

$user = current_user();
$stripeRedirectStatus = isset($_GET['redirect_status']) ? (string) $_GET['redirect_status'] : '';
$paymentFailed = $stripeRedirectStatus === 'failed' || $stripeRedirectStatus === 'requires_payment_method';
$awaitingWebhook = !$paymentFailed && $stripeRedirectStatus !== 'succeeded' && $stripeRedirectStatus !== '';

$mainSiteUrl = BaseUrlService::configuredBaseUrl();
if ($mainSiteUrl === '') {
    $mainSiteUrl = '/';
}
$siteName = SettingsService::getGlobal('site.name', 'Australian Goldwing Association');

$applicantName = '';
if ($user) {
    $applicantName = trim((string) ($user['name'] ?? ''));
}
if ($applicantName === '' && !empty($_SESSION['membership_apply']['first_name'])) {
    $applicantName = trim((string) $_SESSION['membership_apply']['first_name']);
}

$pageTitle = $paymentFailed ? 'Payment unsuccessful' : 'Welcome to the Association';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<main class="min-h-screen bg-background-light flex items-center justify-center px-4 py-10">
  <div class="w-full max-w-2xl space-y-6">

    <section class="bg-card-light rounded-2xl shadow-card border border-gray-100 p-8 md:p-12 text-center">
      <?php if ($paymentFailed): ?>
        <div class="mx-auto w-24 h-24 rounded-full bg-red-50 flex items-center justify-center mb-6">
          <span class="material-icons-outlined text-6xl text-red-500">error_outline</span>
        </div>
        <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900">Payment unsuccessful</h1>
        <p class="text-gray-500 mt-3 max-w-md mx-auto">Your card was declined or the payment didn't go through. Your membership has not been activated.</p>
        <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
          <a href="/become-a-member" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors">
            <span class="material-icons-outlined">refresh</span>
            Try again
          </a>
          <a href="<?= e($mainSiteUrl) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold transition-colors">
            Main website
          </a>
        </div>
      <?php else: ?>
        <div class="mx-auto w-24 h-24 rounded-full bg-green-50 flex items-center justify-center mb-6 relative">
          <span class="material-icons-outlined text-6xl text-green-600">check_circle</span>
        </div>
        <p class="text-sm uppercase tracking-wider text-primary font-bold mb-2"><?= e($siteName) ?></p>
        <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900">
          <?php if ($applicantName !== ''): ?>
            Welcome, <span class="text-primary"><?= e(explode(' ', $applicantName)[0]) ?></span>
          <?php else: ?>
            Welcome to the Association
          <?php endif; ?>
        </h1>
        <?php if ($awaitingWebhook): ?>
          <p class="text-gray-500 mt-3 max-w-md mx-auto">Your payment was received. We're finalising your membership — you'll get an email when it's active.</p>
        <?php else: ?>
          <p class="text-gray-500 mt-3 max-w-md mx-auto">Thanks for joining. Your membership payment has been received and a receipt has been emailed to you.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php if (!$paymentFailed): ?>
      <section class="bg-card-light rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8">
        <h2 class="font-display text-xl font-semibold text-gray-900 mb-4">What's next?</h2>
        <ul class="space-y-3 text-sm text-gray-700">
          <li class="flex items-start gap-3">
            <span class="flex items-center justify-center w-8 h-8 rounded-full bg-primary/10 text-primary-strong shrink-0">
              <span class="material-icons-outlined text-base">mail</span>
            </span>
            <div>
              <p class="font-semibold text-gray-900">Check your inbox</p>
              <p class="text-gray-500">We've emailed your receipt and welcome details. If you don't see it, check your spam folder.</p>
            </div>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex items-center justify-center w-8 h-8 rounded-full bg-primary/10 text-primary-strong shrink-0">
              <span class="material-icons-outlined text-base">badge</span>
            </span>
            <div>
              <p class="font-semibold text-gray-900">Your membership number</p>
              <p class="text-gray-500">Your Goldwing member number will be issued by the committee and emailed once activated.</p>
            </div>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex items-center justify-center w-8 h-8 rounded-full bg-primary/10 text-primary-strong shrink-0">
              <span class="material-icons-outlined text-base">login</span>
            </span>
            <div>
              <p class="font-semibold text-gray-900">Sign in to the Members Area</p>
              <p class="text-gray-500">Use the email and password you set during signup. The dashboard unlocks the calendar, notices, members directory, and the store.</p>
            </div>
          </li>
        </ul>
      </section>

      <div class="flex flex-col sm:flex-row gap-3">
        <?php if ($user): ?>
          <a href="/member/index.php" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors">
            <span class="material-icons-outlined">dashboard</span>
            Back to dashboard
          </a>
        <?php else: ?>
          <a href="/login.php" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-900 hover:bg-gray-800 text-white font-semibold transition-colors">
            <span class="material-icons-outlined">login</span>
            Sign in
          </a>
        <?php endif; ?>
        <a href="<?= e($mainSiteUrl) ?>" class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold transition-colors">
          <span class="material-icons-outlined">public</span>
          Main website
        </a>
      </div>
    <?php endif; ?>

  </div>
</main>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
