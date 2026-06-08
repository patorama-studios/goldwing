<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AiProviderKeyService;
use App\Services\AuditService;
use App\Services\Csrf;
use App\Services\EncryptionService;
use App\Services\SettingsService;

require_permission('admin.settings.general.manage');

$user = current_user();
$message = '';
$error = '';

$provider = 'kie';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $model = trim((string) ($_POST['model'] ?? ''));
        if ($model === '') {
            $model = 'claude-sonnet-4-6';
        }
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        $imageEnabled = isset($_POST['image_generation_enabled']);
        $monthlyCap = isset($_POST['monthly_cap_usd']) ? (float) $_POST['monthly_cap_usd'] : 0.0;
        $tokenRate = isset($_POST['token_cost_usd']) ? (float) $_POST['token_cost_usd'] : 0.0;
        $imageCost = isset($_POST['image_cost_usd']) ? (float) $_POST['image_cost_usd'] : 0.0;
        $guardrails = trim((string) ($_POST['ai_guardrails'] ?? ''));
        $masterPrompt = trim((string) ($_POST['ai_builder_master_prompt'] ?? ''));

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
            SettingsService::setGlobal((int) $user['id'], 'ai.builder_master_prompt', $masterPrompt);
            if ($apiKey !== '') {
                AiProviderKeyService::upsertKey($provider, $apiKey, (int) $user['id']);
            }

            AuditService::log((int) $user['id'], 'settings_change', 'AI settings updated.');
            $message = 'AI settings saved.';
        }
    }
}

SettingsService::setGlobal((int) $user['id'], 'ai.provider', 'kie');
$model = SettingsService::getGlobal('ai.model', 'claude-sonnet-4-6');
if (!is_string($model) || trim((string) $model) === '') {
    $model = 'claude-sonnet-4-6';
}
$imageEnabled = SettingsService::getGlobal('ai.image_generation_enabled', false);
$monthlyCap = (float) SettingsService::getGlobal('ai.monthly_cap_usd', 50);
$tokenRate = (float) SettingsService::getGlobal('ai.token_cost_usd', 0.01);
$imageCost = (float) SettingsService::getGlobal('ai.image_cost_usd', 0.04);
$guardrails = (string) SettingsService::getGlobal('ai.guardrails', '');
$masterPrompt = (string) SettingsService::getGlobal('ai.builder_master_prompt', '');
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
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <?php if ($message): ?>
        <div class="rounded-lg bg-green-50 text-green-700 px-4 py-2 text-sm"><?= e($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="rounded-lg bg-red-50 text-red-700 px-4 py-2 text-sm"><?= e($error) ?></div>
      <?php endif; ?>

      <section class="space-y-6">
        <div class="bg-card-light rounded-2xl border border-gray-100 p-6">
          <div class="flex items-center justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl font-bold text-gray-900"><?= e($pageTitle) ?></h1>
              <nav class="mt-1 flex items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="/admin/" class="hover:text-slate-700">Admin</a>
                <span class="text-slate-300">/</span>
                <a href="/admin/settings/" class="hover:text-slate-700">Settings</a>
                <span class="text-slate-300">/</span>
                <span class="font-semibold text-gray-900 border-b-2 border-primary pb-0.5">AI</span>
              </nav>
            </div>
            <div class="inline-flex items-center gap-2 rounded-full <?= $meta['configured'] ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' ?> px-3 py-1.5 text-xs font-semibold whitespace-nowrap">
              <span class="h-2 w-2 rounded-full <?= $meta['configured'] ? 'bg-green-500' : 'bg-amber-500' ?>"></span>
              <span>kie.ai &middot; <?= $meta['configured'] ? 'Connected' : 'Not connected' ?></span>
            </div>
          </div>
        </div>

        <?php if (!$encryptionReady): ?>
          <div class="rounded-lg bg-amber-50 text-amber-700 px-4 py-3 text-sm">APP_KEY is missing. Set it in <code>.env</code> before saving API keys.</div>
        <?php endif; ?>

        <form method="post" class="space-y-6 pb-24">
          <input type="hidden" name="csrf_token" value="<?= e(Csrf::token()) ?>">

          <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
            <div class="flex items-start gap-3">
              <span class="material-icons-outlined text-slate-500">auto_awesome</span>
              <div>
                <h2 class="font-display text-lg font-bold text-gray-900">Provider &amp; Model</h2>
                <p class="text-sm text-slate-500">The AI page builder uses <strong>kie.ai</strong> with Claude Sonnet 4.6. It is restricted to creating and editing pages only and cannot modify site code or any other admin area.</p>
              </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Provider</label>
                <input type="text" value="kie.ai" readonly class="mt-2 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-600">
                <p class="mt-1 text-xs text-slate-400">Provider is fixed to kie.ai.</p>
              </div>
              <div>
                <label for="ai_model" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Model</label>
                <input id="ai_model" type="text" name="model" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono" value="<?= e((string) $model) ?>" placeholder="claude-sonnet-4-6">
                <p class="mt-1 text-xs text-slate-400">Default is <code>claude-sonnet-4-6</code> (Claude Sonnet 4.6 via kie.ai).</p>
              </div>
            </div>
            <div>
              <label for="ai_api_key" class="text-xs font-semibold uppercase tracking-wider text-slate-500">kie.ai API Key</label>
              <input id="ai_api_key" type="password" name="api_key" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm font-mono" placeholder="<?= $meta['configured'] ? 'Key configured (last 4: ' . e((string) $meta['last4']) . ')' : 'Paste your kie.ai API key' ?>">
              <p class="mt-1 text-xs text-slate-400">Stored encrypted. Leave blank to keep the existing key.</p>
            </div>
          </div>

          <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
            <div class="flex items-start gap-3">
              <span class="material-icons-outlined text-slate-500">payments</span>
              <div>
                <h2 class="font-display text-lg font-bold text-gray-900">Usage &amp; Cost Controls</h2>
                <p class="text-sm text-slate-500">Spend cap and per-unit rates used to estimate AI usage.</p>
              </div>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
              <div>
                <label for="ai_monthly_cap" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Monthly Cap (USD)</label>
                <input id="ai_monthly_cap" type="number" step="0.01" min="0" name="monthly_cap_usd" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(number_format($monthlyCap, 2, '.', '')) ?>">
              </div>
              <div>
                <label for="ai_token_cost" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cost per 1K tokens (USD)</label>
                <input id="ai_token_cost" type="number" step="0.001" min="0" name="token_cost_usd" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(number_format($tokenRate, 3, '.', '')) ?>">
              </div>
              <div>
                <label for="ai_image_cost" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Image cost (USD)</label>
                <input id="ai_image_cost" type="number" step="0.01" min="0" name="image_cost_usd" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" value="<?= e(number_format($imageCost, 2, '.', '')) ?>">
              </div>
            </div>
            <label class="flex items-start justify-between gap-3 rounded-lg p-3 hover:bg-gray-50 cursor-pointer">
              <div>
                <div class="text-sm font-medium text-gray-900">Enable image generation via kie.ai</div>
                <div class="text-xs text-slate-500">Allow the AI builder to request rendered images</div>
              </div>
              <input type="checkbox" name="image_generation_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" <?= $imageEnabled ? 'checked' : '' ?>>
            </label>
          </div>

          <div class="bg-card-light rounded-2xl border border-gray-100 p-6 space-y-5">
            <div class="flex items-start gap-3">
              <span class="material-icons-outlined text-slate-500">shield</span>
              <div>
                <h2 class="font-display text-lg font-bold text-gray-900">Behaviour &amp; Guardrails</h2>
                <p class="text-sm text-slate-500">Master prompt and additional rules layered on top of the built-in scope lock.</p>
              </div>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              The AI builder is hard-locked to page content only. It cannot touch PHP, server code, the admin/codebase, or anything outside the page draft.
            </div>
            <div>
              <label for="ai_master_prompt" class="text-xs font-semibold uppercase tracking-wider text-slate-500">AI Builder Master Prompt</label>
              <textarea id="ai_master_prompt" name="ai_builder_master_prompt" rows="5" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" placeholder="Describe the overall behavior/personality of the AI builder."><?= e($masterPrompt) ?></textarea>
            </div>
            <div>
              <label for="ai_guardrails" class="text-xs font-semibold uppercase tracking-wider text-slate-500">AI Guardrails</label>
              <textarea id="ai_guardrails" name="ai_guardrails" rows="5" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm" placeholder="Extra rules layered on top of the built-in scope lock."><?= e($guardrails) ?></textarea>
            </div>
          </div>

          <div class="sticky bottom-4 z-10 bg-card-light rounded-2xl border border-gray-100 shadow-soft p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="text-xs text-slate-500 flex items-center gap-2">
              <span class="material-icons-outlined text-base text-slate-400">info</span>
              kie.ai key is stored encrypted at rest.
            </div>
            <div class="flex items-center gap-3">
              <a href="/admin/settings/ai.php" class="text-sm font-medium text-slate-600 hover:text-slate-900 px-4 py-2">Cancel</a>
              <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-gray-900 hover:bg-gray-800 px-4 py-2 text-sm font-semibold text-white shadow-sm">
                <span class="material-icons-outlined text-base">save</span>
                Save Settings
              </button>
            </div>
          </div>
        </form>
      </section>
    </div>
  </main>
</div>
<?php require __DIR__ . '/../../../app/Views/partials/backend_footer.php'; ?>
