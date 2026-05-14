<?php
namespace App\Services;

use App\Services\AiProviders\AiProviderFactory;

class AiService
{
    public static function createConversation(int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO ai_conversations (user_id, created_at) VALUES (:user_id, NOW())');
        $stmt->execute(['user_id' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function logMessage(int $conversationId, string $role, string $content): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO ai_messages (conversation_id, role, content, created_at) VALUES (:conversation_id, :role, :content, NOW())');
        $stmt->execute([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
        ]);
    }

    public static function chat(int $conversationId, string $input): array
    {
        self::logMessage($conversationId, 'user', $input);

        $client = AiProviderFactory::make('kie');
        if (!$client) {
            $fallback = [
                'target_type' => 'page',
                'target_id' => null,
                'slug' => 'home',
                'proposed_html' => '<p>AI is not configured.</p>',
                'change_summary' => 'kie.ai key missing; fallback response.',
            ];
            self::logMessage($conversationId, 'assistant', json_encode($fallback));
            return $fallback;
        }

        $systemPrompt = implode("\n", [
            'You are an assistant for the Australian Goldwing Association admin page builder.',
            'SCOPE LOCK: You may ONLY suggest content edits to a single page draft.',
            'You MUST NOT output PHP, server code, SQL, shell commands, <script>, <iframe>, inline event handlers, or anything outside the page content.',
            'You MUST NOT suggest role, permission, settings, user, or codebase changes.',
            'Return ONLY valid JSON with keys: target_type (page|notice|event), target_id or slug, proposed_html or proposed_text, change_summary.',
        ]);

        $result = $client->request([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $input],
        ], [
            'model' => config('ai.model', 'claude-sonnet-4-6'),
            'temperature' => 0.3,
            'max_tokens' => 1400,
        ]);

        if (!$result['ok']) {
            $fallback = [
                'target_type' => 'page',
                'target_id' => null,
                'slug' => 'home',
                'proposed_html' => '<p>AI request failed.</p>',
                'change_summary' => 'AI request failed.',
            ];
            self::logMessage($conversationId, 'assistant', json_encode($fallback));
            return $fallback;
        }

        $content = (string) ($result['content'] ?? '');
        self::logMessage($conversationId, 'assistant', $content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [
                'target_type' => 'page',
                'target_id' => null,
                'slug' => 'home',
                'proposed_html' => '<p>AI response was not valid JSON.</p>',
                'change_summary' => 'AI response invalid.',
            ];
        }

        return self::sanitize($decoded);
    }

    public static function sanitize(array $result): array
    {
        $allowedTargets = ['page', 'notice', 'event'];
        $targetType = $result['target_type'] ?? '';
        if (!in_array($targetType, $allowedTargets, true)) {
            $result['target_type'] = 'page';
        }

        $html = $result['proposed_html'] ?? $result['proposed_text'] ?? '';
        $html = self::stripUnsafe((string) $html);
        if (isset($result['proposed_html'])) {
            $result['proposed_html'] = $html;
        } elseif (isset($result['proposed_text'])) {
            $result['proposed_text'] = $html;
        } else {
            $result['proposed_html'] = $html;
        }

        return $result;
    }

    private static function stripUnsafe(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $blockedPhrases = ['<?php', '<?=', '<? ', 'require(', 'require_once', 'include(', 'include_once', 'eval(', 'system(', 'shell_exec'];
        foreach ($blockedPhrases as $needle) {
            if (stripos($html, $needle) !== false) {
                return '<p>Blocked unsafe content.</p>';
            }
        }
        $patterns = [
            '/<script\b[^>]*>.*?<\/script>/is', '/<script\b[^>]*>/i',
            '/<iframe\b[^>]*>/i', '/<\/iframe>/i',
            '/<object\b[^>]*>/i', '/<\/object>/i',
            '/<embed\b[^>]*>/i',
            '/\son[a-z]+\s*=\s*"(?:[^"\\\\]|\\\\.)*"/i',
            "/\\son[a-z]+\\s*=\\s*'(?:[^'\\\\]|\\\\.)*'/i",
            '/\son[a-z]+\s*=\s*[^\s>]+/i',
            '/javascript:/i',
        ];
        foreach ($patterns as $pat) {
            $html = preg_replace($pat, '', $html) ?? $html;
        }
        return $html;
    }
}
