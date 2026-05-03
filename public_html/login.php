<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AuthService;
use App\Services\Csrf;
use App\Services\Validator;

$error = '';
$success = '';
if (isset($_GET['reset'])) {
  $success = 'Password updated. Please log in with your new password.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!Csrf::verify($token)) {
    $error = 'Invalid CSRF token.';
  } else {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!Validator::length($identifier, 1, 150) || !Validator::length($password, 6, 128)) {
      $error = 'Invalid login details.';
    } else {
      $result = AuthService::attemptLogin($identifier, $password);
      if ($result['status'] === 'ok') {
        if (!empty($_SESSION['twofa_enroll_required'])) {
          header('Location: /member/2fa_enroll.php');
          exit;
        }
        $user = current_user();
        $adminRoles = ['admin', 'chapter_leader', 'store_manager'];
        $isAdmin = false;
        if ($user) {
          foreach ($adminRoles as $role) {
            if (in_array($role, $user['roles'], true)) {
              $isAdmin = true;
              break;
            }
          }
        }
        if ($isAdmin) {
          header('Location: /admin/index.php');
        } else {
          header('Location: /member/index.php');
        }
        exit;
      }
      if ($result['status'] === '2fa_required') {
        header('Location: /member/2fa_verify.php');
        exit;
      }
      if ($result['status'] === '2fa_email_required') {
        header('Location: /member/2fa_verify.php');
        exit;
      }
      if ($result['status'] === '2fa_enroll') {
        header('Location: /member/2fa_enroll.php');
        exit;
      }
      if ($result['status'] === '2fa_email_failed') {
        $error = 'Unable to send your verification code. Contact support.';
      } else {
        $error = $result['status'] === 'locked' ? 'Too many attempts. Try again later.' : 'Login failed.';
      }
    }
  }
}

$pageTitle = 'Member Login';
require __DIR__ . '/../app/Views/partials/header.php';
require __DIR__ . '/../app/Views/partials/nav_public.php';
?>
<main class="site-main">
  <section class="hero hero--compact">
    <div class="container">
      <div class="page-card reveal form-card">
        <h1>Member Login</h1>
        <div class="alert info" style="margin-bottom: 20px; font-size: 0.9em;">
          <strong>New Member?</strong> You must set your password using the link sent to your email before you can log
          in.
        </div>
        <?php if ($success): ?>
          <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <div class="form-group">
            <label>Email or Member ID</label>
            <input type="text" name="identifier" required>
          </div>
          <div class="form-group">
            <label>Password</label>
            <div style="position:relative;">
              <input type="password" name="password" id="login-password" required style="padding-right:2.5rem;width:100%;box-sizing:border-box;">
              <button type="button" id="toggle-password" aria-label="Show password" onclick="(function(){var i=document.getElementById('login-password'),open=i.type==='password';i.type=open?'text':'password';document.getElementById('eye-icon').innerHTML=open?'<path d=\'M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22\'/>':'<path d=\'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z\'/><circle cx=\'12\' cy=\'12\' r=\'3\'/>';document.getElementById('toggle-password').setAttribute('aria-label',open?'Hide password':'Show password')})()" style="position:absolute;right:0.5rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#666;padding:0.25rem;display:flex;align-items:center;">
                <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <button class="button primary" type="submit">Login</button>
        </form>
        <p class="form-footer"><a href="/member/reset_password.php">Forgot password?</a></p>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/../app/Views/partials/footer.php'; ?>