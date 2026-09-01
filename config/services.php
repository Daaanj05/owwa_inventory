<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
        // Small instruct model: PA narrative only rephrases deterministic at-risk facts.
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'qwen2.5:3b'),
    ],

    'rag' => [
        // Shared secret for GET /api/rag/context (Authorization: Bearer …).
        // Leave empty to deny all requests (fail closed).
        'context_token' => env('RAG_CONTEXT_TOKEN'),
    ],

    'libreoffice' => [
        // LibreOffice headless for OWWA PDF exports (Excel-like layout). Required for PDF export.
        'enabled' => filter_var(env('LIBREOFFICE_PDF', true), FILTER_VALIDATE_BOOLEAN),
        'binary' => env('LIBREOFFICE_BINARY', 'soffice'),
        'timeout' => (int) env('LIBREOFFICE_TIMEOUT', 90),
    ],

];
