<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AuthService;
use App\Services\Csrf;
use App\Services\StepUpService;
use App\Services\TwoFactorService;

require_login();
$user = current_user();
$userId = (int) ($user['id'] ?? 0);

if (StepUpService::isValid($userId)) {
    $redirect = $_SESSION['stepup_redirect'] ?? '/admin/index.php';
    unset($_SESSION['stepup_redirect']);
    header('Location: ' . $redirect);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $password = $_POST['password'] ?? '';
        $code = trim($_POST['code'] ?? '');
        $ok = AuthService::verifyPassword($userId, $password);
        if ($ok && TwoFactorService::isEnabled($userId)) {
            $ok = TwoFactorService::verifyCode($userId, $code) || TwoFactorService::verifyRecoveryCode($userId, $code);
        }
        if ($ok) {
            StepUpService::issue($userId);
            $redirect = $_SESSION['stepup_redirect'] ?? '/admin/index.php';
            unset($_SESSION['stepup_redirect']);
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Step-up verification failed.';
    }
}

$pageTitle = 'Step-up verification';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container">
      <div class="page-card reveal form-card">
        <h1>Confirm your identity</h1>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
          </div>
          <div class="form-group">
            <label>Authenticator or recovery code</label>
            <input type="text" name="code" autocomplete="one-time-code">
          </div>
          <button class="button primary" type="submit">Verify</button>
        </form>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>
