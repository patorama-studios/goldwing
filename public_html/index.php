<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\PageService;

$pageSlug = $_GET['page'] ?? 'home';
$pageSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($pageSlug));
$page = PageService::getBySlug($pageSlug);

$pageTitle = $page ? $page['title'] : 'Australian Goldwing Association';

$canView = false;
if ($page) {
    if ($page['visibility'] === 'public') {
        $canView = true;
    } elseif ($page['visibility'] === 'member' && current_user()) {
        $canView = true;
    } elseif ($page['visibility'] === 'admin' && current_user() && in_array('admin', current_user()['roles'] ?? [], true)) {
        $canView = true;
    }
}

$heroTitle = 'Welcome to the Australian Goldwing Association';
$heroLead = 'Rides, events, and member services for Goldwing riders across Australia.';
if ($page && $canView) {
    $heroTitle = $page['title'];
    $plainContent = trim(strip_tags($page['html_content']));
    if ($plainContent !== '') {
        $heroLead = $plainContent;
    }
} elseif ($page && !$canView) {
    $heroTitle = 'Members Only';
    $heroLead = 'Please log in to view this content and access member resources.';
}
if (strlen($heroLead) > 200) {
    $heroLead = substr($heroLead, 0, 200) . '...';
}

$heroClass = $pageSlug === 'home' ? 'hero hero--home' : 'hero hero--compact';

require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="<?= e($heroClass) ?>">
    <div class="container hero__inner">
      <span class="hero__eyebrow">Australian Goldwing Association</span>
      <h1><?= e($heroTitle) ?></h1>
      <p class="hero__lead"><?= e($heroLead) ?></p>
      <?php if ($pageSlug === 'home'): ?>
        <div class="hero__actions">
          <a class="button primary" href="/?page=membership">Join the Association</a>
          <a class="button ghost" href="/?page=ride-calendar">Ride Calendar</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="page-section">
    <div class="container">
      <div class="page-card reveal">
        <?php if ($page && $canView): ?>
          <div class="page-content"><?= render_media_shortcodes($page['html_content']) ?></div>
          <?php if ($pageSlug === 'ride-calendar'): ?>
            <div class="page-content">
              <iframe title="Ride calendar" src="/calendar/events_public.php" style="width: 100%; min-height: 900px; border: 0;" loading="lazy"></iframe>
            </div>
          <?php endif; ?>
        <?php elseif ($page && !$canView): ?>
          <h2>Members Only</h2>
          <p>Please <a href="/login.php">log in</a> to view this content.</p>
        <?php else: ?>
          <h2>Welcome to the Australian Goldwing Association</h2>
          <p>We are building the home for rides, events, and member services. Please check back soon.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php
require __DIR__ . '/../app/Views/partials/footer.php';
