<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AdminMemberAccess;
use App\Services\ChapterRepository;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\MemberRepository;
use App\Services\MembershipService;
use App\Services\MembershipPricingService;
use App\Services\SettingsService;

require_permission('admin.members.view');

$user = current_user();

// Adding members needs full member-edit + manual-payment access (the wizard
// always sets a full profile and creates a membership order). Chapter-restricted
// accounts (area reps) cannot create members.
$chapterRestriction = AdminMemberAccess::getChapterRestrictionId($user);
if (!AdminMemberAccess::isFullAccess($user) || !AdminMemberAccess::canManualOrderFix($user) || $chapterRestriction !== null) {
    $_SESSION['members_flash'] = ['type' => 'error', 'message' => 'You are not authorized to add members.'];
    header('Location: /admin/members');
    exit;
}

// create_member is a sensitive action, so actions.php step-ups on submit. Gate
// it here on page load too (the documented pattern) so the admin verifies BEFORE
// filling the wizard — otherwise the submit redirects to /stepup.php and the
// whole POST is discarded, so the member is never created.
require_stepup($_SERVER['REQUEST_URI'] ?? '/admin/members/add.php');

$canSendWelcome = AdminMemberAccess::canResetPassword($user);

$pdo = Database::connection();

// Chapters for the chapter picker (mirrors index.php's option shape).
$allChapters = ChapterRepository::listForSelection($pdo, false);
$chapterSelectOptions = array_map(static fn($chapter) => [
    'id' => (int) ($chapter['id'] ?? 0),
    'label' => trim(($chapter['display_label'] ?? $chapter['name'] ?? '') . (($chapter['state'] ?? '') ? ' (' . $chapter['state'] . ')' : '')),
], $allChapters);

// Active membership plans (with price for prefilling cost).
try {
    $mtStmt = $pdo->query('SELECT id, name, price_cents FROM membership_types WHERE is_active = 1 ORDER BY name');
    $membershipTypes = $mtStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $mtStmt = $pdo->query('SELECT id, name FROM membership_types WHERE is_active = 1 ORDER BY name');
    $membershipTypes = $mtStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Full / Life members available as the "parent" for a linked associate.
$fullMembers = [];
try {
    $fmStmt = $pdo->query("SELECT id, first_name, last_name, member_number_base, member_number_suffix FROM members WHERE member_type IN ('FULL','LIFE') AND status <> 'INACTIVE' ORDER BY member_number_base ASC, last_name ASC");
    $fullMembers = $fmStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $fullMembers = [];
}

// Suggested next member number (same rule as MembershipUpgradeService).
$memberNumberStart = (int) SettingsService::getGlobal('membership.member_number_start', 1000);
$maxBase = (int) $pdo->query('SELECT MAX(member_number_base) FROM members')->fetchColumn();
$suggestedBase = max($maxBase, max($memberNumberStart, 1) - 1) + 1;

$directoryPrefs = MemberRepository::directoryPreferences();

$flash = $_SESSION['members_flash'] ?? null;
unset($_SESSION['members_flash']);

$today = date('Y-m-d');

// One-off joining fee default (configurable in Settings → Membership pricing).
$joiningFeeDefault = number_format(MembershipPricingService::getJoiningFeeCents() / 100, 2, '.', '');

$pageTitle = 'Add Member';
$activePage = 'members';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Add Member'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

      <div class="flex items-center gap-3">
        <a href="/admin/members" class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-gray-300">
          <span class="material-icons-outlined text-sm">arrow_back</span>
          All members
        </a>
      </div>

      <?php if ($flash): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-6 py-5">
          <h1 class="font-display text-2xl font-bold text-gray-900">Add a member</h1>
          <p class="text-sm text-gray-500">Set up a new member step by step — details, membership, expiry, and welcome.</p>
        </div>

        <!-- Step progress -->
        <div class="px-6 pt-5">
          <ol id="wizard-progress" class="flex flex-wrap items-center gap-x-2 gap-y-2 text-xs font-semibold">
            <?php
            $stepLabels = ['Type & number', 'Contact', 'Address', 'Preferences', 'Bikes', 'Membership', 'Finish'];
            foreach ($stepLabels as $i => $label):
            ?>
              <li class="wizard-progress-item flex items-center gap-2" data-progress="<?= $i ?>">
                <span class="progress-dot flex h-6 w-6 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-500"><?= $i + 1 ?></span>
                <span class="progress-text text-gray-500"><?= e($label) ?></span>
                <?php if ($i < count($stepLabels) - 1): ?><span class="text-gray-300">›</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>

        <form id="add-member-form" method="post" action="/admin/members/actions.php" class="px-6 py-6">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="action" value="create_member">
          <input type="hidden" name="tab" value="overview">

          <!-- STEP 1: Member type & number -->
          <div class="wizard-step" data-step="0">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Membership type &amp; number</h2>
            <p class="text-sm text-gray-500 mb-4">Choose the kind of member and their membership number.</p>

            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Member type</span>
                <select name="member_type" id="member_type" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="FULL">Full member</option>
                  <option value="ASSOCIATE">Associate member</option>
                  <option value="LIFE">Life member</option>
                </select>
              </label>

              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Chapter</span>
                <select name="chapter_id" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="">Unassigned</option>
                  <?php foreach ($chapterSelectOptions as $opt): ?>
                    <option value="<?= e((string) $opt['id']) ?>"><?= e($opt['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <!-- Member number (full / life / unlinked associate) -->
            <div id="number-section" class="mt-4">
              <label class="block max-w-xs">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Member number</span>
                <input type="text" name="member_number_base" value="<?= e((string) $suggestedBase) ?>" inputmode="numeric" pattern="\d*" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <span class="mt-1 block text-xs text-gray-500">Next available number is suggested. Override if needed.</span>
              </label>
            </div>

            <!-- Associate linking (shown only for associate) -->
            <div id="associate-section" class="mt-4 hidden rounded-xl border border-gray-200 bg-gray-50 p-4">
              <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Link to a full member (optional)</span>
              <p class="mt-1 text-xs text-gray-500">Linked associates share the full member's base number and get the next free suffix automatically. Leave blank to give this associate their own number.</p>
              <input type="text" id="full-member-filter" placeholder="Search by name or number…" class="mt-3 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" autocomplete="off">
              <select name="full_member_id" id="full_member_id" size="6" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm bg-white">
                <option value="">— No link (own number) —</option>
                <?php foreach ($fullMembers as $fm):
                  $num = MembershipService::displayMembershipNumber((int) $fm['member_number_base'], (int) $fm['member_number_suffix']);
                  $name = trim(($fm['first_name'] ?? '') . ' ' . ($fm['last_name'] ?? ''));
                ?>
                  <option value="<?= e((string) $fm['id']) ?>" data-search="<?= e(strtolower($num . ' ' . $name)) ?>"><?= e($num . ' — ' . $name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- STEP 2: Contact -->
          <div class="wizard-step hidden" data-step="1">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Contact details</h2>
            <p class="text-sm text-gray-500 mb-4">Name and email are required.</p>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">First name *</span>
                <input type="text" name="first_name" data-required class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Last name *</span>
                <input type="text" name="last_name" data-required class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Email *</span>
                <input type="email" name="email" data-required data-email class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Phone</span>
                <input type="text" name="phone" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
            </div>
          </div>

          <!-- STEP 3: Address -->
          <div class="wizard-step hidden" data-step="2">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Address</h2>
            <p class="text-sm text-gray-500 mb-4">All optional.</p>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block sm:col-span-2">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Address line 1</span>
                <input type="text" name="address_line1" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block sm:col-span-2">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Address line 2</span>
                <input type="text" name="address_line2" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Suburb / city</span>
                <input type="text" name="suburb" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">State</span>
                <input type="text" name="state" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Postcode</span>
                <input type="text" name="postcode" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Country</span>
                <input type="text" name="country" value="Australia" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
            </div>
          </div>

          <!-- STEP 4: Preferences -->
          <div class="wizard-step hidden" data-step="3">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Preferences</h2>
            <p class="text-sm text-gray-500 mb-4">Wings delivery, privacy, and member directory options.</p>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Wings magazine</span>
                <select name="wings_preference" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="digital">Digital</option>
                  <option value="print">Print</option>
                  <option value="both">Both</option>
                </select>
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Privacy level</span>
                <select name="privacy_level" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="A">A</option>
                  <option value="B">B</option>
                  <option value="C">C</option>
                  <option value="D">D</option>
                  <option value="E">E</option>
                  <option value="F">F</option>
                </select>
              </label>
            </div>
            <fieldset class="mt-4">
              <legend class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Member directory / assistance</legend>
              <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <?php foreach ($directoryPrefs as $letter => $info): ?>
                  <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="directory_pref_<?= e($letter) ?>" value="1" class="rounded border-gray-300">
                    <span><?= e($info['label']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <label class="block mt-4">
              <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Admin notes</span>
              <textarea name="notes" rows="3" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"></textarea>
            </label>
          </div>

          <!-- STEP 5: Bikes -->
          <div class="wizard-step hidden" data-step="4">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Bikes <span class="text-sm font-normal text-gray-400">(optional)</span></h2>
            <p class="text-sm text-gray-500 mb-4">Add the member's Goldwing(s). Rows left empty are ignored.</p>
            <div id="bikes-container" class="space-y-3"></div>
            <button type="button" id="add-bike-btn" class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-gray-300">
              <span class="material-icons-outlined text-sm">add</span>
              Add a bike
            </button>
          </div>

          <!-- STEP 6: Membership & expiry -->
          <div class="wizard-step hidden" data-step="5">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Membership &amp; expiry</h2>
            <p class="text-sm text-gray-500 mb-4">This creates the membership record, order, and renewal date.</p>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Membership plan *</span>
                <select name="membership_type_id" id="membership_type_id" data-required class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="">Select a plan…</option>
                  <?php foreach ($membershipTypes as $mt): ?>
                    <option value="<?= e((string) $mt['id']) ?>" data-price="<?= e((string) (isset($mt['price_cents']) ? ($mt['price_cents'] / 100) : '')) ?>"><?= e($mt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Membership status</span>
                <select name="membership_status" id="membership_status" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="active">Active (paid)</option>
                  <option value="pending">Pending payment</option>
                  <option value="complimentary">Complimentary</option>
                  <option value="lapsed">Lapsed</option>
                </select>
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Start / join date</span>
                <input type="date" name="start_date" id="start_date" value="<?= e($today) ?>" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block term-field">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Term</span>
                <select id="term" name="term" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                  <option value="1">1 year</option>
                  <option value="3">3 years</option>
                </select>
              </label>
              <label class="block renewal-field">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Renewal / expiry date</span>
                <input type="date" name="renewal_date" id="renewal_date" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <span class="mt-1 block text-xs text-gray-500">Auto-filled (31 July anchor). Editable. Renewal reminders auto-send 60 &amp; 30 days before.</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Joining fee (AUD)</span>
                <input type="number" step="0.01" min="0" id="joining_fee" value="<?= e($joiningFeeDefault) ?>" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <span class="mt-1 block text-xs text-gray-500">One-off, added to the plan price for first-year joins. Set to 0 to skip (e.g. associates / renewals).</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Amount paid (AUD)</span>
                <input type="number" step="0.01" min="0" name="membership_cost" id="membership_cost" value="0" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                <span class="mt-1 block text-xs text-gray-500">Auto-filled from the plan price + joining fee. Editable — type any amount.</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Payment method</span>
                <input type="text" name="payment_method" value="Manual" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
              <label class="block sm:col-span-2">
                <span class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Order reference / note</span>
                <input type="text" name="order_reference" class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
              </label>
            </div>
            <p id="life-note" class="mt-3 hidden text-xs text-gray-500">Life members have no expiry date — the renewal date is not used.</p>
          </div>

          <!-- STEP 7: Finish -->
          <div class="wizard-step hidden" data-step="6">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Finish</h2>
            <p class="text-sm text-gray-500 mb-4">The member and membership are always created. Choose what to send.</p>
            <div id="review-summary" class="mb-5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700"></div>
            <div class="space-y-3">
              <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-3 <?= $canSendWelcome ? '' : 'opacity-60' ?>">
                <input type="checkbox" name="finish_actions[]" value="welcome" class="mt-1 rounded border-gray-300" <?= $canSendWelcome ? '' : 'disabled' ?>>
                <span>
                  <span class="block text-sm font-semibold text-gray-900">Send welcome email</span>
                  <span class="block text-xs text-gray-500">Creates a login and emails a "set your password" link.<?= $canSendWelcome ? '' : ' (You do not have permission for this.)' ?></span>
                </span>
              </label>
              <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-3">
                <input type="checkbox" name="finish_actions[]" value="payment" class="mt-1 rounded border-gray-300">
                <span>
                  <span class="block text-sm font-semibold text-gray-900">Send payment email</span>
                  <span class="block text-xs text-gray-500">Emails a payment link. Only sent when the membership status is <strong>Pending payment</strong>.</span>
                </span>
              </label>
              <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-3">
                <input type="checkbox" name="finish_actions[]" value="renewal" class="mt-1 rounded border-gray-300" checked>
                <span>
                  <span class="block text-sm font-semibold text-gray-900">Set up renewal reminders</span>
                  <span class="block text-xs text-gray-500">Uses the renewal date so reminders auto-send 60 &amp; 30 days before expiry.</span>
                </span>
              </label>
            </div>
          </div>

          <!-- Nav -->
          <div class="mt-8 flex items-center justify-between border-t border-gray-100 pt-5">
            <button type="button" id="wizard-back" class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:border-gray-300 disabled:opacity-40" disabled>
              <span class="material-icons-outlined text-sm">arrow_back</span>
              Back
            </button>
            <p id="wizard-error" class="hidden text-sm text-red-600"></p>
            <div class="flex items-center gap-2">
              <button type="button" id="wizard-next" class="inline-flex items-center gap-1.5 rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white hover:bg-primary-strong">
                Next
                <span class="material-icons-outlined text-sm">arrow_forward</span>
              </button>
              <button type="submit" id="wizard-submit" class="hidden inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                <span class="material-icons-outlined text-sm">person_add</span>
                Add member
              </button>
            </div>
          </div>
        </form>
      </section>
    </div>
  </main>
</div>

<script>
(function () {
  var form = document.getElementById('add-member-form');
  var steps = Array.prototype.slice.call(form.querySelectorAll('.wizard-step'));
  var progressItems = Array.prototype.slice.call(document.querySelectorAll('.wizard-progress-item'));
  var backBtn = document.getElementById('wizard-back');
  var nextBtn = document.getElementById('wizard-next');
  var submitBtn = document.getElementById('wizard-submit');
  var errorEl = document.getElementById('wizard-error');
  var current = 0;

  function showError(msg) {
    errorEl.textContent = msg;
    errorEl.classList.remove('hidden');
  }
  function clearError() {
    errorEl.textContent = '';
    errorEl.classList.add('hidden');
  }

  function render() {
    steps.forEach(function (s, i) { s.classList.toggle('hidden', i !== current); });
    progressItems.forEach(function (item, i) {
      var dot = item.querySelector('.progress-dot');
      var text = item.querySelector('.progress-text');
      var done = i < current, active = i === current;
      dot.classList.toggle('bg-primary', active);
      dot.classList.toggle('text-white', active);
      dot.classList.toggle('border-primary', active || done);
      dot.classList.toggle('bg-emerald-500', done);
      dot.classList.toggle('text-white', active || done);
      dot.classList.toggle('border-emerald-500', done);
      text.classList.toggle('text-gray-900', active);
    });
    backBtn.disabled = current === 0;
    var last = current === steps.length - 1;
    nextBtn.classList.toggle('hidden', last);
    submitBtn.classList.toggle('hidden', !last);
    if (last) { buildReview(); }
    clearError();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function validateStep(index) {
    var step = steps[index];
    var required = Array.prototype.slice.call(step.querySelectorAll('[data-required]'));
    for (var i = 0; i < required.length; i++) {
      var el = required[i];
      if (el.offsetParent === null) { continue; }
      if (!el.value.trim()) {
        el.focus();
        showError('Please complete the required fields.');
        return false;
      }
      if (el.hasAttribute('data-email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim())) {
        el.focus();
        showError('Please enter a valid email address.');
        return false;
      }
    }
    return true;
  }

  nextBtn.addEventListener('click', function () {
    if (!validateStep(current)) { return; }
    if (current < steps.length - 1) { current++; render(); }
  });
  backBtn.addEventListener('click', function () {
    if (current > 0) { current--; render(); }
  });

  form.addEventListener('submit', function (e) {
    for (var i = 0; i < steps.length - 1; i++) {
      if (!validateStep(i)) { e.preventDefault(); current = i; render(); return; }
    }
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-60');
  });

  // --- Member type behaviour ---
  var memberType = document.getElementById('member_type');
  var numberSection = document.getElementById('number-section');
  var associateSection = document.getElementById('associate-section');
  var termField = form.querySelector('.term-field');
  var renewalField = form.querySelector('.renewal-field');
  var lifeNote = document.getElementById('life-note');

  function onTypeChange() {
    var t = memberType.value;
    associateSection.classList.toggle('hidden', t !== 'ASSOCIATE');
    var isLife = t === 'LIFE';
    if (termField) { termField.classList.toggle('hidden', isLife); }
    if (renewalField) { renewalField.classList.toggle('hidden', isLife); }
    if (lifeNote) { lifeNote.classList.toggle('hidden', !isLife); }
    recalcExpiry();
  }
  memberType.addEventListener('change', onTypeChange);

  // --- Full member filter ---
  var filterInput = document.getElementById('full-member-filter');
  var fullSelect = document.getElementById('full_member_id');
  if (filterInput && fullSelect) {
    filterInput.addEventListener('input', function () {
      var q = filterInput.value.trim().toLowerCase();
      Array.prototype.slice.call(fullSelect.options).forEach(function (opt) {
        if (!opt.value) { return; }
        var hay = opt.getAttribute('data-search') || '';
        opt.hidden = q !== '' && hay.indexOf(q) === -1;
      });
    });
  }

  // --- Expiry auto-calc (mirrors MembershipService::calculateExpiry) ---
  var startInput = document.getElementById('start_date');
  var termInput = document.getElementById('term');
  var renewalInput = document.getElementById('renewal_date');
  var renewalEdited = false;
  if (renewalInput) { renewalInput.addEventListener('input', function () { renewalEdited = true; }); }

  function recalcExpiry() {
    if (!startInput || !renewalInput) { return; }
    if (memberType.value === 'LIFE') { renewalInput.value = ''; return; }
    if (renewalEdited) { return; }
    var v = startInput.value;
    if (!v) { return; }
    var parts = v.split('-');
    if (parts.length !== 3) { return; }
    var year = parseInt(parts[0], 10);
    var month = parseInt(parts[1], 10);
    var termYears = termInput ? parseInt(termInput.value, 10) : 1;
    if (month >= 8) { year += 1; }
    var expiryYear = year + (termYears - 1);
    renewalInput.value = expiryYear + '-07-31';
  }
  if (startInput) { startInput.addEventListener('change', function () { renewalEdited = false; recalcExpiry(); }); }
  if (termInput) { termInput.addEventListener('change', function () { renewalEdited = false; recalcExpiry(); }); }

  // --- Cost prefill from plan (+ joining fee) ---
  var planSelect = document.getElementById('membership_type_id');
  var costInput = document.getElementById('membership_cost');
  var joiningInput = document.getElementById('joining_fee');
  var costEdited = false;
  if (costInput) { costInput.addEventListener('input', function () { costEdited = true; }); }
  function recalcCost() {
    if (!planSelect || !costInput || costEdited) { return; }
    var opt = planSelect.options[planSelect.selectedIndex];
    var price = opt ? parseFloat(opt.getAttribute('data-price')) : NaN;
    if (isNaN(price)) { return; }
    var joining = joiningInput ? parseFloat(joiningInput.value) : 0;
    if (isNaN(joining)) { joining = 0; }
    costInput.value = (price + joining).toFixed(2);
  }
  if (planSelect) { planSelect.addEventListener('change', recalcCost); }
  if (joiningInput) { joiningInput.addEventListener('input', recalcCost); }

  // --- Bikes repeater ---
  var bikesContainer = document.getElementById('bikes-container');
  var addBikeBtn = document.getElementById('add-bike-btn');
  var bikeIndex = 0;
  function addBikeRow() {
    var i = bikeIndex++;
    var row = document.createElement('div');
    row.className = 'rounded-xl border border-gray-200 p-3 grid gap-3 sm:grid-cols-12 items-end';
    row.innerHTML =
      '<label class="block sm:col-span-3"><span class="text-xs font-semibold text-gray-600">Make</span>' +
      '<input type="text" name="bikes[' + i + '][make]" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm"></label>' +
      '<label class="block sm:col-span-3"><span class="text-xs font-semibold text-gray-600">Model</span>' +
      '<input type="text" name="bikes[' + i + '][model]" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm"></label>' +
      '<label class="block sm:col-span-2"><span class="text-xs font-semibold text-gray-600">Year</span>' +
      '<input type="number" name="bikes[' + i + '][year]" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm"></label>' +
      '<label class="block sm:col-span-2"><span class="text-xs font-semibold text-gray-600">Rego</span>' +
      '<input type="text" name="bikes[' + i + '][rego]" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm"></label>' +
      '<label class="block sm:col-span-1"><span class="text-xs font-semibold text-gray-600">Colour</span>' +
      '<input type="text" name="bikes[' + i + '][color]" class="mt-1 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-sm"></label>' +
      '<div class="sm:col-span-1 flex justify-end"><button type="button" class="remove-bike text-gray-400 hover:text-red-600" title="Remove"><span class="material-icons-outlined text-base">delete</span></button></div>';
    row.querySelector('.remove-bike').addEventListener('click', function () { row.remove(); });
    bikesContainer.appendChild(row);
  }
  if (addBikeBtn) { addBikeBtn.addEventListener('click', addBikeRow); }

  // --- Review summary ---
  function buildReview() {
    var el = document.getElementById('review-summary');
    if (!el) { return; }
    function val(name) { var f = form.elements[name]; return f ? f.value : ''; }
    var typeLabel = memberType.options[memberType.selectedIndex].text;
    var planSel = planSelect.options[planSelect.selectedIndex];
    var statusSel = document.getElementById('membership_status');
    var bikes = bikesContainer.querySelectorAll('input[name$="[make]"]');
    var bikeCount = 0;
    Array.prototype.slice.call(bikes).forEach(function (b) { if (b.value.trim()) { bikeCount++; } });
    var rows = [
      ['Name', (val('first_name') + ' ' + val('last_name')).trim() || '—'],
      ['Email', val('email') || '—'],
      ['Type', typeLabel],
      ['Plan', planSel ? planSel.text : '—'],
      ['Status', statusSel.options[statusSel.selectedIndex].text],
      ['Renewal', memberType.value === 'LIFE' ? 'No expiry (Life)' : (val('renewal_date') || '—')],
      ['Bikes', bikeCount ? String(bikeCount) : 'None']
    ];
    el.innerHTML = '<div class="grid gap-1 sm:grid-cols-2">' + rows.map(function (r) {
      return '<div><span class="text-gray-500">' + r[0] + ':</span> <span class="font-medium text-gray-900">' + escapeHtml(r[1]) + '</span></div>';
    }).join('') + '</div>';
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Init
  onTypeChange();
  recalcExpiry();
  render();
})();
</script>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
