<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\AuthService;
use App\Services\Csrf;
use App\Services\StepUpService;
use App\Services\TwoFactorService;

/**
 * Finish a successful step-up: replay a preserved POST if one was stashed (so
 * the form submission that triggered the step-up isn't lost), otherwise redirect
 * to the stored return URL.
 */
function stepup_finish(): void
{
    $replay = $_SESSION['stepup_replay'] ?? null;
    unset($_SESSION['stepup_replay']);
    $redirect = $_SESSION['stepup_redirect'] ?? '/admin/index.php';
    unset($_SESSION['stepup_redirect']);

    if (is_array($replay) && !empty($replay['url']) && !empty($replay['post']) && is_array($replay['post'])) {
        stepup_render_replay((string) $replay['url'], $replay['post']);
        exit;
    }
    header('Location: ' . $redirect);
    exit;
}

/** Flatten nested POST data into bracketed field names (e.g. bikes[0][make]). */
function stepup_flatten_post(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $key => $val) {
        $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
        if (is_array($val)) {
            $out += stepup_flatten_post($val, $name);
        } else {
            $out[$name] = (string) $val;
        }
    }
    return $out;
}

/** Emit a self-submitting form that re-POSTs the preserved fields to $url. */
function stepup_render_replay(string $url, array $post): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Continuing…</title></head>';
    echo '<body onload="document.forms[0].submit()"><form method="post" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    foreach (stepup_flatten_post($post) as $name => $value) {
        echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<noscript><p>Continuing your submission…</p><button type="submit">Continue</button></noscript>';
    echo '</form></body></html>';
}

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
