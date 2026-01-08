<?php
namespace App\Services\AiProviders;

use App\Services\AiProviderKeyService;

class AiProviderFactory
{
    public static function make(string $providerKey): ?AiProviderInterface
    {
        $providerKey = strtolower($providerKey);
        if ($providerKey === 'openai') {
            $apiKey = AiProviderKeyService::getKey('openai') ?? config('ai.providers.openai.api_key', '');
            if ($apiKey === '') {
                return null;
            }
            return new OpenAiProvider($apiKey);
        }
        if ($providerKey === 'gemini') {
            $apiKey = AiProviderKeyService::getKey('gemini') ?? config('ai.providers.gemini.api_key', '');
            if ($apiKey === '') {
                return null;
            }
            return new GeminiProvider($apiKey);
        }
        if ($providerKey === 'claude' || $providerKey === 'anthropic') {
            $apiKey = AiProviderKeyService::getKey('claude') ?? config('ai.providers.claude.api_key', '');
            if ($apiKey === '') {
                return null;
            }
            return new ClaudeProvider($apiKey);
        }

        return null;
    }
}
