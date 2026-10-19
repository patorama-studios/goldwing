<?php
// Self-contained: reads the live pricing config so admin edits show here
// immediately, whichever caller (index.php or the page builder) includes it.
$pricingSvc = \App\Services\MembershipPricingService::class;
$pricingCfg = $pricingSvc::getConfig();
$pricePeriods = array_values(array_filter($pricingCfg['renewal_periods'] ?? [], static fn($p) => !empty($p['active'])));
usort($pricePeriods, static fn($a, $b) => (int) $a['duration_months'] <=> (int) $b['duration_months']);
$joinWindow = $pricingSvc::joiningWindowForDate();
$joinFeeCents = $pricingSvc::getJoiningFeeCents();
$money = static fn(?int $cents): string => $cents === null ? '—' : '$' . number_format($cents / 100, $cents % 100 ? 2 : 0);
$monthLong = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$monthShort = static fn(int $m): string => substr($monthLong[(($m - 1) % 12 + 12) % 12 + 1], 0, 3);
$expiryText = (int) $pricingCfg['expiry_day'] . ' ' . $monthLong[(int) $pricingCfg['expiry_month']];
$priceRows = [
    ['PRINTED', 'FULL', 'Full member', 'Printed Wings magazine posted to you'],
    ['PDF', 'FULL', 'Full member', 'Wings magazine as a PDF'],
    ['PRINTED', 'ASSOCIATE', 'Associate member', 'Added to a full member’s household'],
];
// Join windows are thirds of the membership year (see joiningWindowForDate).
// From the rollover date a joiner pays the start-of-year price for the NEXT
// year, so the last third ends the month before rollover.
$anchorMonth = (int) $pricingCfg['anchor_month'];
$rolloverOn = !empty($pricingCfg['join_rollover_enabled']);
$lastThirdEnd = $rolloverOn ? (int) $pricingCfg['join_rollover_month'] - 1 : (int) $pricingCfg['expiry_month'];
$joinWindows = [
    'FULL' => $monthShort($anchorMonth) . ' – ' . $monthShort($anchorMonth + 3),
    'DEC' => $monthShort($anchorMonth + 4) . ' – ' . $monthShort($anchorMonth + 7),
    'APR' => $monthShort($anchorMonth + 8) . ' – ' . $monthShort($lastThirdEnd),
];
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
      <h2>Membership Prices</h2>
      <p>There are two price lists. Find the one that fits you. Every membership runs to <span style="white-space: nowrap;"><?= e($expiryText) ?></span>.</p>
    </div>

    <div class="membership-pricing__which">
      <a class="membership-which membership-which--new" href="#prices-new">
        <span class="membership-which__q">Joining for the first time?</span>
        <span class="membership-which__a">See <strong>New member</strong> prices</span>
      </a>
      <a class="membership-which membership-which--current" href="#prices-current">
        <span class="membership-which__q">Already a member?</span>
        <span class="membership-which__a">See <strong>Current member</strong> prices</span>
      </a>
    </div>

    <div class="membership-pricing__grid">
      <?php foreach ([
          ['new', 'New members', 'Joining the AGA for the first time', $joinFeeCents > 0 ? 'Includes the one-off ' . $money($joinFeeCents) . ' joining fee' : 'No joining fee'],
          ['current', 'Current members', 'Renewing your membership', 'No joining fee. You only pay it once'],
      ] as [$kind, $title, $sub, $badge]): ?>
      <article class="membership-prices membership-prices--<?= $kind ?>" id="prices-<?= $kind ?>">
        <header class="membership-prices__header">
          <h3><?= e($title) ?></h3>
          <p><?= e($sub) ?></p>
          <span class="membership-prices__badge"><?= e($badge) ?></span>
        </header>
        <table class="membership-prices__table">
          <caption class="sr-only"><?= e($title) ?> prices<?= $kind === 'new' ? ' if you join today' : '' ?></caption>
          <thead>
            <tr>
              <th scope="col">Membership</th>
              <?php foreach ($pricePeriods as $period): ?><th scope="col"><?= e($period['label']) ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($priceRows as [$mag, $type, $label, $detail]): ?>
            <tr>
              <th scope="row"><?= e($label) ?><span><?= e($detail) ?></span></th>
              <?php foreach ($pricePeriods as $period):
                $cents = $kind === 'new'
                    ? $pricingSvc::getJoiningPriceCents($mag, $type, (string) $period['id'], $joinWindow)
                    : $pricingSvc::getRenewalPriceCents($mag, $type, (string) $period['id']);
              ?><td><?= e($money($cents)) ?></td><?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($kind === 'new'): ?>
        <p class="membership-prices__note">Prices if you join today. Your membership runs to <?= e($expiryText) ?>.</p>
        <details class="membership-prices__later">
          <summary>Joining later in the year? The price drops.</summary>
          <table class="membership-prices__table membership-prices__table--compact">
            <caption class="sr-only">New member prices by the month you join</caption>
            <thead>
              <tr>
                <th scope="col">Join in</th>
                <?php foreach ($joinWindows as $wKey => $wLabel): ?>
                <th scope="col"<?= $wKey === $joinWindow ? ' class="is-now"' : '' ?>><?= e($wLabel) ?><?= $wKey === $joinWindow ? '<span>Now</span>' : '' ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <?php foreach ($pricePeriods as $period): ?>
            <tbody>
              <tr class="membership-prices__group"><th scope="rowgroup" colspan="<?= count($joinWindows) + 1 ?>"><?= e($period['label']) ?></th></tr>
              <?php foreach ($priceRows as [$mag, $type, $label, $detail]): ?>
              <tr>
                <th scope="row"><?= e($label) ?><span><?= e($mag === 'PDF' ? 'PDF Wings' : ($type === 'FULL' ? 'Printed Wings' : '')) ?></span></th>
                <?php foreach ($joinWindows as $wKey => $wLabel): ?>
                <td<?= $wKey === $joinWindow ? ' class="is-now"' : '' ?>><?= e($money($pricingSvc::getJoiningPriceCents($mag, $type, (string) $period['id'], $wKey))) ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
          <?php if ($rolloverOn): ?>
          <p class="membership-prices__note">Join from 1 <?= e($monthLong[(int) $pricingCfg['join_rollover_month']]) ?> and you pay the <?= e($joinWindows['FULL']) ?> price, but your membership runs all the way to <?= e($expiryText) ?> the following year.</p>
          <?php endif; ?>
        </details>
        <a class="button primary membership-prices__cta" href="/apply.php">Apply to join <span aria-hidden="true">&rarr;</span></a>
        <?php else: ?>
        <p class="membership-prices__note">Renewals run from 1 <?= e($monthLong[$anchorMonth]) ?> to <?= e($expiryText) ?>. Log in to renew; the member portal shows your exact price.</p>
        <a class="button membership-prices__cta" href="/member/?page=billing">Log in to renew <span aria-hidden="true">&rarr;</span></a>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
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
