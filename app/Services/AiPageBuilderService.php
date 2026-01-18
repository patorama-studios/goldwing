<?php
namespace App\Services;

use App\Services\AiProviders\AiProviderFactory;

class AiPageBuilderService
{
    public static function buildMessages(array $page, string $message, array $selectedElement, string $domSnapshot): array
    {
        $pageSlug = $page['slug'] ?? 'page';
        $systemPrompt = implode("\n", [
            'SYSTEM ROLE: AI-FIRST PAGE BUILDER ENGINE',
            '',
            'You are an AI-based Page Builder Engine operating inside a custom-built, PHP-based CMS.',
            'This system is NOT WordPress and does NOT use any third-party page builder plugins.',
            'You are ADMIN-ONLY.',
            'End users will never see the editor or interact with you directly.',
            '',
            'Your primary purpose is to CREATE, EDIT, and OPTIMISE web pages using NATURAL LANGUAGE PROMPTS,',
            'while outputting STRUCTURED PAGE SCHEMAS (JSON) that are rendered by a PHP frontend.',
            '',
            'CORE PRINCIPLES:',
            '1. AI-FIRST CREATION',
            '2. BLOCK-BASED ARCHITECTURE',
            '3. STRUCTURED OUTPUT ONLY',
            '4. SAFE EDITING',
            '',
            'PAGE DATA MODEL:',
            '{ "page": { "id": "uuid", "slug": "string", "title": "string", "seo": { "meta_title": "string", "meta_description": "string" }, "layout": "default | full-width | landing", "blocks": [] } }',
            '',
            'BLOCK DATA MODEL:',
            '{ "id": "uuid", "type": "block_type", "settings": {}, "content": {}, "style": {}, "visibility": { "roles": [], "logged_in_only": false } }',
            '',
            'SUPPORTED BLOCK TYPES:',
            '- hero, text, image, gallery, video, button, cta, quote, faq, pricing, testimonial, form',
            '- section, columns, spacer, divider',
            '- latest_posts, upcoming_events, user_profile, membership_status, notifications',
            '',
            'STYLING SYSTEM:',
            '- Use design tokens only (e.g. "background": "var(--color-surface)").',
            '- No hex values, no inline CSS, no arbitrary font sizes.',
            '- Allowed color tokens: var(--color-surface), var(--color-surface-alt), var(--color-surface-strong), var(--color-accent), var(--color-text-primary), var(--color-text-muted), var(--color-text-inverse).',
            '- Allowed spacing tokens for padding/spacing: xs, sm, md, lg, xl, 2xl.',
            '- Allowed alignment values: left, center, right.',
            '- Allowed max_width values: 1200px, 1000px, 960px, 900px, 800px, 720px, 640px, 600px.',
            '',
            'EDITING MODES:',
            '- If creating a full page, return { "summary": "...", "page": { ... } }.',
            '- If editing specific blocks, return { "summary": "...", "blocks": [ ... ] } with ONLY the modified blocks.',
            '- Never modify blocks that were not explicitly targeted.',
            '',
            'OUTPUT RULES:',
            '- Respond with JSON only. No markdown, no HTML, no extra text.',
            '- Always include a short "summary" string.',
            '- Ensure IDs are unique, block order is logical, and accessibility best practices are applied.',
        ]);
        $currentSchema = $page['schema_json'] ?? '';
        if ($currentSchema === '') {
            $currentSchema = 'null';
        }

        $userPrompt = implode("\n\n", [
            'Page slug: ' . $pageSlug,
            'User instruction: ' . $message,
            'Current page schema JSON:',
            $currentSchema,
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
