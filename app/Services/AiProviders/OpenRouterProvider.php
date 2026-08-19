<?php
namespace App\Services\AiProviders;

class OpenRouterProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $endpoint;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->endpoint = 'https://openrouter.ai/api/v1/chat/completions';
    }

    public function supportsVision(): bool
    {
        // ponytail: vision is model-dependent on OpenRouter; a non-vision model
        // (e.g. DeepSeek) returns a provider error that surfaces in the builder UI.
        return true;
    }

    public function request(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? 'deepseek/deepseek-chat',
            // Messages are already in OpenAI chat format (string content or
            // text/image_url part arrays) — passed through unchanged.
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 1400,
            'stream' => false,
            // Ask OpenRouter to report the USD cost of the call in usage.cost.
            'usage' => ['include' => true],
        ];
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
                'HTTP-Referer: https://goldwing.org.au',
                'X-Title: AGA Page Builder',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $data = is_string($response) ? json_decode($response, true) : null;

        if ($response === false || $status < 200 || $status >= 300 || isset($data['error'])) {
            $errMessage = $error ?: 'OpenRouter request failed.';
            if (is_array($data)) {
                if (!empty($data['error']['message'])) {
                    $errMessage = (string) $data['error']['message'];
                } elseif (!empty($data['message'])) {
                    $errMessage = (string) $data['message'];
                }
            }
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'OpenRouter (' . $status . '): ' . $errMessage,
            ];
        }

        $content = is_array($data) ? (string) ($data['choices'][0]['message']['content'] ?? '') : '';

        return [
            'ok' => true,
            'status' => $status,
            'content' => $content,
            'raw' => is_array($data) ? $data : [],
        ];
    }
}
