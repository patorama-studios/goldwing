<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\AuthService;
use App\Services\Csrf;
use App\Services\SettingsService;
use App\Services\TwoFactorService;
use App\Services\TotpService;

$pending = $_SESSION['auth_pending'] ?? null;
$user = current_user();
$userId = $user['id'] ?? ($pending['user_id'] ?? null);

if (!$userId) {
    header('Location: /login.php');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch();
if (!$userRow) {
    header('Location: /login.php');
    exit;
}

$error = '';
$message = '';
$recoveryCodes = [];
$twofaRow = TwoFactorService::getUser((int) $userId);
if ($twofaRow && !empty($twofaRow['enabled_at'])) {
    header('Location: /member/index.php');
    exit;
}

if (!$twofaRow || empty($twofaRow['totp_secret_encrypted'])) {
    TwoFactorService::beginEnrollment((int) $userId);
}
$secret = TwoFactorService::getSecret((int) $userId);
if ($secret === '') {
    $error = '2FA encryption is not configured. Contact support.';
}
$issuer = SettingsService::getGlobal('site.name', 'Goldwing Association');
$otpAuthUrl = TotpService::getOtpAuthUrl($issuer, $userRow['email'], $secret);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpAuthUrl);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $secret !== '') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $code = trim($_POST['code'] ?? '');
        $result = TwoFactorService::verifyAndEnable((int) $userId, $code);
        if (!$result['success']) {
            $error = $result['error'] ?? 'Unable to enable 2FA.';
        } else {
            $recoveryCodes = $result['recovery_codes'] ?? [];
            $message = 'Two-factor authentication is now enabled.';
            ActivityLogger::log('member', (int) $userId, null, 'security.2fa_enabled');
            if ($pending) {
                AuthService::completeTwoFactorLogin();
            }
        }
    }
}

$pageTitle = 'Set up two-factor authentication';
require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container">
      <div class="page-card reveal form-card">
        <h1>Set up two-factor authentication</h1>
        <?php if ($message): ?>
          <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($recoveryCodes): ?>
          <p class="text-sm text-gray-700">Save these recovery codes in a secure place.</p>
          <ul class="mt-2 grid grid-cols-2 gap-2 text-sm">
            <?php foreach ($recoveryCodes as $code): ?>
              <li class="rounded bg-gray-100 px-3 py-2 font-mono"><?= e($code) ?></li>
            <?php endforeach; ?>
          </ul>
          <div class="mt-4">
            <a class="button primary" href="/member/index.php">Continue</a>
          </div>
        <?php else: ?>
          <p class="text-sm text-gray-700">Scan the QR code with your authenticator app, or enter the secret manually.</p>
          <div class="mt-4 flex flex-col items-center gap-3">
            <img src="<?= e($qrUrl) ?>" alt="2FA QR code">
            <div class="rounded bg-gray-100 px-3 py-2 font-mono text-sm"><?= e($secret) ?></div>
          </div>
          <form method="post" class="mt-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
            <div class="form-group">
              <label>Authenticator code</label>
              <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button class="button primary" type="submit">Enable 2FA</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
