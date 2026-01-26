<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\BaseUrlService;
use App\Services\Csrf;
use App\Services\NotificationService;
use App\Services\SecuritySettingsService;
use App\Services\SettingsService;
use App\Services\Validator;
use App\Services\LogViewerService;

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!Validator::email($email)) {
            $error = 'Please enter a valid email.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id, member_id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            $settings = SecuritySettingsService::get();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ipWindow = (int) $settings['login_ip_window_minutes'];
            $accountWindow = (int) $settings['login_account_window_minutes'];
            $ipMax = (int) $settings['login_ip_max_attempts'];
            $accountMax = (int) $settings['login_account_max_attempts'];
            $rateLimitDisabled = SettingsService::getGlobal('advanced.disable_password_reset_rate_limit', false);
            $rateLimited = false;
            if (!$rateLimitDisabled) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE ip_address = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)');
                $stmt->bindValue(':ip', $ip);
                $stmt->bindValue(':window', $ipWindow, \PDO::PARAM_INT);
                $stmt->execute();
                if ($ipMax > 0 && (int) $stmt->fetchColumn() >= $ipMax) {
                    $rateLimited = true;
                    ActivityLogger::log('system', null, null, 'security.password_reset_rate_limited', ['ip' => $ip]);
                }
            }
            if (!$rateLimited && $user) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)');
                $stmt->bindValue(':user_id', $user['id'], \PDO::PARAM_INT);
                $stmt->bindValue(':window', $accountWindow, \PDO::PARAM_INT);
                $stmt->execute();
                if ($rateLimitDisabled || $accountMax <= 0 || (int) $stmt->fetchColumn() < $accountMax) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, ip_address) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), :ip)');
                    $stmt->execute([
                        'user_id' => $user['id'],
                        'token_hash' => $tokenHash,
                        'ip' => $ip,
                    ]);
                    $link = BaseUrlService::emailLink('/member/reset_password_confirm.php?token=' . urlencode($token));
                    $sent = NotificationService::dispatch('member_password_reset_self', [
                        'primary_email' => $email,
                        'admin_emails' => NotificationService::getAdminEmails(),
                        'reset_link' => NotificationService::escape($link),
                        'member_id' => $user['member_id'] ?? null,
                    ]);
                    if (!$sent) {
                        LogViewerService::write('[Member] Password reset email not sent for ' . $email . '.');
                        error_log('[Member] Password reset email not sent for ' . $email . '.');
                        ActivityLogger::log('system', null, null, 'security.password_reset_email_failed', ['email' => $email]);
                    } else {
                        LogViewerService::write('[Member] Password reset email sent for ' . $email . '.');
                    }
                    ActivityLogger::log('system', null, null, 'security.password_reset_request', ['user_id' => $user['id']]);
                }
            }
            $message = 'If the email exists, a reset link has been sent.';
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
        <h1>Reset Password</h1>
        <?php if ($message): ?>
          <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
          </div>
          <button class="button primary" type="submit">Send reset link</button>
        </form>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
