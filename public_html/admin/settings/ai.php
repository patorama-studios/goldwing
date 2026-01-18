<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AiProviderKeyService;
use App\Services\AuditService;
use App\Services\Csrf;
use App\Services\EncryptionService;
use App\Services\SettingsService;

require_role(['admin', 'committee']);

$user = current_user();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $provider = strtolower(trim((string) ($_POST['provider'] ?? 'openai')));
        if (!in_array($provider, ['openai', 'gemini'], true)) {
            $provider = 'openai';
        }
        $model = trim((string) ($_POST['model'] ?? ''));
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $imageEnabled = isset($_POST['image_generation_enabled']);
        $monthlyCap = isset($_POST['monthly_cap_usd']) ? (float) $_POST['monthly_cap_usd'] : 0.0;
        $tokenRate = isset($_POST['token_cost_usd']) ? (float) $_POST['token_cost_usd'] : 0.0;
        $imageCost = isset($_POST['image_cost_usd']) ? (float) $_POST['image_cost_usd'] : 0.0;
        $guardrails = trim((string) ($_POST['ai_guardrails'] ?? ''));

        if ($apiKey !== '' && !EncryptionService::isReady()) {
            $error = 'APP_KEY is not configured. Set APP_KEY in .env to store API keys securely.';
        } else {
            SettingsService::setGlobal((int) $user['id'], 'ai.provider', $provider);
            SettingsService::setGlobal((int) $user['id'], 'ai.model', $model);
            SettingsService::setGlobal((int) $user['id'], 'ai.image_generation_enabled', $imageEnabled);
            SettingsService::setGlobal((int) $user['id'], 'ai.monthly_cap_usd', $monthlyCap);
            SettingsService::setGlobal((int) $user['id'], 'ai.token_cost_usd', $tokenRate);
            SettingsService::setGlobal((int) $user['id'], 'ai.image_cost_usd', $imageCost);
            SettingsService::setGlobal((int) $user['id'], 'ai.guardrails', $guardrails);
            AiProviderKeyService::upsertKey($provider, $apiKey, (int) $user['id']);

            AuditService::log((int) $user['id'], 'settings_change', 'AI settings updated.');
            $message = 'AI settings saved.';
        }
    }
}

$provider = SettingsService::getGlobal('ai.provider', 'openai');
$model = SettingsService::getGlobal('ai.model', '');
$imageEnabled = SettingsService::getGlobal('ai.image_generation_enabled', false);
$monthlyCap = (float) SettingsService::getGlobal('ai.monthly_cap_usd', 50);
$tokenRate = (float) SettingsService::getGlobal('ai.token_cost_usd', 0.01);
$imageCost = (float) SettingsService::getGlobal('ai.image_cost_usd', 0.02);
$guardrails = (string) SettingsService::getGlobal('ai.guardrails', '');
$meta = AiProviderKeyService::getMeta($provider);
$encryptionReady = EncryptionService::isReady();

$pageTitle = 'AI Settings';
$activePage = 'settings-ai';

require __DIR__ . '/../../../app/Views/partials/backend_head.php';
?>
<div class="flex h-screen overflow-hidden">
  <?php require __DIR__ . '/../../../app/Views/partials/backend_admin_sidebar.php'; ?>
  <main class="flex-1 overflow-y-auto bg-background-light relative">
    <?php $topbarTitle = 'AI Settings'; require __DIR__ . '/../../../app/Views/partials/backend_mobile_topbar.php'; ?>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($message): ?>
        <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($error) ?></div>
      <?php endif; ?>
      <section class="bg-card-light rounded-2xl p-6 shadow-sm border border-gray-100 space-y-4">
        <div>
          <h1 class="font-display text-2xl font-bold text-gray-900">AI Settings</h1>
          <p class="text-sm text-gray-500">Configure the AI provider and model for the page builder.</p>
        </div>
        <?php if (!$encryptionReady): ?>
          <div class="rounded-lg bg-amber-50 text-amber-700 px-4 py-2 text-sm">APP_KEY is missing. Set it in `.env` before saving API keys.</div>
        <?php endif; ?>
        <form method="post" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Provider</label>
            <select name="provider" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
              <option value="openai" <?= $provider === 'openai' ? 'selected' : '' ?>>ChatGPT</option>
              <option value="gemini" <?= $provider === 'gemini' ? 'selected' : '' ?>>Gemini</option>
            </select>
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Model</label>
            <input type="text" name="model" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e((string) $model) ?>" placeholder="e.g. gpt-4o-mini">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">API Key</label>
            <input type="password" name="api_key" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="<?= $meta['configured'] ? 'Key configured (last 4: ' . e((string) $meta['last4']) . ')' : 'Enter API key' ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Monthly Cap (USD)</label>
            <input type="number" step="0.01" min="0" name="monthly_cap_usd" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(number_format($monthlyCap, 2, '.', '')) ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Cost per 1K tokens (USD)</label>
            <input type="number" step="0.001" min="0" name="token_cost_usd" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(number_format($tokenRate, 3, '.', '')) ?>">
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">Image cost (USD)</label>
            <input type="number" step="0.01" min="0" name="image_cost_usd" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" value="<?= e(number_format($imageCost, 2, '.', '')) ?>">
          </div>
          <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Changing guardrails will affect how the AI builder behaves across all pages.
          </div>
          <div>
            <label class="text-xs uppercase tracking-[0.2em] text-slate-500">AI Guardrails</label>
            <textarea name="ai_guardrails" rows="5" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" placeholder="Rules for the AI to follow."><?= e($guardrails) ?></textarea>
          </div>
          <label class="inline-flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" name="image_generation_enabled" class="rounded border-gray-200" <?= $imageEnabled ? 'checked' : '' ?>>
            Enable image generation
          </label>
          <div>
            <button class="inline-flex items-center px-4 py-2 rounded-lg bg-primary text-gray-900 text-sm font-semibold" type="submit">Save Settings</button>
          </div>
        </form>
      </section>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
