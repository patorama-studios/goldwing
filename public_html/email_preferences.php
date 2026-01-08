<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\Database;
use App\Services\EmailPreferencesTokenService;
use App\Services\NotificationPreferenceService;

$token = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');
$tokenData = $token !== '' ? EmailPreferencesTokenService::validateToken($token) : null;
$error = '';
$message = '';
$member = null;
$userId = 0;

if (!$tokenData) {
    $error = 'This link is invalid or has expired.';
} else {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, user_id, first_name, last_name, email FROM members WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $tokenData['member_id']]);
    $member = $stmt->fetch();
    if (!$member || empty($member['user_id'])) {
        $error = 'We could not locate your account.';
    } else {
        $userId = (int) $member['user_id'];
    }
}

if (!$error && $tokenData && $tokenData['purpose'] === 'unsubscribe' && $action === 'unsubscribe') {
    $prefs = NotificationPreferenceService::load($userId);
    $prefs['unsubscribe_all_non_essential'] = true;
    NotificationPreferenceService::save($userId, $prefs);
    ActivityLogger::log('system', null, (int) $member['id'], 'notification.unsubscribe_all', [
        'visibility' => 'member',
    ]);
    $message = 'You have been unsubscribed from all non-essential emails.';
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $categories = [];
        foreach (NotificationPreferenceService::categories() as $key => $label) {
            $categories[$key] = isset($_POST['notify_category'][$key]);
        }
        $prefs = [
            'master_enabled' => isset($_POST['notify_master_enabled']),
            'unsubscribe_all_non_essential' => isset($_POST['notify_unsubscribe_all']),
            'categories' => $categories,
        ];
        NotificationPreferenceService::save($userId, $prefs);
        ActivityLogger::log('system', null, (int) $member['id'], 'notification.preferences_updated', [
            'visibility' => 'member',
        ]);
        $message = 'Your email preferences have been updated.';
    }
}

$prefs = $userId ? NotificationPreferenceService::load($userId) : NotificationPreferenceService::defaultPreferences();
$categories = NotificationPreferenceService::categories();
$pageTitle = 'Email Preferences';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container">
      <div class="page-card reveal form-card">
        <h1>Email preferences</h1>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php elseif ($message): ?>
          <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$error): ?>
          <p class="text-sm text-gray-600 mt-3">Manage which categories you want to hear about. Mandatory security and billing emails always send.</p>
          <form method="post" class="space-y-4 mt-4">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <label class="form-group">
              <span class="text-sm font-medium text-gray-700">Email notifications</span>
              <input type="checkbox" name="notify_master_enabled" <?= !empty($prefs['master_enabled']) ? 'checked' : '' ?>>
            </label>
            <label class="form-group">
              <span class="text-sm font-medium text-gray-700">Unsubscribe from all non-essential emails</span>
              <input type="checkbox" name="notify_unsubscribe_all" <?= !empty($prefs['unsubscribe_all_non_essential']) ? 'checked' : '' ?>>
            </label>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <?php foreach ($categories as $key => $label): ?>
                <label class="form-group">
                  <input type="checkbox" name="notify_category[<?= e($key) ?>]" <?= !empty($prefs['categories'][$key]) ? 'checked' : '' ?>>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <button class="button primary" type="submit">Save preferences</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>
