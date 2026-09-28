<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "openai", "gemini", "fake"
    |
    */
    'provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Global API Key & Model Defaults
    |--------------------------------------------------------------------------
    |
    | These can be overridden per provider below if individual keys are used.
    |
    */
    'api_key' => env('AI_API_KEY', env('OPENAI_API_KEY')),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),
    'timeout' => (int) env('AI_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Provider Specific Configurations
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY', env('AI_API_KEY')),
            'model' => env('OPENAI_MODEL', env('AI_MODEL', 'gpt-4o-mini')),
            'timeout' => (int) env('AI_TIMEOUT', 30),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', env('AI_API_KEY')),
            'model' => env('GEMINI_MODEL', env('AI_MODEL', 'gemini-1.5-flash')),
            'timeout' => (int) env('AI_TIMEOUT', 30),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'fake' => [
            'api_key' => 'fake-key',
            'model' => 'fake-model',
            'timeout' => 5,
        ],
    ],
];
