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
        'default_provider' => getenv('AI_DEFAULT_PROVIDER') ?: 'openai',
        'default_model' => getenv('AI_DEFAULT_MODEL') ?: 'gpt-4o-mini',
        'provider' => getenv('AI_DEFAULT_PROVIDER') ?: 'openai',
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model' => getenv('AI_DEFAULT_MODEL') ?: 'gpt-4o-mini',
        'providers' => [
            'openai' => [
                'label' => 'OpenAI',
                'api_key' => getenv('OPENAI_API_KEY') ?: '',
                'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'],
            ],
            'gemini' => [
                'label' => 'Gemini',
                'api_key' => getenv('GEMINI_API_KEY') ?: '',
                'models' => ['gemini-1.5-flash', 'gemini-1.5-pro'],
            ],
            'claude' => [
                'label' => 'Claude',
                'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
                'models' => ['claude-3-5-sonnet-20240620', 'claude-3-5-haiku-20241022'],
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
