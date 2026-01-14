<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\MembershipPricingService;
use App\Services\PageService;

$pageSlug = $_GET['page'] ?? 'home';
$pageSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($pageSlug));
$page = PageService::getBySlug($pageSlug);

$pageTitle = $page ? $page['title'] : 'Australian Goldwing Association';
$isMembershipPage = $pageSlug === 'membership';

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
$membershipPricing = $isMembershipPage ? MembershipPricingService::getMembershipPricing() : [];
$pricingMatrix = $membershipPricing['matrix'] ?? [];
$pricingCurrency = $membershipPricing['currency'] ?? 'AUD';
$pricingNote = $isMembershipPage ? MembershipPricingService::pricingNote() : '';
$formatMembershipPrice = static function (?int $cents, string $currency): string {
    if ($cents === null) {
        return 'N/A';
    }
    $prefix = $currency === 'AUD' ? '$' : ($currency . ' ');
    return $prefix . number_format($cents / 100, 2);
};
$getMembershipPrice = static function (array $matrix, string $magazine, string $membership, string $period): ?int {
    return $matrix[$magazine][$membership][$period] ?? null;
};

require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <?php if ($isMembershipPage && $page && $canView): ?>
    <section class="membership-hero">
      <div class="container membership-hero__inner">
        <span class="membership-hero__eyebrow">Australian Goldwing Association</span>
        <h1>Membership Benefits and Pricing</h1>
        <p class="membership-hero__lead">Join our premier touring community with flexible membership options tailored for every rider.</p>
      </div>
    </section>

    <section class="membership-intro">
      <div class="container">
        <div class="membership-card membership-card--intro">
          <div class="membership-intro__grid">
            <div>
              <h2>Why Join Us?</h2>
              <p>New full members will receive a comprehensive welcome pack including a membership card, an embroidered patch, an enamel badge, and a welcome letter with a link to our constitution.</p>
              <ul class="membership-highlights">
                <li>
                  <span class="membership-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg>
                  </span>
                  <span>Access to a directory of like-minded touring riders.</span>
                </li>
                <li>
                  <span class="membership-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg>
                  </span>
                  <span>Subscription to the exclusive Wings Magazine (Printed or PDF).</span>
                </li>
                <li>
                  <span class="membership-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg>
                  </span>
                  <span>Participation in organized rides and national rallies.</span>
                </li>
              </ul>
            </div>
            <div class="membership-intro__image">
              <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuBdE0csgyVfFdqFSwxRvdyUwYfPc5cx7CgYt-ldfJapZNjvba8ZZCezjVid3aDuHmzdV_fVfqE2lW8s_swpu2xxJt8maR93nYmzNR2TXnjpeHyeqhHERIQva8j9t6_Uw2OOwcZNfaN0SpSZTjcRry69FJdfjyES43SkMXGFZh3MZJ28uugx2Ja19nkhWA8uJ72AUTERI2GUwpXlmt6m6ax0b7LDAAipd0lqO9w7BhL9jmeb5m_hcCBqAZ6YZjWydH5KCCgxFJznnqI" alt="Motorcycle community gathering">
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="membership-pricing" id="pricing">
      <div class="container">
        <div class="membership-pricing__intro">
          <h2>Membership Options</h2>
          <p>Select the duration and type that best fits your needs. All memberships expire on the 31st of July. Pricing includes a one-off $15.00 joining fee.</p>
        </div>
        <div class="membership-pricing__grid">
          <article class="membership-plan">
            <div class="membership-plan__header membership-plan__header--dark">
              <h3>3 Year Plan</h3>
              <p>Full term: August to July</p>
            </div>
            <div class="membership-plan__body">
              <div class="membership-plan__row">
                <div>
                  <p class="membership-plan__label">Full - Wings</p>
                  <p class="membership-plan__sub">Includes printed magazine</p>
                </div>
                <span class="membership-plan__price"><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'FULL', 'THREE_YEARS'), $pricingCurrency)) ?></span>
              </div>
              <div class="membership-plan__row">
                <div>
                  <p class="membership-plan__label">Full - PDF</p>
                  <p class="membership-plan__sub">Includes digital magazine</p>
                </div>
                <span class="membership-plan__price"><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PDF', 'FULL', 'THREE_YEARS'), $pricingCurrency)) ?></span>
              </div>
              <div class="membership-plan__row">
                <div>
                  <p class="membership-plan__label">Associate</p>
                  <p class="membership-plan__sub">Standard access</p>
                </div>
                <span class="membership-plan__price"><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'ASSOCIATE', 'THREE_YEARS'), $pricingCurrency)) ?></span>
              </div>
            </div>
          </article>

          <article class="membership-plan membership-plan--featured">
            <div class="membership-plan__header">
              <span class="membership-plan__tag">Most popular</span>
              <h3>1 Year Plan</h3>
              <p>Full term: August to July</p>
            </div>
            <div class="membership-plan__body">
              <div class="membership-plan__row">
                <div>
                  <p class="membership-plan__label">Full - Wings</p>
                  <p class="membership-plan__sub">Includes printed magazine</p>
                </div>
                <span class="membership-plan__price"><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'FULL', 'ONE_YEAR'), $pricingCurrency)) ?></span>
              </div>
              <div class="membership-plan__row">
                <div>
                  <p class="membership-plan__label">Full - PDF</p>
                  <p class="membership-plan__sub">Includes digital magazine</p>
                </div>
                <span class="membership-plan__price"><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PDF', 'FULL', 'ONE_YEAR'), $pricingCurrency)) ?></span>
              </div>
              <div class="membership-plan__row">
                <div>
                  <p class="membership-plan__label">Associate</p>
                  <p class="membership-plan__sub">Standard access</p>
                </div>
                <span class="membership-plan__price"><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'ASSOCIATE', 'ONE_YEAR'), $pricingCurrency)) ?></span>
              </div>
            </div>
          </article>

          <article class="membership-plan">
            <div class="membership-plan__header membership-plan__header--mid">
              <h3>Pro-rata Rates</h3>
              <p>Join mid-season</p>
            </div>
            <div class="membership-plan__body">
              <table class="membership-pro-table">
                <thead>
                  <tr>
                    <th>Category</th>
                    <th>After Dec</th>
                    <th>After Apr</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Full - Wings</td>
                    <td><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'FULL', 'TWO_THIRDS'), $pricingCurrency)) ?></td>
                    <td><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'FULL', 'ONE_THIRD'), $pricingCurrency)) ?></td>
                  </tr>
                  <tr>
                    <td>Full - PDF</td>
                    <td><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PDF', 'FULL', 'TWO_THIRDS'), $pricingCurrency)) ?></td>
                    <td><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PDF', 'FULL', 'ONE_THIRD'), $pricingCurrency)) ?></td>
                  </tr>
                  <tr>
                    <td>Associate</td>
                    <td><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'ASSOCIATE', 'TWO_THIRDS'), $pricingCurrency)) ?></td>
                    <td><?= e($formatMembershipPrice($getMembershipPrice($pricingMatrix, 'PRINTED', 'ASSOCIATE', 'ONE_THIRD'), $pricingCurrency)) ?></td>
                  </tr>
                </tbody>
              </table>
              <p class="membership-pro-note">Values shown are for 1-year equivalent pro-rata.</p>
            </div>
          </article>
        </div>
        <div class="membership-cta">
          <a class="button primary membership-cta__button" href="/become-a-member">
            Apply today
            <span class="membership-cta__arrow" aria-hidden="true">&rarr;</span>
          </a>
        </div>
      </div>
    </section>

    <section class="membership-inclusions">
      <div class="container">
        <div class="membership-inclusions__grid">
          <div>
            <h2>Full Member Inclusions</h2>
            <ul class="membership-inclusions__list">
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Membership card</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Embroidered patch</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Enamel badge</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Welcome letter</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Member directory</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Wings Magazine</li>
            </ul>
          </div>
          <div>
            <h2>Associate Inclusions</h2>
            <ul class="membership-inclusions__list">
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Membership card</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Embroidered patch</li>
              <li><span class="membership-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></span>Enamel badge</li>
            </ul>
            <div class="membership-note">
              <p><strong>Note:</strong> <?= e($pricingNote) ?> Joining fees are included in the initial pricing shown.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="membership-final-cta">
      <div class="container">
        <h2>Ready to join the community?</h2>
        <p>Complete your application today and join the premier association for Goldwing owners in Australia.</p>
        <a class="button primary membership-final-cta__button" href="/become-a-member">Apply today</a>
      </div>
    </section>
  <?php else: ?>
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
  <?php endif; ?>
</main>
<?php
require __DIR__ . '/../app/Views/partials/footer.php';
