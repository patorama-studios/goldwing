<?php
namespace App\Services\AiProviders;

interface AiProviderInterface
{
    public function request(array $messages, array $options = []): array;

    public function supportsVision(): bool;
}
