<?php
namespace App\Services\AiProviders;

class OpenAiProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $endpoint;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->endpoint = 'https://api.openai.com/v1/chat/completions';
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function request(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? 'gpt-4o-mini',
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
            return [
                'ok' => false,
                'status' => $status,
                'error' => $error ?: 'OpenAI request failed.',
            ];
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        return [
            'ok' => true,
            'status' => $status,
            'content' => $content,
            'raw' => $data,
        ];
    }
}
