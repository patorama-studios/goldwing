<?php
namespace App\Services\AiProviders;

class ClaudeProvider implements AiProviderInterface
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
        $model = $options['model'] ?? 'claude-3-5-sonnet-20240620';

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
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 1400,
            'temperature' => $options['temperature'] ?? 0.2,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => implode("\n\n", $userChunks),
                        ],
                    ],
                ],
            ],
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
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
                'error' => $error ?: 'Claude request failed.',
            ];
        }

        $data = json_decode($response, true);
        $content = $data['content'][0]['text'] ?? '';

        return [
            'ok' => true,
            'status' => $status,
            'content' => $content,
            'raw' => $data,
        ];
    }
}
