<?php
namespace App\Services;

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

        $apiKey = config('ai.providers.openai.api_key', config('ai.api_key', ''));
        if ($apiKey === '') {
            $fallback = [
                'target_type' => 'page',
                'target_id' => null,
                'slug' => 'home',
                'proposed_html' => '<p>AI is not configured.</p>',
                'change_summary' => 'AI key missing; fallback response.',
            ];
            self::logMessage($conversationId, 'assistant', json_encode($fallback));
            return $fallback;
        }

        $systemPrompt = 'You are an assistant for the Goldwing Association admin. '
            . 'Return ONLY valid JSON with keys: target_type (page|notice|event), target_id or slug, '
            . 'proposed_html or proposed_text, change_summary. '
            . 'Never suggest role/permission changes. Never output PHP or server code. Only content edits.';

        $payload = [
            'model' => config('ai.model', 'gpt-4o-mini'),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $input],
            ],
            'temperature' => 0.3,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
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
        curl_close($ch);

        if ($status < 200 || $status >= 300 || !$response) {
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

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
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
        if (stripos($html, '<?php') !== false || stripos($html, 'require(') !== false) {
            $html = '<p>Blocked unsafe content.</p>';
        }
        if (isset($result['proposed_html'])) {
            $result['proposed_html'] = $html;
        } elseif (isset($result['proposed_text'])) {
            $result['proposed_text'] = $html;
        } else {
            $result['proposed_html'] = $html;
        }

        return $result;
    }
}
