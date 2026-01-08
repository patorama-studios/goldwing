<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AuthService;
use App\Services\Csrf;
use App\Services\TwoFactorService;
use App\Services\EmailOtpService;
use App\Services\Database;

$pending = $_SESSION['auth_pending'] ?? null;
if (!$pending || empty($pending['user_id'])) {
    header('Location: /login.php');
    exit;
}

$error = '';
$message = '';
$purpose = $pending['purpose'] ?? 'verify';
$isEmailOtp = $purpose === 'email_otp';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $userId = (int) $pending['user_id'];
        if ($isEmailOtp && ($_POST['action'] ?? '') === 'resend_email_otp') {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT email, name FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['email'])) {
                $issued = EmailOtpService::issueCode($userId, (string) $row['email'], (string) ($row['name'] ?? 'Member'));
                if ($issued['success']) {
                    $message = 'A new verification code has been sent.';
                } else {
                    $error = $issued['error'] ?? 'Unable to resend the code.';
                }
            } else {
                $error = 'Unable to resend the code.';
            }
        } else {
            $code = trim($_POST['code'] ?? '');
            $recovery = trim($_POST['recovery_code'] ?? '');
            $verified = false;
            if ($isEmailOtp) {
                if ($code !== '') {
                    $verified = EmailOtpService::verifyCode($userId, $code);
                }
            } else {
                if ($code !== '') {
                    $verified = TwoFactorService::verifyCode($userId, $code);
                } elseif ($recovery !== '') {
                    $verified = TwoFactorService::verifyRecoveryCode($userId, $recovery);
                }
            }
            if ($verified) {
                if ($isEmailOtp && isset($_POST['trust_device'])) {
                    EmailOtpService::trustDevice($userId);
                }
                if (AuthService::completeTwoFactorLogin()) {
                    $user = current_user();
                    $adminRoles = ['admin', 'committee', 'treasurer', 'chapter_leader', 'super_admin', 'store_manager'];
                    $isAdmin = false;
                    if ($user) {
                        foreach ($adminRoles as $role) {
                            if (in_array($role, $user['roles'], true)) {
                                $isAdmin = true;
                                break;
                            }
                        }
                    }
                    header('Location: ' . ($isAdmin ? '/admin/index.php' : '/member/index.php'));
                    exit;
                }
                $error = 'Unable to complete login.';
            } else {
                $error = $isEmailOtp ? 'Invalid verification code.' : 'Invalid authentication code.';
            }
        }
    }
}

$pageTitle = 'Verify two-factor authentication';
require __DIR__ . '/../../app/Views/partials/header.php';
require __DIR__ . '/../../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container">
      <div class="page-card reveal form-card">
        <h1>Two-factor verification</h1>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
          <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <div class="form-group">
            <label><?= $isEmailOtp ? 'Email verification code' : 'Authenticator code' ?></label>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code">
          </div>
          <?php if (!$isEmailOtp): ?>
            <div class="form-group">
              <label>Recovery code (optional)</label>
              <input type="text" name="recovery_code" autocomplete="one-time-code">
            </div>
          <?php else: ?>
            <label class="form-group">
              <input type="checkbox" name="trust_device">
              <span>Trust this device for 30 days</span>
            </label>
          <?php endif; ?>
          <button class="button primary" type="submit">Verify</button>
          <?php if ($isEmailOtp): ?>
            <button class="button" type="submit" name="action" value="resend_email_otp">Resend code</button>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../../app/Views/partials/footer.php'; ?>
