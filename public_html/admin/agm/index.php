<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AgmEventService;
use App\Services\AgmRegistrationService;
use App\Services\StripeSettingsService;

require_permission('admin.agm.view');

$agmFeatureEnabled = AgmEventService::isFeatureEnabled();

$user = current_user();
$pdo = db();

$tab = $_GET['tab'] ?? 'dashboard';
$validTabs = ['dashboard', 'event', 'content', 'products', 'fields', 'submissions', 'archive', 'settings'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'dashboard';
}

$selectedEventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$events = AgmEventService::listEvents();
$activeEvents = array_values(array_filter($events, fn($e) => ($e['status'] ?? '') !== 'archived'));
$archivedEvents = array_values(array_filter($events, fn($e) => ($e['status'] ?? '') === 'archived'));

if ($selectedEventId <= 0) {
    $current = AgmEventService::getCurrentEvent();
    if ($current) {
        $selectedEventId = (int) $current['id'];
    } elseif ($activeEvents) {
        $selectedEventId = (int) $activeEvents[0]['id'];
    }
}
$selectedEvent = $selectedEventId > 0 ? AgmEventService::getEventById($selectedEventId) : null;

$flash = null;
if (!empty($_GET['msg'])) {
    $flashMessages = [
        'event_created' => ['type' => 'success', 'message' => 'AGM event created.'],
        'event_updated' => ['type' => 'success', 'message' => 'AGM event updated.'],
        'event_published' => ['type' => 'success', 'message' => 'Event published and set as current.'],
        'event_archived' => ['type' => 'success', 'message' => 'Event archived.'],
        'content_saved' => ['type' => 'success', 'message' => 'Event content saved.'],
        'product_saved' => ['type' => 'success', 'message' => 'Product saved.'],
        'product_deleted' => ['type' => 'success', 'message' => 'Product deleted.'],
        'field_saved' => ['type' => 'success', 'message' => 'Form field saved.'],
        'field_deleted' => ['type' => 'success', 'message' => 'Form field deleted.'],
        'cloned' => ['type' => 'success', 'message' => 'Products and fields cloned from previous event.'],
        'settings_saved' => ['type' => 'success', 'message' => 'AGM Stripe settings saved.'],
        'reg_refunded' => ['type' => 'success', 'message' => 'Registration refund submitted to Stripe.'],
        'reg_marked_paid' => ['type' => 'success', 'message' => 'Registration marked as paid.'],
        'reg_cancelled' => ['type' => 'success', 'message' => 'Registration cancelled.'],
        'feature_enabled' => ['type' => 'success', 'message' => 'AGM is now LIVE for the public.'],
        'feature_disabled' => ['type' => 'success', 'message' => 'AGM is now disabled — public pages show a coming-soon placeholder.'],
    ];
    $flash = $flashMessages[$_GET['msg']] ?? null;
}
if (!empty($_GET['err'])) {
    $flash = ['type' => 'error', 'message' => urldecode($_GET['err'])];
}

$pageTitle = 'AGM';
$activePage = 'agm-' . $tab;
require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
    <main class="flex-1 overflow-y-auto bg-background-light relative">
        <?php $topbarTitle = 'AGM'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="font-display text-3xl font-bold text-gray-900">Annual General Meeting</h1>
                    <p class="text-sm text-gray-500 mt-1">Configure the current AGM, manage registrations, archive past events.</p>
                </div>
                <?php if ($activeEvents): ?>
                    <form method="get" class="flex items-center gap-2">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                        <label class="text-sm font-medium text-gray-700">Editing event:</label>
                        <select name="event_id" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <?php foreach ($activeEvents as $event): ?>
                                <option value="<?= (int) $event['id'] ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                                    <?= e($event['year'] . ' — ' . $event['title']) ?><?= (int) $event['is_current'] === 1 ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
                <div class="rounded-2xl border <?= $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700' ?> p-4 text-sm">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if (!$agmFeatureEnabled): ?>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 flex items-start gap-3">
                    <span class="material-icons-outlined">visibility_off</span>
                    <div class="flex-1">
                        <strong>AGM is disabled for the public.</strong>
                        The <code>/agm/</code> landing page shows a "coming soon" placeholder and the registration form is unavailable. You can still configure everything here.
                        <a href="?tab=settings<?= $selectedEventId > 0 ? '&event_id=' . $selectedEventId : '' ?>" class="font-semibold underline ml-1">Enable in Settings →</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$selectedEvent && $tab !== 'event' && $tab !== 'settings'): ?>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                    <p class="text-sm text-amber-800 font-semibold mb-2">No AGM event yet.</p>
                    <p class="text-sm text-amber-700 mb-4">Create your first AGM event to start configuring products, the registration page, and submissions.</p>
                    <a href="?tab=event" class="inline-block rounded-lg bg-amber-600 text-white px-4 py-2 text-sm font-medium">Create AGM event</a>
                </div>
            <?php else: ?>
                <nav class="border-b border-gray-200">
                    <div class="-mb-px flex flex-wrap gap-x-6 text-sm">
                        <?php
                        $tabLabels = [
                            'dashboard' => 'Dashboard',
                            'event' => 'Event Setup',
                            'content' => 'Content',
                            'products' => 'Products & Pricing',
                            'fields' => 'Form Fields',
                            'submissions' => 'Submissions',
                            'archive' => 'Archive',
                            'settings' => 'Settings',
                        ];
                        foreach ($tabLabels as $key => $label):
                            $isActive = $tab === $key;
                            $href = '?tab=' . urlencode($key) . ($selectedEventId > 0 ? '&event_id=' . $selectedEventId : '');
                        ?>
                            <a href="<?= e($href) ?>" class="border-b-2 py-3 font-medium <?= $isActive ? 'border-primary text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                <?= e($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </nav>

                <?php
                $tabFile = __DIR__ . '/tabs/' . $tab . '.php';
                if (is_file($tabFile)) {
                    include $tabFile;
                } else {
                    echo '<div class="text-sm text-gray-500">Tab not found.</div>';
                }
                ?>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
