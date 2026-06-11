<?php
/**
 * Membership-lapsed lockdown UI. Include once, as the first child of <main>
 * (i.e. straight after the mobile topbar include) on any member-area page.
 *
 * Expects in scope:
 *   $user             - current session user array (falls back to current_user())
 *   $lockdownPageKey  - sidebar page key for THIS page (e.g. 'wings', 'billing').
 *                       Used to decide whether to draw the blur overlay.
 *
 * Renders, only when the membership has lapsed:
 *   1. a slim persistent banner at the top of the content area;
 *   2. a heavy blur overlay over the page content when the page is locked;
 *   3. a "your membership has lapsed" lightbox shown once per login session.
 *
 * Every "Renew now" action points at /member/?page=billing&pay=1, which
 * auto-opens the existing pay lightbox — so this works on every page without
 * depending on the pay-drawer markup being present locally.
 */

$ldUser = $user ?? (function_exists('current_user') ? current_user() : null);
$ldState = \App\Services\MembershipAccessService::state($ldUser);

if (!empty($ldState['lapsed'])):
    $ldPageKey = $lockdownPageKey ?? null;
    $ldPageLocked = \App\Services\MembershipAccessService::isPageLocked($ldPageKey);
    $ldRenewUrl = '/member/index.php?page=billing&pay=1';
    $ldEndLabel = '';
    if (!empty($ldState['end_date'])) {
        $ldEndLabel = function_exists('format_date_au')
            ? (format_date_au($ldState['end_date']) ?: '')
            : (string) $ldState['end_date'];
    }
?>
<!-- ── Membership lapsed: persistent banner ─────────────────────────────── -->
<div class="relative z-30 bg-red-600 text-white px-4 sm:px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-2 shadow-sm" data-lockdown-banner>
  <span class="material-icons-outlined text-xl shrink-0">lock</span>
  <p class="flex-1 min-w-[12rem] text-sm font-medium leading-snug">
    Your membership has lapsed<?= $ldEndLabel !== '' ? ' (expired ' . e($ldEndLabel) . ')' : '' ?>.
    Renew now to restore full access to the members area.
  </p>
  <a href="<?= e($ldRenewUrl) ?>"
     class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 transition-colors">
    <span class="material-icons-outlined text-base">autorenew</span>
    Renew membership
  </a>
</div>

<?php if ($ldPageLocked): ?>
<!-- ── Membership lapsed: blur overlay over locked content ───────────────── -->
<div class="gw-lockdown-overlay" data-lockdown-overlay role="alertdialog" aria-modal="false" aria-labelledby="gw-lockdown-overlay-title">
  <div class="gw-lockdown-blur" aria-hidden="true"></div>
  <div class="gw-lockdown-card">
    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
      <span class="material-icons-outlined text-3xl text-red-600">lock</span>
    </div>
    <h2 id="gw-lockdown-overlay-title" class="font-display text-xl font-bold text-gray-900">This area is locked</h2>
    <p class="mt-2 text-sm text-gray-600">
      Your membership has lapsed, so member features like this one are
      temporarily unavailable. Renew your membership to unlock the calendar,
      Wings, the members directory and everything else.
    </p>
    <div class="mt-6 flex flex-col sm:flex-row items-stretch sm:items-center justify-center gap-3">
      <a href="<?= e($ldRenewUrl) ?>"
         class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition-colors">
        <span class="material-icons-outlined text-base">autorenew</span>
        Renew membership now
      </a>
      <a href="/member/index.php"
         class="inline-flex items-center justify-center gap-2 rounded-lg bg-gray-100 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-200 transition-colors">
        <span class="material-icons-outlined text-base">dashboard</span>
        Back to dashboard
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Membership lapsed: once-per-session lightbox ──────────────────────── -->
<div class="gw-lockdown-modal hidden" data-lockdown-modal role="dialog" aria-modal="true" aria-labelledby="gw-lockdown-modal-title">
  <div class="gw-lockdown-modal-backdrop" data-lockdown-modal-close aria-hidden="true"></div>
  <div class="gw-lockdown-modal-card">
    <div class="h-1.5 w-full rounded-t-2xl bg-red-600"></div>
    <div class="p-6 sm:p-8 text-center">
      <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
        <span class="material-icons-outlined text-3xl text-red-600">lock_clock</span>
      </div>
      <h2 id="gw-lockdown-modal-title" class="font-display text-xl font-bold text-gray-900">Your membership has lapsed</h2>
      <p class="mt-2 text-sm text-gray-600">
        <?= $ldEndLabel !== '' ? 'Your membership expired on ' . e($ldEndLabel) . '. ' : '' ?>While
        it's lapsed you can still reach your dashboard, billing and profile, but
        community features are locked. Renew now to unlock everything again.
      </p>
      <div class="mt-6 flex flex-col gap-3">
        <a href="<?= e($ldRenewUrl) ?>"
           class="inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-5 py-3 text-sm font-semibold text-white hover:bg-red-700 transition-colors">
          <span class="material-icons-outlined text-base">autorenew</span>
          Renew membership now
        </a>
        <button type="button" data-lockdown-modal-close
          class="text-sm font-medium text-gray-500 hover:text-gray-700">
          Continue with limited access
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  /* Blur overlay sits over the page content only: below the mobile topbar
     (h-16) on small screens, and beside the static sidebar (w-64 = 16rem)
     on md+. z-20 keeps it under the sticky topbar (z-30) so the mobile
     hamburger stays tappable, and under the sidebar (z-40). */
  .gw-lockdown-overlay {
    position: fixed;
    top: 4rem; left: 0; right: 0; bottom: 0;
    z-index: 20;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem;
  }
  @media (min-width: 768px) {
    .gw-lockdown-overlay { top: 0; left: 16rem; }
  }
  .gw-lockdown-blur {
    position: absolute; inset: 0;
    background: rgba(248, 250, 252, 0.7);
    -webkit-backdrop-filter: blur(14px);
    backdrop-filter: blur(14px);
  }
  .gw-lockdown-card {
    position: relative;
    width: 100%; max-width: 30rem;
    background: #fff;
    border: 1px solid rgba(0, 0, 0, 0.06);
    border-top: 4px solid #dc2626;
    border-radius: 1rem;
    box-shadow: 0 20px 45px -15px rgba(0, 0, 0, 0.35);
    padding: 1.75rem 1.5rem;
    text-align: center;
  }
  /* Once-per-session lightbox */
  .gw-lockdown-modal {
    position: fixed; inset: 0; z-index: 60;
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem;
  }
  .gw-lockdown-modal.hidden { display: none; }
  .gw-lockdown-modal-backdrop {
    position: absolute; inset: 0;
    background: rgba(15, 23, 42, 0.55);
    -webkit-backdrop-filter: blur(2px);
    backdrop-filter: blur(2px);
  }
  .gw-lockdown-modal-card {
    position: relative;
    width: 100%; max-width: 28rem;
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
    overflow: hidden;
  }
</style>
<script>
  (function () {
    var modal = document.querySelector('[data-lockdown-modal]');
    if (!modal) return;

    function closeModal() { modal.classList.add('hidden'); }

    document.querySelectorAll('[data-lockdown-modal-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    // Show once per browsing session (until the tab/window is closed).
    try {
      if (!sessionStorage.getItem('gw:lockdown:shown')) {
        modal.classList.remove('hidden');
        sessionStorage.setItem('gw:lockdown:shown', '1');
      }
    } catch (err) {
      // sessionStorage blocked — show it this once, don't loop.
      modal.classList.remove('hidden');
    }
  })();
</script>
<?php endif; ?>
