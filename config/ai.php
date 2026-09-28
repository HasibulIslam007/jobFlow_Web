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

    /*
    |--------------------------------------------------------------------------
    | OCR (image/screenshot capture)
    |--------------------------------------------------------------------------
    |
    | Separate from `provider` because OCR has different needs: it sends image
    | bytes rather than a prompt, and its output is a plain transcription that
    | the extraction prompt consumes afterwards.
    |
    | Supported: "gemini" (vision), "fake"
    |
    | NOTE: "fake" returns a fixed placeholder string. If image capture fails
    | with "title and company must not be empty", check this is not still set
    | to "fake" — the model is being handed a placeholder instead of a real
    | transcription.
    |
    */
    'ocr' => [
        'provider' => env('OCR_PROVIDER', 'gemini'),
        'api_key' => env('OCR_API_KEY', env('GEMINI_API_KEY', env('AI_API_KEY'))),
        'model' => env('OCR_MODEL', env('GEMINI_MODEL', 'gemini-flash-lite-latest')),
        'timeout' => (int) env('OCR_TIMEOUT', 60),
        'base_url' => env(
            'OCR_BASE_URL',
            env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta')
        ),
    ],
];
