<?php
$ordinalSuffix = static function ($n): string {
    $n = (int) $n;
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    return $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
};
?>
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
              <span>Participation in organised rides and national rallies.</span>
            </li>
          </ul>
        </div>
        <div class="membership-intro__image">
          <img src="/assets/img/membership-second.png" alt="Motorcycle community gathering">
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── Membership showcase video ──────────────────────────────── -->
<section class="membership-video">
  <div class="container membership-video__header">
    <p class="membership-video__eyebrow">See the member experience</p>
    <h2 class="membership-video__title">Inside the A.G.A. member portal</h2>
  </div>
  <div class="membership-video__stage">
    <video class="membership-video__player"
           controls
           preload="metadata"
           playsinline
           poster="/assets/img/membership-video-poster.jpg">
      <source src="/assets/videos/goldwing-membership-showcase.mp4" type="video/mp4">
    </video>
  </div>
</section>

<section class="membership-pricing" id="pricing">
  <div class="container">
    <div class="membership-pricing__intro">
      <h2>Membership Options</h2>
      <p>Select the duration and type that best fits your needs. All memberships expire on the <span style="white-space: nowrap;"><?= e($ordinalSuffix($expiryDay ?? 31)) ?> of <?= e($expiryMonthName ?? 'July') ?></span>.</p>
    </div>
    <div class="membership-pricing__grid">
      <?php
        // One card per active renewal period — admin-defined.
        $renewalPeriods = $renewalPeriods ?? [];
        $joiningPrices = $joiningPrices ?? [];
        $featuredPeriodId = $featuredPeriodId ?? null;
        $anchorMonthName = $anchorMonthName ?? 'August';
        $expiryMonthName = $expiryMonthName ?? 'July';
        foreach ($renewalPeriods as $period):
          $periodId = (string) $period['id'];
          $isFeatured = ($periodId === $featuredPeriodId);
          $headerModifier = $isFeatured ? '' : ' membership-plan__header--dark';
          $articleModifier = $isFeatured ? ' membership-plan--featured' : '';
          $printedFull = $joiningPrices['PRINTED']['FULL'][$periodId] ?? null;
          $pdfFull = $joiningPrices['PDF']['FULL'][$periodId] ?? null;
          $printedAssociate = $joiningPrices['PRINTED']['ASSOCIATE'][$periodId] ?? null;
      ?>
      <article class="membership-plan<?= $articleModifier ?>">
        <div class="membership-plan__header<?= $headerModifier ?>">
          <?php if ($isFeatured): ?><span class="membership-plan__tag">Most popular</span><?php endif; ?>
          <h3><?= e($period['label']) ?></h3>
          <p>Full term: <?= e($anchorMonthName) ?> to <?= e($expiryMonthName) ?></p>
        </div>
        <div class="membership-plan__body">
          <div class="membership-plan__row">
            <div>
              <p class="membership-plan__label">Full - Wings</p>
              <p class="membership-plan__sub">Includes printed magazine</p>
            </div>
            <span class="membership-plan__price"><?= e($formatMembershipPrice($printedFull, $pricingCurrency)) ?></span>
          </div>
          <div class="membership-plan__row">
            <div>
              <p class="membership-plan__label">Full - PDF magazine</p>
              <p class="membership-plan__sub">Includes digital magazine</p>
            </div>
            <span class="membership-plan__price"><?= e($formatMembershipPrice($pdfFull, $pricingCurrency)) ?></span>
          </div>
          <div class="membership-plan__row">
            <div>
              <p class="membership-plan__label">Associate</p>
              <p class="membership-plan__sub">Standard access</p>
            </div>
            <span class="membership-plan__price"><?= e($formatMembershipPrice($printedAssociate, $pricingCurrency)) ?></span>
          </div>
        </div>
      </article>
      <?php endforeach; ?>

      <?php if (!empty($proRataEnabled)): ?>
      <article class="membership-plan">
        <div class="membership-plan__header membership-plan__header--mid">
          <h3>Join Mid-Year</h3>
          <p>Pro-rata to next expiry</p>
        </div>
        <div class="membership-plan__body">
          <table class="membership-pro-table">
            <thead>
              <tr>
                <th>Category</th>
                <th>If you join today</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Full - Wings</td>
                <td><?= e($formatMembershipPrice($todayProRata['PRINTED']['FULL'] ?? null, $pricingCurrency)) ?></td>
              </tr>
              <tr>
                <td>Full - PDF magazine</td>
                <td><?= e($formatMembershipPrice($todayProRata['PDF']['FULL'] ?? null, $pricingCurrency)) ?></td>
              </tr>
              <tr>
                <td>Associate</td>
                <td><?= e($formatMembershipPrice($todayProRata['PRINTED']['ASSOCIATE'] ?? null, $pricingCurrency)) ?></td>
              </tr>
            </tbody>
          </table>
          <p class="membership-pro-note">Price covers the <?= (int) ($monthsRemainingToday ?? 0) ?> month<?= ($monthsRemainingToday ?? 0) === 1 ? '' : 's' ?> until <?= e($ordinalSuffix($expiryDay ?? 31)) ?> <?= e($expiryMonthName) ?>. After that, members renew for whole years.</p>
        </div>
      </article>
      <?php endif; ?>
    </div>
    <div class="membership-cta">
      <a class="button primary membership-cta__button" href="/apply.php">
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
    <a class="button primary membership-final-cta__button" href="/apply.php">Apply today</a>
  </div>
</section>
