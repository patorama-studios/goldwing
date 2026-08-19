<?php
namespace App\Services\AiProviders;

use App\Services\AiProviderKeyService;

class AiProviderFactory
{
    public static function make(string $providerKey): ?AiProviderInterface
    {
        $providerKey = strtolower($providerKey);
        if ($providerKey !== 'openrouter') {
            return null;
        }
        $apiKey = AiProviderKeyService::getKey('openrouter') ?? config('ai.providers.openrouter.api_key', '');
        if ($apiKey === '') {
            return null;
        }
        return new OpenRouterProvider($apiKey);
    }
}
