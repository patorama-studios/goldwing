<?php
namespace App\Services\AiProviders;

class KieAiProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $endpoint;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->endpoint = 'https://api.kie.ai/v1/chat/completions';
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function request(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? 'claude-sonnet-4-6',
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 1400,
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            $errMessage = $error ?: 'kie.ai request failed.';
            if ($response) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && !empty($decoded['error']['message'])) {
                    $errMessage = (string) $decoded['error']['message'];
                }
            }
            return [
                'ok' => false,
                'status' => $status,
                'error' => $errMessage,
            ];
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $text = '';
            foreach ($content as $chunk) {
                if (is_array($chunk) && isset($chunk['text'])) {
                    $text .= (string) $chunk['text'];
                } elseif (is_string($chunk)) {
                    $text .= $chunk;
                }
            }
            $content = $text;
        }

        return [
            'ok' => true,
            'status' => $status,
            'content' => (string) $content,
            'raw' => $data,
        ];
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? 'dall-e-3',
            'prompt' => $prompt,
            'size' => $options['size'] ?? '1024x1024',
            'n' => 1,
        ];

        $ch = curl_init('https://api.kie.ai/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => $error ?: 'kie.ai image generation failed.'];
        }

        $data = json_decode($response, true);
        $url = $data['data'][0]['url'] ?? ($data['data'][0]['b64_json'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'error' => 'Image response missing URL.'];
        }

        return ['ok' => true, 'url' => $url];
    }
}
