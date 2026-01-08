<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\Csrf;
use App\Services\PasswordPolicyService;
use App\Services\SecuritySettingsService;

$token = $_GET['token'] ?? '';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $settings = SecuritySettingsService::get();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $window = (int) $settings['login_ip_window_minutes'];
        $maxAttempts = (int) $settings['login_ip_max_attempts'];
        $pdo = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'security.password_reset_attempt' AND ip_address = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)");
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':window', $window, \PDO::PARAM_INT);
        $stmt->execute();
        if ($maxAttempts > 0 && (int) $stmt->fetchColumn() >= $maxAttempts) {
            $error = 'Too many attempts. Please try again later.';
        }
        if ($error) {
            ActivityLogger::log('system', null, null, 'security.password_reset_attempt', ['ip' => $ip]);
        } else {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $errors = PasswordPolicyService::validate($password);
        if ($errors) {
            $error = $errors[0];
        } else {
            $tokenHash = hash('sha256', $token);
            $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = :token_hash AND expires_at > NOW() AND used_at IS NULL ORDER BY created_at DESC LIMIT 1');
            $stmt->execute(['token_hash' => $tokenHash]);
            $reset = $stmt->fetch();
            if ($reset) {
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $stmt->execute([
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $reset['user_id'],
                ]);
                $stmt = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => $reset['id']]);
                ActivityLogger::log('system', null, null, 'security.password_reset_completed', ['user_id' => $reset['user_id']]);
                $message = 'Password updated. You can now log in.';
            } else {
                $error = 'Invalid or expired token.';
            }
        }
        ActivityLogger::log('system', null, null, 'security.password_reset_attempt', ['ip' => $ip]);
        }
    }
}

$pageTitle = 'Reset Password';
require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container">
      <div class="page-card reveal form-card">
        <h1>Set a New Password</h1>
        <?php if ($message): ?>
          <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="form-group">
            <label>New Password</label>
            <input type="password" name="password" required>
          </div>
          <button class="button primary" type="submit">Update password</button>
        </form>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
