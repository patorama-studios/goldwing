<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

require_permission('admin.members.view');

$pdo = db();
$user = current_user();
$appId = (int) ($_GET['id'] ?? 0);
$statusFilter = strtolower($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'pending';
}

$stmt = $pdo->prepare('SELECT a.*, m.member_number_base, m.member_number_suffix, m.member_type as member_profile_type, m.status as member_status, m.first_name as member_first_name, m.last_name as member_last_name, m.email as member_email, m.phone as member_phone, m.address_line1 as member_address_line1, m.address_line2 as member_address_line2, m.city as member_city, m.state as member_state, m.postal_code as member_postal_code, m.country as member_country, m.privacy_level, m.assist_ute, m.assist_phone, m.assist_bed, m.assist_tools, m.exclude_printed, m.exclude_electronic, m.chapter_id, c.name as chapter_name, c.state as chapter_state FROM membership_applications a JOIN members m ON m.id = a.member_id LEFT JOIN chapters c ON c.id = m.chapter_id WHERE a.id = :id');
$stmt->execute(['id' => $appId]);
$application = $stmt->fetch();

$notes = [];
if ($application) {
    $notes = json_decode($application['notes'] ?? '', true);
    if (!is_array($notes)) {
        $notes = [];
    }
}

$membership = $notes['membership'] ?? [];
$fullMembership = $membership['full'] ?? [];
$associateMembership = $membership['associate'] ?? [];
$currency = $membership['currency'] ?? 'AUD';
$fullVehicles = $notes['vehicles']['full'] ?? [];
$associateVehicles = $notes['vehicles']['associate'] ?? [];
$associate = $notes['associate'] ?? [];
$associateSelected = $membership['associate_selected'] ?? false;
$associateAdd = $membership['associate_add'] ?? '';

function yesNoLabel(bool $value): string
{
    return $value ? 'Yes' : 'No';
}

function formatCents($value, string $currency): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }
    $amount = ((int) $value) / 100;
    return $currency . ' ' . number_format($amount, 2);
}

function statusClasses(string $status): string
{
    return match (strtoupper($status)) {
        'APPROVED' => 'bg-emerald-100 text-emerald-800',
        'REJECTED' => 'bg-rose-100 text-rose-800',
        default => 'bg-amber-100 text-amber-800',
    };
}

$pageTitle = 'Application Details';
$activePage = 'applications';
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'Applications'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if (!$application): ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
          <h1 class="text-xl font-semibold text-gray-900">Application not found</h1>
          <p class="mt-2 text-sm text-gray-600">This application may have been removed or the link is invalid.</p>
          <a class="mt-4 inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700" href="/admin/index.php?page=applications&status=<?= e($statusFilter) ?>">Back to applications</a>
        </section>
      <?php else: ?>
        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div class="border-b border-gray-100 px-6 py-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <h1 class="font-display text-2xl font-bold text-gray-900">Application #<?= e((string) $application['id']) ?></h1>
              <p class="text-sm text-gray-500">Submitted <?= e($application['created_at']) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <a class="inline-flex items-center rounded-full border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-700" href="/admin/index.php?page=applications&status=<?= e($statusFilter) ?>">Back to applications</a>
              <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?= statusClasses($application['status']) ?>">
                <?= e(ucfirst(strtolower($application['status']))) ?>
              </span>
            </div>
          </div>
          <div class="p-6 grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
              <h2 class="font-semibold text-gray-900">Full member details</h2>
              <div class="grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Name</p>
                  <p class="text-gray-900 font-semibold"><?= e(trim($application['member_first_name'] . ' ' . $application['member_last_name'])) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Member number</p>
                  <p class="text-gray-900 font-semibold"><?= e(\App\Services\MembershipService::displayMembershipNumber((int) $application['member_number_base'], (int) $application['member_number_suffix'])) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Email</p>
                  <p class="text-gray-900 font-semibold"><?= e($application['member_email']) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Phone</p>
                  <p class="text-gray-900 font-semibold"><?= e($application['member_phone'] ?? 'N/A') ?></p>
                </div>
                <div class="sm:col-span-2">
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Address</p>
                  <p class="text-gray-900 font-semibold">
                    <?= e($application['member_address_line1'] ?? '') ?>
                    <?= $application['member_address_line2'] ? ' ' . e($application['member_address_line2']) : '' ?><br>
                    <?= e($application['member_city'] ?? '') ?> <?= e($application['member_state'] ?? '') ?> <?= e($application['member_postal_code'] ?? '') ?><br>
                    <?= e($application['member_country'] ?? '') ?>
                  </p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Chapter</p>
                  <p class="text-gray-900 font-semibold"><?= e($application['chapter_name'] ? $application['chapter_name'] . ' (' . $application['chapter_state'] . ')' : 'Unassigned') ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Privacy level</p>
                  <p class="text-gray-900 font-semibold"><?= e($application['privacy_level'] ?? 'N/A') ?></p>
                </div>
              </div>
              <div class="grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Assist with ute</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($application['assist_ute']))) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Assist with phone</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($application['assist_phone']))) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Assist with bed</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($application['assist_bed']))) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Assist with tools</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($application['assist_tools']))) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Exclude printed magazine</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($application['exclude_printed']))) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Exclude electronic magazine</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($application['exclude_electronic']))) ?></p>
                </div>
              </div>
            </div>
            <div class="space-y-4">
              <h2 class="font-semibold text-gray-900">Full membership selection</h2>
              <div class="grid gap-4 sm:grid-cols-2 text-sm">
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Selected</p>
                  <p class="text-gray-900 font-semibold"><?= e(yesNoLabel(!empty($membership['full_selected']))) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Magazine type</p>
                  <p class="text-gray-900 font-semibold"><?= e($fullMembership['magazine_type'] ?? 'N/A') ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Period</p>
                  <p class="text-gray-900 font-semibold"><?= e($fullMembership['period_key'] ?? 'N/A') ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Price</p>
                  <p class="text-gray-900 font-semibold"><?= e(formatCents($fullMembership['price_cents'] ?? null, $currency)) ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Payment method</p>
                  <p class="text-gray-900 font-semibold"><?= e($notes['payment_method'] ?? 'N/A') ?></p>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Referral source</p>
                  <p class="text-gray-900 font-semibold"><?= e($notes['referral_source'] ?? 'N/A') ?></p>
                </div>
              </div>
              <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
                <p class="text-xs uppercase tracking-[0.3em] text-gray-500 mb-3">Full member vehicles</p>
                <?php if (empty($fullVehicles)): ?>
                  <p class="text-gray-600">No vehicles listed.</p>
                <?php else: ?>
                  <div class="space-y-2">
                    <?php foreach ($fullVehicles as $vehicle): ?>
                      <div class="rounded-xl border border-gray-200 bg-white px-3 py-2">
                        <p class="font-semibold text-gray-900"><?= e(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) ?></p>
                        <p class="text-xs text-gray-500">Year: <?= e($vehicle['year'] ?? 'N/A') ?> · Rego: <?= e($vehicle['rego'] ?? 'N/A') ?></p>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>

        <?php if ($associateSelected && $associateAdd === 'yes'): ?>
          <section class="rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
              <h2 class="font-display text-xl font-bold text-gray-900">Associate member details</h2>
              <p class="text-sm text-gray-500">Linked to application #<?= e((string) $application['id']) ?></p>
            </div>
            <div class="p-6 grid gap-6 lg:grid-cols-2">
              <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2 text-sm">
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Name</p>
                    <p class="text-gray-900 font-semibold"><?= e(trim(($associate['first_name'] ?? '') . ' ' . ($associate['last_name'] ?? ''))) ?></p>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Email</p>
                    <p class="text-gray-900 font-semibold"><?= e($associate['email'] ?? 'N/A') ?></p>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Phone</p>
                    <p class="text-gray-900 font-semibold"><?= e($associate['phone'] ?? 'N/A') ?></p>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Different address</p>
                    <p class="text-gray-900 font-semibold"><?= e($associate['address_diff'] ?? 'N/A') ?></p>
                  </div>
                  <div class="sm:col-span-2">
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Address</p>
                    <p class="text-gray-900 font-semibold">
                      <?= e($associate['address_line1'] ?? '') ?><br>
                      <?= e($associate['city'] ?? '') ?> <?= e($associate['state'] ?? '') ?> <?= e($associate['postal_code'] ?? '') ?><br>
                      <?= e($associate['country'] ?? '') ?>
                    </p>
                  </div>
                </div>
              </div>
              <div class="space-y-4">
                <h3 class="font-semibold text-gray-900">Associate membership selection</h3>
                <div class="grid gap-4 sm:grid-cols-2 text-sm">
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Selected</p>
                    <p class="text-gray-900 font-semibold"><?= e(yesNoLabel($associateSelected)) ?></p>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Period</p>
                    <p class="text-gray-900 font-semibold"><?= e($associateMembership['period_key'] ?? 'N/A') ?></p>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Price</p>
                    <p class="text-gray-900 font-semibold"><?= e(formatCents($associateMembership['price_cents'] ?? null, $currency)) ?></p>
                  </div>
                  <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-gray-500">Magazine type</p>
                    <p class="text-gray-900 font-semibold"><?= e($associateMembership['magazine_type'] ?? 'N/A') ?></p>
                  </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm">
                  <p class="text-xs uppercase tracking-[0.3em] text-gray-500 mb-3">Associate vehicles</p>
                  <?php if (empty($associateVehicles)): ?>
                    <p class="text-gray-600">No vehicles listed.</p>
                  <?php else: ?>
                    <div class="space-y-2">
                      <?php foreach ($associateVehicles as $vehicle): ?>
                        <div class="rounded-xl border border-gray-200 bg-white px-3 py-2">
                          <p class="font-semibold text-gray-900"><?= e(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) ?></p>
                          <p class="text-xs text-gray-500">Year: <?= e($vehicle['year'] ?? 'N/A') ?> · Rego: <?= e($vehicle['rego'] ?? 'N/A') ?></p>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
