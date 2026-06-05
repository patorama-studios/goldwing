<?php
/**
 * Floating "?" Help button — included site-wide for logged-in users.
 *
 * Injects Driver.js, the tour engine, the manifest, the user's completion
 * map, and boots the help button. Skips silently for guests.
 */

use App\Services\TourService;
use App\Services\Csrf;
use App\Services\SettingsService;

if (!function_exists('current_user')) {
    return;
}
$gwHelpUser = current_user();
if (empty($gwHelpUser['id'])) {
    return;
}

$gwHelpManifest = TourService::manifest();
$gwHelpCompletions = TourService::completionsFor((int) $gwHelpUser['id']);
$gwHelpSupportEmail = SettingsService::getGlobal('site.support_email', 'admin@goldwing.org.au');
$gwHelpCurrentUrl = ($_SERVER['REQUEST_URI'] ?? '/');
$gwHelpAllGuidesUrl = '/admin/help/index.php';
$gwHelpCsrf = Csrf::token();
?>
<link rel="stylesheet" href="/assets/css/driver.css">
<link rel="stylesheet" href="/assets/css/tours.css">
<script src="/assets/js/tours/driver.js.iife.js" defer></script>
<script src="/assets/js/tours/tour-engine.js" defer></script>
<script>
  // Set up GoldwingTours globals once the engine has loaded.
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.GoldwingTours) return;
    window.GoldwingTours.manifest = <?= json_encode($gwHelpManifest, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.GoldwingTours.completions = <?= json_encode((object) $gwHelpCompletions, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.GoldwingTours.csrfToken = <?= json_encode($gwHelpCsrf) ?>;
    window.GoldwingTours.isAdmin = <?= json_encode(in_array('admin', $gwHelpUser['roles'] ?? [], true) || in_array('webmaster', $gwHelpUser['roles'] ?? [], true)) ?>;
  });
  window.GoldwingHelpConfig = {
    allGuidesUrl: <?= json_encode($gwHelpAllGuidesUrl) ?>,
    supportEmail: <?= json_encode($gwHelpSupportEmail) ?>,
    currentUrl: <?= json_encode($gwHelpCurrentUrl) ?>,
  };
</script>
<script src="/assets/js/tours/help-button.js" defer></script>
<?php
// Auto-launch validator mode if an admin opens a page with ?gw_validate=<slug>.
$gwValidateSlug = isset($_GET['gw_validate']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $_GET['gw_validate']) : '';
$gwValidateAllowed = $gwValidateSlug !== '' && (in_array('admin', $gwHelpUser['roles'] ?? [], true) || in_array('webmaster', $gwHelpUser['roles'] ?? [], true));
?>
<?php if ($gwValidateAllowed): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var tries = 0;
    var iv = setInterval(function () {
      if (window.GoldwingTours && window.GoldwingTours.runInValidator) {
        clearInterval(iv);
        window.GoldwingTours.runInValidator(<?= json_encode($gwValidateSlug) ?>, null);
      } else if (++tries > 40) {
        clearInterval(iv);
      }
    }, 100);
  });
</script>
<?php endif; ?>
