<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\AiPageBuilderService;
use App\Services\AiProviderKeyService;
use App\Services\AiService;
use App\Services\AuditService;
use App\Services\Csrf;
use App\Services\DomSnapshotService;
use App\Services\PageAiRevisionService;
use App\Services\PageService;
use App\Services\UnifiedDiffService;

require_role(['admin']);

header('Content-Type: application/json');

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathInfo = trim($pathInfo, '/');
$parts = $pathInfo === '' ? [] : explode('/', $pathInfo);

$pageId = isset($parts[0]) ? (int) $parts[0] : 0;
$action = $parts[1] ?? '';
$subId = $parts[2] ?? null;
$revisionQueryId = isset($_GET['revision_id']) ? (int) $_GET['revision_id'] : 0;

if ($pageId === 0 && isset($_GET['page_id'])) {
    $pageId = (int) $_GET['page_id'];
}
if ($action === '' && isset($_GET['action'])) {
    $action = $_GET['action'];
}

if ($pageId <= 0) {
    json_response(['ok' => false, 'error' => 'Invalid page ID.'], 400);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = current_user();

if ($method === 'GET') {
    if ($action === 'builder-state') {
        $page = PageService::getById($pageId);
        if (!$page) {
            json_response(['ok' => false, 'error' => 'Page not found.'], 404);
        }

        $sessionKey = 'ai_builder_conversations';
        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [];
        }
        if (empty($_SESSION[$sessionKey][$pageId])) {
            $_SESSION[$sessionKey][$pageId] = AiService::createConversation($user['id']);
        }
        $conversationId = (int) $_SESSION[$sessionKey][$pageId];

        $pdo = db();
        $stmt = $pdo->prepare('SELECT role, content, created_at FROM ai_messages WHERE conversation_id = :id ORDER BY created_at ASC LIMIT 120');
        $stmt->execute(['id' => $conversationId]);
        $messages = $stmt->fetchAll() ?: [];

        $providers = config('ai.providers', []);
        $providerList = [];
        foreach ($providers as $key => $provider) {
            $dbConfigured = AiProviderKeyService::hasKey($key);
            $envConfigured = !empty($provider['api_key']);
            $providerList[] = [
                'key' => $key,
                'label' => $provider['label'] ?? ucfirst($key),
                'models' => $provider['models'] ?? [],
                'configured' => $dbConfigured || $envConfigured,
            ];
        }

        $revisions = PageAiRevisionService::listByPage($pageId, 50);
        $revisionList = array_map(function ($revision) {
            return [
                'id' => (int) $revision['id'],
                'summary' => $revision['summary'] ?? '',
                'provider' => $revision['provider'] ?? '',
                'model' => $revision['model'] ?? '',
                'created_at' => $revision['created_at'] ?? '',
                'user_name' => $revision['user_name'] ?? 'System',
                'reverted_from_revision_id' => $revision['reverted_from_revision_id'] ? (int) $revision['reverted_from_revision_id'] : null,
            ];
        }, $revisions);

        json_response([
            'ok' => true,
            'page' => [
                'id' => (int) $page['id'],
                'title' => $page['title'],
                'slug' => $page['slug'],
            ],
            'preview_url' => '/?page=' . urlencode($page['slug']) . '&builder_preview=1',
            'providers' => $providerList,
            'default_provider' => config('ai.default_provider', 'openai'),
            'default_model' => config('ai.default_model', 'gpt-4o-mini'),
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'revisions' => $revisionList,
        ]);
    }

    if ($action === 'revisions') {
        $lookupId = $subId ? (int) $subId : $revisionQueryId;
        if ($lookupId) {
            $revision = PageAiRevisionService::getById($pageId, $lookupId);
            if (!$revision) {
                json_response(['ok' => false, 'error' => 'Revision not found.'], 404);
            }
            json_response([
                'ok' => true,
                'revision' => [
                    'id' => (int) $revision['id'],
                    'summary' => $revision['summary'] ?? '',
                    'diff_text' => $revision['diff_text'] ?? '',
                    'created_at' => $revision['created_at'] ?? '',
                    'user_name' => $revision['user_name'] ?? 'System',
                ],
            ]);
        }

        $revisions = PageAiRevisionService::listByPage($pageId, 50);
        $revisionList = array_map(function ($revision) {
            return [
                'id' => (int) $revision['id'],
                'summary' => $revision['summary'] ?? '',
                'provider' => $revision['provider'] ?? '',
                'model' => $revision['model'] ?? '',
                'created_at' => $revision['created_at'] ?? '',
                'user_name' => $revision['user_name'] ?? 'System',
                'reverted_from_revision_id' => $revision['reverted_from_revision_id'] ? (int) $revision['reverted_from_revision_id'] : null,
            ];
        }, $revisions);
        json_response(['ok' => true, 'revisions' => $revisionList]);
    }

    json_response(['ok' => false, 'error' => 'Not found.'], 404);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? '');
    if (!Csrf::verify($csrfToken)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    $page = PageService::getById($pageId);
    if (!$page) {
        json_response(['ok' => false, 'error' => 'Page not found.'], 404);
    }

    $sessionKey = 'ai_builder_conversations';
    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = [];
    }
    if (empty($_SESSION[$sessionKey][$pageId])) {
        $_SESSION[$sessionKey][$pageId] = AiService::createConversation($user['id']);
    }
    $conversationId = (int) $_SESSION[$sessionKey][$pageId];

    if ($action === 'ai-edit') {
        $message = trim($data['message'] ?? '');
        if ($message === '') {
            json_response(['ok' => false, 'error' => 'Message is required.'], 400);
        }
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $providers = config('ai.providers', []);
        $provider = strtolower($data['provider'] ?? config('ai.default_provider', 'openai'));
        if (!isset($providers[$provider])) {
            $provider = config('ai.default_provider', 'openai');
        }
        $model = $data['model'] ?? ($providers[$provider]['models'][0] ?? config('ai.default_model', 'gpt-4o-mini'));
        if (!in_array($model, $providers[$provider]['models'] ?? [], true)) {
            $model = $providers[$provider]['models'][0] ?? config('ai.default_model', 'gpt-4o-mini');
        }

        $selected = $data['selectedElement'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }
        $domSnapshot = $data['domSnapshot'] ?? '';
        $domSnapshot = DomSnapshotService::sanitize((string) $domSnapshot);

        $messages = AiPageBuilderService::buildMessages($page, $message, $selected, $domSnapshot);
        $aiResult = AiPageBuilderService::requestPatch($provider, $model, $messages);
        if (!$aiResult['ok']) {
            json_response(['ok' => false, 'error' => $aiResult['error'] ?? 'AI request failed.'], 500);
        }

        $parsed = UnifiedDiffService::extractSummaryAndDiff($aiResult['content'] ?? '');
        $summary = $parsed['summary'] ?: 'AI update';
        $diff = $parsed['diff'] ?? '';

        if (stripos($summary, 'NEEDS_CLARIFICATION') === 0 || $diff === '') {
            json_response([
                'ok' => false,
                'error' => 'AI could not produce a safe patch.',
                'question' => $aiResult['content'] ?? '',
            ], 400);
        }

        $files = UnifiedDiffService::parse($diff);
        if (count($files) !== 1) {
            json_response(['ok' => false, 'error' => 'Patch must target a single file.'], 400);
        }

        $filePatch = $files[0];
        $path = $filePatch['new'] ?: $filePatch['old'];
        $expectedPath = 'pages/' . $page['slug'] . '.html';
        if ($path !== $expectedPath) {
            json_response(['ok' => false, 'error' => 'Patch path not allowed.'], 400);
        }

        [$ok, $updatedHtml, $applyError] = UnifiedDiffService::apply($page['html_content'], $filePatch);
        if (!$ok) {
            json_response(['ok' => false, 'error' => $applyError], 400);
        }

        $beforeHtml = $page['html_content'];
        PageService::updateContent($pageId, $updatedHtml, $user['id'], $summary);

        $revisionId = PageAiRevisionService::create([
            'page_id' => $pageId,
            'user_id' => $user['id'],
            'provider' => $provider,
            'model' => $model,
            'summary' => $summary,
            'diff_text' => $diff,
            'files_changed' => json_encode([$expectedPath]),
            'before_content' => $beforeHtml,
            'after_content' => $updatedHtml,
            'reverted_from_revision_id' => null,
        ]);

        AiService::logMessage($conversationId, 'user', $message);
        AiService::logMessage($conversationId, 'assistant', $summary);
        AuditService::log($user['id'], 'ai_page_edit', 'Page #' . $pageId . ' updated via AI builder.');

        json_response([
            'ok' => true,
            'summary' => $summary,
            'applied' => true,
            'revisionId' => $revisionId,
        ]);
    }

    if ($action === 'revert') {
        $revisionId = (int) ($data['revisionId'] ?? 0);
        if ($revisionId <= 0) {
            json_response(['ok' => false, 'error' => 'Revision ID required.'], 400);
        }

        $revision = PageAiRevisionService::getById($pageId, $revisionId);
        if (!$revision) {
            json_response(['ok' => false, 'error' => 'Revision not found.'], 404);
        }

        $expectedPath = 'pages/' . $page['slug'] . '.html';
        $beforeHtml = $page['html_content'];
        $restoredHtml = $revision['before_content'] ?? '';
        $diff = UnifiedDiffService::generateFullDiff($beforeHtml, $restoredHtml, $expectedPath);

        PageService::updateContent($pageId, $restoredHtml, $user['id'], 'Reverted to revision #' . $revisionId);

        $newRevisionId = PageAiRevisionService::create([
            'page_id' => $pageId,
            'user_id' => $user['id'],
            'provider' => 'system',
            'model' => 'revert',
            'summary' => 'Reverted to revision #' . $revisionId,
            'diff_text' => $diff,
            'files_changed' => json_encode([$expectedPath]),
            'before_content' => $beforeHtml,
            'after_content' => $restoredHtml,
            'reverted_from_revision_id' => $revisionId,
        ]);

        AuditService::log($user['id'], 'ai_page_revert', 'Page #' . $pageId . ' reverted to revision #' . $revisionId . '.');

        json_response([
            'ok' => true,
            'applied' => true,
            'newRevisionId' => $newRevisionId,
        ]);
    }

    json_response(['ok' => false, 'error' => 'Not found.'], 404);
}

json_response(['ok' => false, 'error' => 'Unsupported method.'], 405);
