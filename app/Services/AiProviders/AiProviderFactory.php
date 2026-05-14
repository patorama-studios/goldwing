<?php
namespace App\Services\AiProviders;

use App\Services\AiProviderKeyService;

class AiProviderFactory
{
    public static function make(string $providerKey): ?AiProviderInterface
    {
        $providerKey = strtolower($providerKey);
        if ($providerKey !== 'kie') {
            return null;
        }
        $apiKey = AiProviderKeyService::getKey('kie') ?? config('ai.providers.kie.api_key', '');
        if ($apiKey === '') {
            return null;
        }
        return new KieAiProvider($apiKey);
    }
}
