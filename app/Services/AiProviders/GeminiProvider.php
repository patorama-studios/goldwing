<?php
namespace App\Services\AiProviders;

class GeminiProvider implements AiProviderInterface
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function request(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? 'gemini-1.5-flash';
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($this->apiKey);

        $systemPrompt = '';
        $userChunks = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $systemPrompt .= ($systemPrompt ? "\n\n" : '') . ($message['content'] ?? '');
                continue;
            }
            if (($message['role'] ?? '') === 'user') {
                $userChunks[] = $message['content'] ?? '';
            }
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => implode("\n\n", $userChunks)],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.2,
                'maxOutputTokens' => $options['max_tokens'] ?? 1400,
            ],
        ];

        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = [
                'role' => 'system',
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => $error ?: 'Gemini request failed.',
            ];
        }

        $data = json_decode($response, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'ok' => true,
            'status' => $status,
            'content' => $content,
            'raw' => $data,
        ];
    }
}
