<?php
namespace App\Services;

use App\Services\AiProviders\AiProviderFactory;

class AiPageBuilderService
{
    public static function buildMessages(array $page, string $message, array $selectedElement, string $domSnapshot): array
    {
        $pageSlug = $page['slug'] ?? 'page';
        $pagePath = 'pages/' . $pageSlug . '.html';
        $systemPrompt = implode("\n", [
            'You are an expert web page editor for the Australian Goldwing Association admin.',
            'Return a unified diff patch ONLY along with a short summary line.',
            'Format your response exactly as:',
            'Summary: <short summary>',
            '--- ' . $pagePath,
            '+++ ' . $pagePath,
            '@@ ...',
            'Rules:',
            '- Only modify ' . $pagePath . ' for the provided page.',
            '- Preserve existing formatting unless changing it is necessary.',
            '- Never output secrets or API keys.',
            '- Do not change PHP, server config, or unrelated files.',
            '- Do not wrap the diff in markdown fences.',
            '- If you cannot safely produce a patch, respond with: Summary: NEEDS_CLARIFICATION and a question.',
        ]);
        $currentHtml = $page['html_content'] ?? '';

        $selectedInfo = 'None';
        if (!empty($selectedElement)) {
            $selectedInfo = "Selector: " . ($selectedElement['selector'] ?? 'n/a') . "\n";
            $selectedInfo .= "Breadcrumb: " . ($selectedElement['breadcrumb'] ?? 'n/a') . "\n";
            $selectedInfo .= "HTML: " . ($selectedElement['html'] ?? '');
        }

        $userPrompt = implode("\n\n", [
            'Page slug: ' . $pageSlug,
            'User instruction: ' . $message,
            'Selected element info:',
            $selectedInfo,
            'Current page HTML:',
            "```html\n" . $currentHtml . "\n```",
            'Rendered DOM snapshot (sanitized):',
            "```html\n" . $domSnapshot . "\n```",
        ]);

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    public static function requestPatch(string $provider, string $model, array $messages): array
    {
        $client = AiProviderFactory::make($provider);
        if (!$client) {
            return [
                'ok' => false,
                'error' => 'Provider is not configured.',
            ];
        }

        $result = $client->request($messages, [
            'model' => $model,
            'temperature' => 0.2,
            'max_tokens' => 1600,
        ]);

        if (!$result['ok']) {
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'AI request failed.',
                'status' => $result['status'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'content' => $result['content'] ?? '',
        ];
    }
}
