<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$pageTitle = 'Membership Success';
require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container hero__inner">
      <span class="hero__eyebrow">Australian Goldwing Association</span>
      <h1>Membership payment received</h1>
      <p class="hero__lead">Thanks for joining. Your membership will activate once the payment clears.</p>
    </div>
  </section>
  <section class="page-section">
    <div class="container">
      <div class="page-card">
        <p>We have received your payment. A confirmation email will arrive shortly.</p>
        <a class="button primary" href="/">Return to home</a>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
