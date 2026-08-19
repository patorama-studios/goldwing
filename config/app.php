<?php
return [
    'app_key' => getenv('APP_KEY') ?: '',
    'app_name' => 'Australian Goldwing Association',
    'base_url' => rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/'),
    'env' => 'production',
    'session' => [
        'name' => 'goldwing_session',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
        // Server-side idle timeout for DB-backed sessions (seconds). The
        // session slides forward on every request (bootstrap disables PHP's
        // lazy_write), so this is a true idle timeout, not a hard cap — it
        // gives members ample time to work through a renewal/checkout without
        // being bounced with "your session may have expired" mid-payment.
        'gc_maxlifetime' => 7200,
    ],
    'email' => [
        'from' => 'no-reply@example.com',
        'from_name' => 'Australian Goldwing Association',
    ],
    'stripe' => [
        'secret_key' => '',
        'webhook_secret' => '',
        'membership_prices' => [
            'FULL_1Y' => '',
            'FULL_3Y' => '',
            'ASSOCIATE_1Y' => '',
            'ASSOCIATE_3Y' => '',
            'LIFE' => '',
        ],
    ],
    'ai' => [
        'default_provider' => 'openrouter',
        'default_model' => getenv('AI_DEFAULT_MODEL') ?: 'deepseek/deepseek-chat',
        'provider' => 'openrouter',
        'api_key' => getenv('OPENROUTER_API_KEY') ?: '',
        'model' => getenv('AI_DEFAULT_MODEL') ?: 'deepseek/deepseek-chat',
        'providers' => [
            'openrouter' => [
                'label' => 'OpenRouter',
                'api_key' => getenv('OPENROUTER_API_KEY') ?: '',
                // Suggestions only — any model ID from openrouter.ai/models works.
                'models' => [
                    'deepseek/deepseek-chat',
                    'deepseek/deepseek-r1',
                    'openai/gpt-4.1-mini',
                    'openai/gpt-5-mini',
                    'google/gemini-2.5-flash',
                    'anthropic/claude-haiku-4.5',
                    'anthropic/claude-sonnet-4.5',
                ],
            ],
        ],
    ],
    'auth' => [
        'google' => [
            'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '',
            'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: '',
            'redirect_uri' => getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: '',
        ],
        'apple' => [
            'client_id' => getenv('APPLE_OAUTH_CLIENT_ID') ?: '',
            'team_id' => getenv('APPLE_OAUTH_TEAM_ID') ?: '',
            'key_id' => getenv('APPLE_OAUTH_KEY_ID') ?: '',
            'private_key_path' => getenv('APPLE_OAUTH_PRIVATE_KEY_PATH') ?: '',
            'redirect_uri' => getenv('APPLE_OAUTH_REDIRECT_URI') ?: '',
        ],
    ],
];
