<?php
require_once __DIR__ . '/../app/bootstrap.php';

http_response_code(404);

$pageTitle = '404 — Took a wrong turn';

$user = current_user();
$roles = $user['roles'] ?? [];

$dashboardUrl = '/member/index.php';
$dashboardLabel = 'Back to the Garage';
if ($user && in_array('admin', $roles, true)) {
    $dashboardUrl = '/admin/index.php';
    $dashboardLabel = 'Back to the Admin Garage';
}

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Insider puns for logged-in members — uses club lingo.
$memberPuns = [
    [
        'headline' => 'You\'ve ridden off the map.',
        'sub'      => 'This road isn\'t on any of our route sheets — let\'s get you back on the highway.',
    ],
    [
        'headline' => 'Wrong turn, rider.',
        'sub'      => 'Even the best touring crews miss an exit now and then. Pull a U-turn and we\'ll get you home.',
    ],
    [
        'headline' => 'Running on fumes.',
        'sub'      => 'This page doesn\'t exist — but the road back to the clubhouse is wide open.',
    ],
    [
        'headline' => 'Dead-end track.',
        'sub'      => 'Time to lean it over, swing back around and find a better line.',
    ],
    [
        'headline' => 'That trail\'s gone cold.',
        'sub'      => 'Whatever you were chasing has packed up and ridden off. Let\'s point you somewhere fun.',
    ],
    [
        'headline' => 'You\'ve drifted off the convoy.',
        'sub'      => 'No drama — the rest of the pack is just back this way.',
    ],
    [
        'headline' => 'Off-road and out of sealed bitumen.',
        'sub'      => 'Goldwings don\'t love the dirt. Let\'s get those tyres back on tarmac.',
    ],
    [
        'headline' => 'Missed the apex.',
        'sub'      => 'This page doesn\'t exist — but the next corner does. Throttle on, point home.',
    ],
];

// Warmer, welcoming puns for non-logged-in visitors — a touch less insider.
$publicPuns = [
    [
        'headline' => 'Looks like you\'ve taken a scenic detour.',
        'sub'      => 'This page isn\'t on the map — but the front gate is open. Roll on through.',
    ],
    [
        'headline' => 'Whoops — you\'ve missed the turn-off.',
        'sub'      => 'No harm done. Hit the throttle and we\'ll point you back to the start.',
    ],
    [
        'headline' => 'End of the road, friend.',
        'sub'      => 'There\'s nothing parked here. Let\'s ride back to base camp together.',
    ],
    [
        'headline' => 'This corner\'s empty.',
        'sub'      => 'Whatever you were looking for has packed up and left. The home page hasn\'t.',
    ],
    [
        'headline' => 'Bit of a rough stretch.',
        'sub'      => 'You\'ve hit a pothole the size of a postcode. Hop back on the highway with us.',
    ],
    [
        'headline' => 'Looks like you\'ve overshot the driveway.',
        'sub'      => 'Easy fix — circle back round and we\'ll get the kettle on.',
    ],
    [
        'headline' => 'You\'ve drifted past the clubhouse.',
        'sub'      => 'Happens to the best of us. The road home is just one button away.',
    ],
    [
        'headline' => 'Even your GPS is scratching its head.',
        'sub'      => 'This page doesn\'t exist on any map we know. Back to home base?',
    ],
];

$puns = $user ? $memberPuns : $publicPuns;
$pun = $puns[array_rand($puns)];

require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="section">
  <div class="container">
    <div class="page-card error-404" style="max-width: 760px; margin: 0 auto; text-align: center;">
      <div class="error-404__code" aria-hidden="true">404</div>
      <h1 style="margin-top: 0;"><?= e($pun['headline']) ?></h1>
      <p class="error-404__sub"><?= e($pun['sub']) ?></p>

      <div class="error-404__actions" style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; margin-top: 24px;">
        <?php if ($user): ?>
          <a class="button primary" href="<?= e($dashboardUrl) ?>"><?= e($dashboardLabel) ?></a>
          <a class="button ghost" href="/">Home Page</a>
        <?php else: ?>
          <a class="button primary" href="/">Take me home</a>
        <?php endif; ?>
      </div>

      <?php if (!$user): ?>
        <p class="error-404__nudge">
          While you&rsquo;re here &mdash; fancy joining the convoy?
          <a href="/become-a-member.php">Become a member</a>
          or <a href="/login.php">log in</a>.
        </p>
      <?php endif; ?>

      <p class="error-404__path">
        Tried to reach: <code><?= e($requestedPath) ?></code>
      </p>
    </div>
  </div>
</main>

<style>
  .error-404__code {
    font-family: 'Bebas Neue', 'Rajdhani', sans-serif;
    font-size: clamp(120px, 22vw, 220px);
    line-height: 0.9;
    letter-spacing: 0.04em;
    color: var(--gold);
    text-shadow: 0 6px 0 rgba(0, 0, 0, 0.08);
    margin-bottom: 8px;
  }
  .error-404__sub {
    font-size: 1.1rem;
    color: var(--muted);
    max-width: 540px;
    margin: 0 auto;
  }
  .error-404__nudge {
    margin-top: 22px;
    font-size: 0.95rem;
    color: var(--muted);
  }
  .error-404__nudge a {
    color: var(--gold);
    font-weight: 600;
    text-decoration: none;
  }
  .error-404__nudge a:hover {
    text-decoration: underline;
  }
  .error-404__path {
    margin-top: 28px;
    font-size: 0.85rem;
    color: var(--muted);
  }
  .error-404__path code {
    background: var(--sand);
    padding: 2px 8px;
    border-radius: 4px;
    color: var(--ink);
  }
</style>

<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>
</body>
</html>
