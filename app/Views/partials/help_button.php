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
// Auto-launch when a member follows a ?gw_tour=<slug> link from the Help panel
// or an admin opens a ?gw_validate=<slug> link from the Tour Validator page.
$gwTourSlug     = isset($_GET['gw_tour']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $_GET['gw_tour']) : '';
$gwValidateSlug = isset($_GET['gw_validate']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $_GET['gw_validate']) : '';
$gwIsAdmin = in_array('admin', $gwHelpUser['roles'] ?? [], true)
          || in_array('webmaster', $gwHelpUser['roles'] ?? [], true);
$gwValidateAllowed = $gwValidateSlug !== '' && $gwIsAdmin;
$gwTourAllowed     = $gwTourSlug !== '';
?>
<?php if ($gwTourAllowed || $gwValidateAllowed): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    // Strip the query param immediately so reloads don't re-launch.
    try {
      if (window.history && window.history.replaceState) {
        var u = new URL(window.location.href);
        u.searchParams.delete('gw_tour');
        u.searchParams.delete('gw_validate');
        window.history.replaceState({}, document.title, u.pathname + (u.search ? u.search : '') + u.hash);
      }
    } catch (e) { /* old browsers — fine to skip */ }

    var tries = 0;
    var iv = setInterval(function () {
      var gt = window.GoldwingTours;
      if (gt && gt.manifest && (gt.run || gt.runInValidator)) {
        clearInterval(iv);
        <?php if ($gwValidateAllowed): ?>
        if (typeof gt.runInValidator === 'function') {
          gt.runInValidator(<?= json_encode($gwValidateSlug) ?>, null);
          return;
        }
        <?php endif; ?>
        <?php if ($gwTourAllowed): ?>
        if (typeof gt.run === 'function') {
          gt.run(<?= json_encode($gwTourSlug) ?>);
        }
        <?php endif; ?>
      } else if (++tries > 40) {
        clearInterval(iv);
      }
    }, 100);
  });
</script>
<?php endif; ?>
