<?php
namespace App\Services\AiProviders;

class KieAiProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $endpoint;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->endpoint = 'https://api.kie.ai/claude/v1/messages';
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function request(array $messages, array $options = []): array
    {
        [$systemPrompt, $anthropicMessages] = self::translateMessages($messages);

        $payload = [
            'model' => $options['model'] ?? 'claude-sonnet-4-6',
            'messages' => $anthropicMessages,
            'max_tokens' => $options['max_tokens'] ?? 1400,
            'stream' => false,
        ];
        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            $errMessage = $error ?: 'kie.ai request failed.';
            if ($response) {
                $decoded = json_decode($response, true);
                if (is_array($decoded)) {
                    if (!empty($decoded['error']['message'])) {
                        $errMessage = (string) $decoded['error']['message'];
                    } elseif (!empty($decoded['message'])) {
                        $errMessage = (string) $decoded['message'];
                    } elseif (!empty($decoded['msg'])) {
                        $errMessage = (string) $decoded['msg'];
                    }
                }
            }
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'kie.ai (' . $status . '): ' . $errMessage,
            ];
        }

        $data = json_decode($response, true);
        $content = '';
        if (is_array($data) && !empty($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $content .= (string) ($block['text'] ?? '');
                }
            }
        }

        return [
            'ok' => true,
            'status' => $status,
            'content' => $content,
            'raw' => $data,
        ];
    }

    private static function translateMessages(array $messages): array
    {
        $systemChunks = [];
        $out = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';
            if ($role === 'system') {
                if (is_string($content)) {
                    $systemChunks[] = $content;
                } elseif (is_array($content)) {
                    foreach ($content as $part) {
                        if (is_array($part) && isset($part['text'])) {
                            $systemChunks[] = (string) $part['text'];
                        }
                    }
                }
                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $out[] = [
                'role' => $role,
                'content' => self::translateContent($content),
            ];
        }
        return [implode("\n\n", array_filter($systemChunks)), $out];
    }

    private static function translateContent($content)
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $blocks = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = $part['type'] ?? '';
            if ($type === 'text') {
                $blocks[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
            } elseif ($type === 'image_url') {
                $url = is_array($part['image_url'] ?? null) ? ($part['image_url']['url'] ?? '') : (string) ($part['image_url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'url',
                        'url' => $url,
                    ],
                ];
            } elseif ($type === 'image') {
                $blocks[] = $part;
            }
        }
        return $blocks;
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        return [
            'ok' => false,
            'error' => 'Image generation via kie.ai is not wired up in this build. Use the media library or disable image generation in AI Settings.',
        ];
    }
}
