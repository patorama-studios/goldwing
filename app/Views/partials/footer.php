<?php
use App\Services\SettingsService;

$siteName = SettingsService::getGlobal('site.name', 'Australian Goldwing Association');
$showFooter = SettingsService::getGlobal('site.show_footer', true);
$legal = SettingsService::getGlobal('site.legal_urls', []);
$privacyUrl = is_array($legal) ? ($legal['privacy'] ?? '') : '';
$termsUrl = is_array($legal) ? ($legal['terms'] ?? '') : '';
?>
<?php if ($showFooter): ?>
<footer class="footer">
  <div class="container footer__inner">
    <div class="footer__brand"><?= e($siteName) ?></div>
    <div class="footer__meta">
      <span>&copy; <?= date('Y') ?> <?= e($siteName) ?>. All rights reserved.</span>
      <?php if ($privacyUrl): ?>
        <span class="footer__divider">|</span>
        <a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener">Privacy</a>
      <?php endif; ?>
      <?php if ($termsUrl): ?>
        <span class="footer__divider">|</span>
        <a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener">Terms</a>
      <?php endif; ?>
      <span class="footer__divider">|</span>
      <a href="https://patorama.com.au/" target="_blank" rel="noopener">Website by Patorama</a>
    </div>
  </div>
</footer>
<?php endif; ?>
</body>
</html>
