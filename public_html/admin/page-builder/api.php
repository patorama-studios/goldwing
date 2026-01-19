<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Services\AiProviderKeyService;
use App\Services\AiProviders\AiProviderFactory;
use App\Services\AuditService;
use App\Services\Csrf;
use App\Services\PageBuilderService;
use App\Services\PageService;
use App\Services\SettingsService;

require_role(['admin', 'committee']);

header('Content-Type: application/json');

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function require_csrf(array $body): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? '');
    if (!Csrf::verify($token)) {
        json_response(['error' => 'Invalid CSRF token.'], 403);
    }
}

function load_page_or_fail(int $pageId): array
{
    $page = PageService::getById($pageId);
    if (!$page) {
        json_response(['error' => 'Page not found.'], 404);
    }
    if (empty($page['slug'])) {
        json_response(['error' => 'Page is not eligible.'], 403);
    }
    return $page;
}

function load_roles(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT name FROM roles ORDER BY name')->fetchAll();
    $roles = [];
    foreach ($rows as $row) {
        $role = strtolower(trim((string) ($row['name'] ?? '')));
        if ($role !== '') {
            $roles[] = $role;
        }
    }
    return $roles;
}

function insert_chat_message(int $pageId, ?int $userId, string $role, string $content, ?string $elementId = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO page_chat_messages (page_id, user_id, role, content, selected_element_id, created_at) VALUES (:page_id, :user_id, :role, :content, :selected_element_id, NOW())');
    $stmt->execute([
        'page_id' => $pageId,
        'user_id' => $userId,
        'role' => $role,
        'content' => $content,
        'selected_element_id' => $elementId,
    ]);
}

function fetch_chat_messages(int $pageId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT role, content, selected_element_id, created_at, user_id FROM page_chat_messages WHERE page_id = :page_id ORDER BY created_at ASC');
    $stmt->execute(['page_id' => $pageId]);
    return $stmt->fetchAll() ?: [];
}

function normalize_ai_response(string $content): array
{
    $trimmed = trim($content);
    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'AI response was not valid JSON.'];
    }
    $updatedHtml = trim((string) ($decoded['updated_html'] ?? ''));
    $summary = trim((string) ($decoded['summary'] ?? 'AI update'));
    $warnings = $decoded['warnings'] ?? [];
    if ($updatedHtml === '') {
        return ['ok' => false, 'error' => 'AI response missing updated_html.'];
    }
    return [
        'ok' => true,
        'updated_html' => $updatedHtml,
        'summary' => $summary !== '' ? $summary : 'AI update',
        'warnings' => is_array($warnings) ? $warnings : [],
    ];
}

function default_template_html(string $scope): string
{
    if ($scope === 'header') {
        ob_start();
        require __DIR__ . '/../../../app/Views/partials/nav_public.php';
        return ob_get_clean() ?: '';
    }
    if ($scope === 'footer') {
        ob_start();
        require __DIR__ . '/../../../app/Views/partials/footer.php';
        $html = ob_get_clean() ?: '';
        return preg_replace('/<\/body>.*$/s', '', $html);
    }
    return '';
}

function generate_image_openai(string $prompt): array
{
    $apiKey = AiProviderKeyService::getKey('openai');
    if ($apiKey === null || $apiKey === '') {
        return ['ok' => false, 'error' => 'OpenAI API key not configured.'];
    }
    $payload = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'size' => '1024x1024',
        'response_format' => 'url',
    ];

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => $error ?: 'Image generation failed.'];
    }

    $data = json_decode($response, true);
    $url = $data['data'][0]['url'] ?? '';
    if ($url === '') {
        return ['ok' => false, 'error' => 'Image response missing URL.'];
    }

    return ['ok' => true, 'url' => $url];
}

function normalize_reference_image_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, '/')) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return null;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . $url;
    }
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
        return null;
    }
    return $url;
}

function usage_month_key(?\DateTimeInterface $date = null): string
{
    $date = $date ?: new \DateTimeImmutable('now');
    return $date->format('Y-m');
}

function usage_days_to_reset(): int
{
    $now = new \DateTimeImmutable('now');
    $startNext = $now->modify('first day of next month')->setTime(0, 0);
    $diff = $now->diff($startNext);
    return max(0, (int) $diff->days);
}

function usage_fetch(string $provider): array
{
    $pdo = db();
    $monthKey = usage_month_key();
    $stmt = $pdo->prepare('SELECT total_usd_cents, total_tokens FROM ai_usage_monthly WHERE month_key = :month_key AND provider = :provider LIMIT 1');
    $stmt->execute(['month_key' => $monthKey, 'provider' => $provider]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['usd_cents' => 0, 'tokens' => 0, 'month' => $monthKey];
    }
    return [
        'usd_cents' => (int) ($row['total_usd_cents'] ?? 0),
        'tokens' => (int) ($row['total_tokens'] ?? 0),
        'month' => $monthKey,
    ];
}

function usage_record(string $provider, int $usdCents, int $tokens): void
{
    if ($usdCents <= 0 && $tokens <= 0) {
        return;
    }
    $pdo = db();
    $monthKey = usage_month_key();
    $stmt = $pdo->prepare('INSERT INTO ai_usage_monthly (month_key, provider, total_usd_cents, total_tokens, created_at, updated_at)
        VALUES (:month_key, :provider, :usd_cents, :tokens, NOW(), NOW())
        ON DUPLICATE KEY UPDATE total_usd_cents = total_usd_cents + VALUES(total_usd_cents),
                                total_tokens = total_tokens + VALUES(total_tokens),
                                updated_at = NOW()');
    $stmt->execute([
        'month_key' => $monthKey,
        'provider' => $provider,
        'usd_cents' => $usdCents,
        'tokens' => $tokens,
    ]);
}

function usage_cost_from_raw(string $provider, array $raw, float $ratePer1k): array
{
    $tokens = 0;
    if ($provider === 'openai') {
        $tokens = (int) ($raw['usage']['total_tokens'] ?? 0);
    } elseif ($provider === 'gemini') {
        $tokens = (int) ($raw['usageMetadata']['totalTokenCount'] ?? 0);
    }
    $usd = $tokens > 0 ? ($tokens / 1000.0) * $ratePer1k : 0.0;
    return ['tokens' => $tokens, 'usd_cents' => (int) round($usd * 100)];
}

function usage_check_cap(string $provider): array
{
    $capUsd = (float) SettingsService::getGlobal('ai.monthly_cap_usd', 0);
    if ($capUsd <= 0) {
        return ['ok' => true];
    }
    $usage = usage_fetch($provider);
    $capCents = (int) round($capUsd * 100);
    if ($usage['usd_cents'] >= $capCents) {
        return [
            'ok' => false,
            'used_cents' => $usage['usd_cents'],
            'cap_cents' => $capCents,
            'days_to_reset' => usage_days_to_reset(),
        ];
    }
    return [
        'ok' => true,
        'used_cents' => $usage['usd_cents'],
        'cap_cents' => $capCents,
        'days_to_reset' => usage_days_to_reset(),
    ];
}

function normalize_page_create_response(string $content): array
{
    $decoded = json_decode(trim($content), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'AI response was not valid JSON.'];
    }
    $title = trim((string) ($decoded['title'] ?? ''));
    $slug = trim((string) ($decoded['slug'] ?? ''));
    $html = trim((string) ($decoded['html'] ?? ''));
    $summary = trim((string) ($decoded['summary'] ?? 'New page'));
    if ($html === '') {
        return ['ok' => false, 'error' => 'AI response missing html.'];
    }
    if ($title === '') {
        $title = 'New Page';
    }
    return [
        'ok' => true,
        'title' => $title,
        'slug' => $slug,
        'html' => $html,
        'summary' => $summary !== '' ? $summary : 'New page',
    ];
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'page';
}

function unique_slug(string $slug): string
{
    $pdo = db();
    $base = $slug;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$pageId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$user = current_user();

if ($method === 'GET') {
    if ($action === 'pages') {
        $pages = PageService::listEditablePages();
        $provider = SettingsService::getGlobal('ai.provider', 'openai');
        $usage = usage_fetch($provider);
        $capUsd = (float) SettingsService::getGlobal('ai.monthly_cap_usd', 0);
        $capCents = (int) round($capUsd * 100);
        $payload = [];
        foreach ($pages as $page) {
            $draft = PageService::draftHtml($page);
            $live = PageService::liveHtml($page);
            $payload[] = [
                'id' => (int) $page['id'],
                'slug' => $page['slug'],
                'title' => $page['title'],
                'has_draft_changes' => $draft !== $live,
                'access_level' => $page['access_level'] ?? 'public',
                'updated_at' => $page['updated_at'],
            ];
        }
        json_response([
            'pages' => $payload,
            'roles' => load_roles(),
            'provider' => $provider,
            'usage' => [
                'usd_cents' => $usage['usd_cents'],
                'tokens' => $usage['tokens'],
                'cap_cents' => $capCents,
                'days_to_reset' => usage_days_to_reset(),
            ],
        ]);
    }

    if ($action === 'page') {
        $page = load_page_or_fail($pageId);
        $draft = PageService::draftHtml($page);
        $draft = PageBuilderService::ensureEditableBody($page, $draft);
        $draft = PageBuilderService::ensureDraftHtml($draft);
        if ($draft !== ($page['draft_html'] ?? '')) {
            PageService::updateDraft($pageId, $draft, (string) ($page['access_level'] ?? 'public'));
        }
        $live = PageService::liveHtml($page);
        $headerTemplate = (string) SettingsService::getGlobal('ai.template_header_html', '');
        $footerTemplate = (string) SettingsService::getGlobal('ai.template_footer_html', '');
        if ($headerTemplate === '') {
            $headerTemplate = default_template_html('header');
        }
        if ($footerTemplate === '') {
            $footerTemplate = default_template_html('footer');
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, version_number, version_label, published_by_user_id, published_at FROM page_versions WHERE page_id = :page_id AND published_at IS NOT NULL ORDER BY version_number DESC');
        $stmt->execute(['page_id' => $pageId]);
        $versions = $stmt->fetchAll() ?: [];

        json_response([
            'page' => [
                'id' => (int) $page['id'],
                'slug' => $page['slug'],
                'title' => $page['title'],
                'draft_html' => $draft,
                'live_html' => $live,
                'has_draft_changes' => $draft !== $live,
                'access_level' => $page['access_level'] ?? 'public',
                'updated_at' => $page['updated_at'],
            ],
            'templates' => [
                'header' => $headerTemplate,
                'footer' => $footerTemplate,
            ],
            'versions' => $versions,
            'chat' => fetch_chat_messages($pageId),
        ]);
    }

    if ($action === 'versions') {
        $page = load_page_or_fail($pageId);
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, version_number, version_label, published_by_user_id, published_at FROM page_versions WHERE page_id = :page_id AND published_at IS NOT NULL ORDER BY version_number DESC');
        $stmt->execute(['page_id' => $pageId]);
        $versions = $stmt->fetchAll() ?: [];
        json_response(['versions' => $versions]);
    }

    if ($action === 'settings') {
        $provider = SettingsService::getGlobal('ai.provider', 'openai');
        $model = SettingsService::getGlobal('ai.model', '');
        $imageEnabled = SettingsService::getGlobal('ai.image_generation_enabled', false);
        $capUsd = (float) SettingsService::getGlobal('ai.monthly_cap_usd', 0);
        $capCents = (int) round($capUsd * 100);
        $usage = usage_fetch($provider);
        $meta = AiProviderKeyService::getMeta($provider);
        json_response([
            'provider' => $provider,
            'model' => $model,
            'image_generation_enabled' => (bool) $imageEnabled,
            'configured' => $meta['configured'] ?? false,
            'last4' => $meta['last4'] ?? null,
            'usage' => [
                'usd_cents' => $usage['usd_cents'],
                'tokens' => $usage['tokens'],
                'cap_cents' => $capCents,
                'days_to_reset' => usage_days_to_reset(),
            ],
        ]);
    }

    json_response(['error' => 'Not found.'], 404);
}

$rawUploadAction = $action === 'upload_media';
if ($rawUploadAction && $method === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!Csrf::verify($token)) {
        json_response(['error' => 'Invalid CSRF token.'], 403);
    }
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_response(['error' => 'No file uploaded.'], 422);
    }

    $allowedTypes = SettingsService::getGlobal('media.allowed_types', []);
    if (!is_array($allowedTypes) || empty($allowedTypes)) {
        $allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/html',
            'application/xhtml+xml',
            'text/plain',
        ];
    }
    $file = $_FILES['file'];
    if (!empty($file['error'])) {
        json_response(['error' => 'Upload failed.'], 422);
    }
    $maxUploadMb = (float) SettingsService::getGlobal('media.max_upload_mb', 10);
    if ($file['size'] > ($maxUploadMb * 1024 * 1024)) {
        json_response(['error' => 'File exceeds upload limit.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: ($file['type'] ?? '');
    if (!in_array($mime, $allowedTypes, true)) {
        json_response(['error' => 'Unsupported file type.'], 422);
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/html' => 'html',
        'application/xhtml+xml' => 'html',
        'text/plain' => 'txt',
    ];
    $extension = $extensionMap[$mime] ?? 'bin';

    $baseDir = __DIR__ . '/../../uploads';
    $targetDir = $baseDir . '/media';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        json_response(['error' => 'Unable to create upload directory.'], 500);
    }

    $safeName = 'media_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        json_response(['error' => 'Unable to save upload.'], 500);
    }

    if ($mime === 'application/pdf') {
        $type = 'pdf';
    } elseif (str_starts_with($mime, 'image/')) {
        $type = 'image';
    } else {
        $type = 'file';
    }
    $url = '/uploads/media/' . $safeName;
    $title = trim((string) ($_POST['title'] ?? $file['name']));
    $visibility = SettingsService::getGlobal('media.privacy_default', 'member');
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO media (type, title, path, tags, visibility, uploaded_by, created_at) VALUES (:type, :title, :path, :tags, :visibility, :uploaded_by, NOW())');
    $stmt->execute([
        'type' => $type,
        'title' => $title !== '' ? $title : $safeName,
        'path' => $url,
        'tags' => '',
        'visibility' => $visibility,
        'uploaded_by' => (int) ($user['id'] ?? 0),
    ]);
    $mediaId = (int) $pdo->lastInsertId();

    json_response([
        'ok' => true,
        'id' => $mediaId,
        'url' => $url,
        'type' => $type,
    ]);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = [];
}
require_csrf($data);

if ($method === 'POST') {
    if ($action === 'save_draft') {
        $page = load_page_or_fail($pageId);
        $draftHtml = (string) ($data['draft_html'] ?? '');
        $roles = load_roles();
        $access = PageBuilderService::buildAccessLevel((string) ($data['access_level'] ?? 'public'), $roles);
        $draftHtml = PageBuilderService::ensureDraftHtml($draftHtml);

        PageService::updateDraft($pageId, $draftHtml, $access);
        AuditService::log((int) ($user['id'] ?? 0), 'save_draft', 'Page #' . $pageId . ' draft updated.');
        insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'system', 'Draft saved.', null);

        json_response(['ok' => true, 'draft_html' => $draftHtml]);
    }

    if ($action === 'create_page') {
        $provider = strtolower((string) SettingsService::getGlobal('ai.provider', 'openai'));
        $capCheck = usage_check_cap($provider);
        if (!$capCheck['ok']) {
            json_response(['error' => 'Monthly AI usage cap reached.'], 429);
        }
        $prompt = trim((string) ($data['prompt'] ?? ''));
        if ($prompt === '') {
            json_response(['error' => 'Prompt is required.'], 400);
        }
        if (!in_array($provider, ['openai', 'gemini'], true)) {
            $provider = 'openai';
        }
        $model = (string) SettingsService::getGlobal('ai.model', '');
        $client = AiProviderFactory::make($provider);
        if (!$client) {
            json_response(['error' => 'AI provider is not configured.'], 400);
        }

        $guardrails = (string) SettingsService::getGlobal('ai.guardrails', '');
        $masterPrompt = (string) SettingsService::getGlobal('ai.builder_master_prompt', '');
        $systemPrompt = implode("\n", [
            'You create HTML for a front-facing page on the Australian Goldwing Association site.',
            'You are an advanced product and website builder and designer.',
            'Return ONLY valid JSON with keys: title, slug, html, summary.',
            'Use existing site styles and structure (sections, headings, buttons).',
            'Output HTML only (no markdown).',
            $masterPrompt !== '' ? 'Master prompt: ' . $masterPrompt : '',
            $guardrails !== '' ? 'Guardrails: ' . $guardrails : '',
        ]);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ];

        $options = [
            'temperature' => 0.3,
            'max_tokens' => 1600,
        ];
        if ($model !== '') {
            $options['model'] = $model;
        }
        $result = $client->request($messages, $options);

        if (!$result['ok']) {
            json_response(['error' => $result['error'] ?? 'AI request failed.'], 500);
        }

        $parsed = normalize_page_create_response($result['content'] ?? '');
        if (!$parsed['ok']) {
            json_response(['error' => $parsed['error']], 400);
        }

        $title = $parsed['title'];
        $slug = $parsed['slug'] !== '' ? $parsed['slug'] : $title;
        $slug = unique_slug(slugify($slug));
        $draftHtml = PageBuilderService::ensureDraftHtml($parsed['html']);

        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO pages (slug, title, html_content, draft_html, live_html, access_level, visibility, created_at) VALUES (:slug, :title, :html_content, :draft_html, :live_html, :access_level, :visibility, NOW())');
        $stmt->execute([
            'slug' => $slug,
            'title' => $title,
            'html_content' => '',
            'draft_html' => $draftHtml,
            'live_html' => '',
            'access_level' => 'public',
            'visibility' => 'public',
        ]);
        $newId = (int) $pdo->lastInsertId();

        $ratePer1k = (float) SettingsService::getGlobal('ai.token_cost_usd', 0);
        $usage = usage_cost_from_raw($provider, $result['raw'] ?? [], $ratePer1k);
        usage_record($provider, $usage['usd_cents'], $usage['tokens']);

        insert_chat_message($newId, (int) ($user['id'] ?? 0), 'user', $prompt, null);
        insert_chat_message($newId, null, 'assistant', $parsed['summary'], null);
        insert_chat_message($newId, (int) ($user['id'] ?? 0), 'system', 'Page created in draft.', null);
        AuditService::log((int) ($user['id'] ?? 0), 'save_draft', 'Page #' . $newId . ' created.');

        json_response([
            'ok' => true,
            'page_id' => $newId,
        ]);
    }

    if ($action === 'access') {
        $page = load_page_or_fail($pageId);
        $roles = load_roles();
        $access = PageBuilderService::buildAccessLevel((string) ($data['access_level'] ?? 'public'), $roles);
        PageService::updateDraft($pageId, PageService::draftHtml($page), $access);
        AuditService::log((int) ($user['id'] ?? 0), 'settings_change', 'Page #' . $pageId . ' access updated.');
        json_response(['ok' => true, 'access_level' => $access]);
    }

    if ($action === 'publish') {
        $page = load_page_or_fail($pageId);
        $draft = PageService::draftHtml($page);
        $liveHtml = PageBuilderService::stripElementIds($draft);

        $pdo = db();
        $stmt = $pdo->prepare('SELECT MAX(version_number) as max_version FROM page_versions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);
        $row = $stmt->fetch();
        $nextVersion = (int) ($row['max_version'] ?? 0) + 1;
        $label = trim((string) ($data['version_label'] ?? ''));
        if ($label === '') {
            $label = 'v' . $nextVersion . ' – Update';
        }

        PageService::publishDraft($pageId, $liveHtml);

        $stmt = $pdo->prepare('INSERT INTO page_versions (page_id, html_content, created_by, change_summary, version_number, version_label, html_snapshot, published_by_user_id, published_at, created_at) VALUES (:page_id, :html_content, :created_by, :summary, :version_number, :version_label, :html_snapshot, :published_by, NOW(), NOW())');
        $stmt->execute([
            'page_id' => $pageId,
            'html_content' => $liveHtml,
            'created_by' => (int) ($user['id'] ?? 0),
            'summary' => $label,
            'version_number' => $nextVersion,
            'version_label' => $label,
            'html_snapshot' => $liveHtml,
            'published_by' => (int) ($user['id'] ?? 0),
        ]);

        AuditService::log((int) ($user['id'] ?? 0), 'push_live', 'Page #' . $pageId . ' published.');
        insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'system', 'Pushed live: ' . $label, null);

        json_response(['ok' => true, 'version_label' => $label]);
    }

    if ($action === 'rollback') {
        $page = load_page_or_fail($pageId);
        $versionId = (int) ($data['version_id'] ?? 0);
        if ($versionId <= 0) {
            json_response(['error' => 'Version ID required.'], 400);
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT html_snapshot, version_label FROM page_versions WHERE id = :id AND page_id = :page_id LIMIT 1');
        $stmt->execute(['id' => $versionId, 'page_id' => $pageId]);
        $version = $stmt->fetch();
        if (!$version) {
            json_response(['error' => 'Version not found.'], 404);
        }
        $draftHtml = PageBuilderService::ensureDraftHtml((string) ($version['html_snapshot'] ?? ''));
        PageService::updateDraft($pageId, $draftHtml, (string) ($page['access_level'] ?? 'public'));
        AuditService::log((int) ($user['id'] ?? 0), 'rollback', 'Page #' . $pageId . ' rolled back.');
        insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'system', 'Rollback to ' . ($version['version_label'] ?? 'version'), null);
        json_response(['ok' => true, 'draft_html' => $draftHtml]);
    }

    if ($action === 'manual_edit') {
        $page = load_page_or_fail($pageId);
        $elementId = trim((string) ($data['selected_element_id'] ?? ''));
        $updatedHtml = (string) ($data['updated_html'] ?? '');
        $templateScope = trim((string) ($data['template_scope'] ?? ''));
        if ($elementId === '' || $updatedHtml === '') {
            json_response(['error' => 'Element selection required.'], 400);
        }
        if ($templateScope !== '') {
            $currentTemplate = (string) SettingsService::getGlobal('ai.template_' . $templateScope . '_html', '');
            if ($currentTemplate === '') {
                $currentTemplate = default_template_html($templateScope);
            }
            $currentTemplate = PageBuilderService::ensureDraftHtml($currentTemplate);
            [$ok, $updatedTemplate, $error] = PageBuilderService::replaceElementHtml($currentTemplate, $elementId, $updatedHtml);
            if (!$ok) {
                json_response(['error' => $error ?: 'Could not update element.'], 400);
            }
            SettingsService::setGlobal((int) ($user['id'] ?? 0), 'ai.template_' . $templateScope . '_html', $updatedTemplate);
            AuditService::log((int) ($user['id'] ?? 0), 'manual_edit', 'Template ' . $templateScope . ' updated.');
            insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'system', 'Template updated.', $elementId);
            json_response(['ok' => true, 'template_html' => $updatedTemplate]);
        }
        [$ok, $updatedDraft, $error] = PageBuilderService::replaceElementHtml(PageService::draftHtml($page), $elementId, $updatedHtml);
        if (!$ok) {
            json_response(['error' => $error ?: 'Could not update element.'], 400);
        }
        PageService::updateDraft($pageId, $updatedDraft, (string) ($page['access_level'] ?? 'public'));
        AuditService::log((int) ($user['id'] ?? 0), 'manual_edit', 'Page #' . $pageId . ' manual edit.');
        insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'system', 'Manual edit applied.', $elementId);
        json_response(['ok' => true, 'draft_html' => $updatedDraft]);
    }

    if ($action === 'ai_edit') {
        $page = load_page_or_fail($pageId);
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $elementId = trim((string) ($data['selected_element_id'] ?? ''));
        $elementHtml = (string) ($data['selected_element_html'] ?? '');
        $templateScope = trim((string) ($data['template_scope'] ?? ''));
        $referenceImageUrl = normalize_reference_image_url((string) ($data['reference_image_url'] ?? ''));
        $mode = ($data['mode'] ?? 'element') === 'page' ? 'page' : 'element';

        if ($prompt === '') {
            json_response(['error' => 'Prompt is required.'], 400);
        }
        if ($mode === 'element' && ($elementId === '' || $elementHtml === '')) {
            json_response(['error' => 'Element selection required.'], 400);
        }

        $provider = strtolower((string) SettingsService::getGlobal('ai.provider', 'openai'));
        $capCheck = usage_check_cap($provider);
        if (!$capCheck['ok']) {
            json_response(['error' => 'Monthly AI usage cap reached.'], 429);
        }
        if (!in_array($provider, ['openai', 'gemini'], true)) {
            $provider = 'openai';
        }
        $model = (string) SettingsService::getGlobal('ai.model', '');
        $client = AiProviderFactory::make($provider);
        if (!$client) {
            json_response(['error' => 'AI provider is not configured.'], 400);
        }
        if ($referenceImageUrl !== null && !$client->supportsVision()) {
            json_response(['error' => 'Selected AI provider does not support vision inputs.'], 400);
        }

        $guardrails = (string) SettingsService::getGlobal('ai.guardrails', '');
        $masterPrompt = (string) SettingsService::getGlobal('ai.builder_master_prompt', '');
        $systemPrompt = implode("\n", [
            'You are an advanced product and website builder and designer.',
            'You can fully redesign pages when asked, applying modern UX/UI best practices and clear content hierarchy.',
            'Return ONLY valid JSON with keys: updated_html (string), summary (string), warnings (array).',
            'If mode is element, updated_html MUST be the full HTML for the selected element only.',
            'If mode is page, updated_html MUST be the full HTML for the page content.',
            'Do not include markdown fences.',
            $masterPrompt !== '' ? 'Master prompt: ' . $masterPrompt : '',
            $guardrails !== '' ? 'Guardrails: ' . $guardrails : '',
        ]);

        $userPromptParts = [
            'Mode: ' . $mode,
            'User instruction: ' . $prompt,
        ];
        if ($mode === 'element') {
            $userPromptParts[] = 'Selected element HTML:';
            $userPromptParts[] = $elementHtml;
        } else {
            $userPromptParts[] = 'Current page HTML:';
            $userPromptParts[] = PageService::draftHtml($page);
        }
        if ($referenceImageUrl !== null) {
            $userPromptParts[] = 'Design reference image is attached.';
        }

        $userContent = implode("\n\n", $userPromptParts);
        if ($referenceImageUrl !== null) {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $userContent],
                        ['type' => 'image_url', 'image_url' => ['url' => $referenceImageUrl]],
                    ],
                ],
            ];
        } else {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ];
        }

        $options = [
            'temperature' => 0.2,
            'max_tokens' => 1400,
        ];
        if ($model !== '') {
            $options['model'] = $model;
        }
        $result = $client->request($messages, $options);

        if (!$result['ok']) {
            json_response(['error' => $result['error'] ?? 'AI request failed.'], 500);
        }

        $parsed = normalize_ai_response($result['content'] ?? '');
        if (!$parsed['ok']) {
            json_response(['error' => $parsed['error']], 400);
        }

        $ratePer1k = (float) SettingsService::getGlobal('ai.token_cost_usd', 0);
        $usage = usage_cost_from_raw($provider, $result['raw'] ?? [], $ratePer1k);
        usage_record($provider, $usage['usd_cents'], $usage['tokens']);

        if ($templateScope !== '') {
            $currentTemplate = (string) SettingsService::getGlobal('ai.template_' . $templateScope . '_html', '');
            if ($currentTemplate === '') {
                $currentTemplate = default_template_html($templateScope);
            }
            $currentTemplate = PageBuilderService::ensureDraftHtml($currentTemplate);
            if ($mode === 'page') {
                $updatedTemplate = PageBuilderService::ensureDraftHtml($parsed['updated_html']);
            } else {
                [$ok, $updatedTemplate, $error] = PageBuilderService::replaceElementHtml($currentTemplate, $elementId, $parsed['updated_html']);
                if (!$ok) {
                    json_response(['error' => $error ?: 'AI edit failed.'], 400);
                }
            }
            SettingsService::setGlobal((int) ($user['id'] ?? 0), 'ai.template_' . $templateScope . '_html', $updatedTemplate);
            insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'user', $prompt, $elementId ?: null);
            insert_chat_message($pageId, null, 'assistant', $parsed['summary'], $elementId ?: null);
            AuditService::log((int) ($user['id'] ?? 0), 'ai_edit', 'Template ' . $templateScope . ' AI edit.');
            json_response([
                'ok' => true,
                'template_html' => $updatedTemplate,
                'summary' => $parsed['summary'],
                'warnings' => $parsed['warnings'],
            ]);
        }

        $draft = PageService::draftHtml($page);
        if ($mode === 'page') {
            $updatedDraft = PageBuilderService::ensureDraftHtml($parsed['updated_html']);
        } else {
            [$ok, $updatedDraft, $error] = PageBuilderService::replaceElementHtml($draft, $elementId, $parsed['updated_html']);
            if (!$ok) {
                json_response(['error' => $error ?: 'AI edit failed.'], 400);
            }
        }

        PageService::updateDraft($pageId, $updatedDraft, (string) ($page['access_level'] ?? 'public'));

        insert_chat_message($pageId, (int) ($user['id'] ?? 0), 'user', $prompt, $elementId ?: null);
        insert_chat_message($pageId, null, 'assistant', $parsed['summary'], $elementId ?: null);
        AuditService::log((int) ($user['id'] ?? 0), 'ai_edit', 'Page #' . $pageId . ' AI edit.');

        json_response([
            'ok' => true,
            'draft_html' => $updatedDraft,
            'summary' => $parsed['summary'],
            'warnings' => $parsed['warnings'],
        ]);
    }

    if ($action === 'image_generate') {
        $page = load_page_or_fail($pageId);
        $templateScope = trim((string) ($data['template_scope'] ?? ''));
        $provider = strtolower((string) SettingsService::getGlobal('ai.provider', 'openai'));
        $capCheck = usage_check_cap($provider);
        if (!$capCheck['ok']) {
            json_response(['error' => 'Monthly AI usage cap reached.'], 429);
        }
        $prompt = trim((string) ($data['prompt'] ?? ''));
        if ($prompt === '') {
            json_response(['error' => 'Prompt is required.'], 400);
        }
        $enabled = SettingsService::getGlobal('ai.image_generation_enabled', false);
        if (!$enabled) {
            json_response(['error' => 'Image generation is disabled.'], 400);
        }
        if ($provider !== 'openai') {
            json_response(['error' => 'Image generation only supported with OpenAI.'], 400);
        }
        $result = generate_image_openai($prompt);
        if (!$result['ok']) {
            json_response(['error' => $result['error']], 500);
        }
        $imageCostUsd = (float) SettingsService::getGlobal('ai.image_cost_usd', 0.02);
        usage_record($provider, (int) round($imageCostUsd * 100), 0);
        AuditService::log((int) ($user['id'] ?? 0), 'ai_edit', 'Page #' . $pageId . ' image generated.');
        json_response([
            'ok' => true,
            'url' => $result['url'],
            'template_scope' => $templateScope !== '' ? $templateScope : null,
        ]);
    }

    if ($action === 'template_update') {
        $scope = trim((string) ($data['scope'] ?? ''));
        $html = (string) ($data['html'] ?? '');
        if (!in_array($scope, ['header', 'footer'], true)) {
            json_response(['error' => 'Invalid template scope.'], 400);
        }
        $html = PageBuilderService::ensureDraftHtml($html);
        SettingsService::setGlobal((int) ($user['id'] ?? 0), 'ai.template_' . $scope . '_html', $html);
        AuditService::log((int) ($user['id'] ?? 0), 'manual_edit', 'Template ' . $scope . ' updated.');
        json_response(['ok' => true, 'html' => $html]);
    }

    json_response(['error' => 'Not found.'], 404);
}

json_response(['error' => 'Unsupported method.'], 405);
