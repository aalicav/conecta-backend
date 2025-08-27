<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Whapi WhatsApp API Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the Whapi WhatsApp API integration.
    | You can find your API key and other settings in your Whapi dashboard.
    |
    */

    'api_key' => env('WHAPI_API_KEY'),

    'base_url' => env('WHAPI_BASE_URL', 'https://gate.whapi.cloud'),

    'webhook_url' => env('WHAPI_WEBHOOK_URL', env('APP_URL') . '/api/whatsapp/webhook'),

    'from_number' => env('WHAPI_FROM_NUMBER', 'system'),

    /*
    |--------------------------------------------------------------------------
    | Message Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for WhatsApp messages
    |
    */

    'default_preview_url' => env('WHAPI_DEFAULT_PREVIEW_URL', true),

    'message_timeout' => env('WHAPI_MESSAGE_TIMEOUT', 30),

    'retry_attempts' => env('WHAPI_RETRY_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Media Settings
    |--------------------------------------------------------------------------
    |
    | Settings for media messages
    |
    */

    'max_file_size' => env('WHAPI_MAX_FILE_SIZE', 16 * 1024 * 1024), // 16MB

    'supported_media_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif'],
        'video' => ['mp4', '3gp', 'avi', 'mov'],
        'audio' => ['mp3', 'ogg', 'wav', 'm4a'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
        'sticker' => ['webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Settings
    |--------------------------------------------------------------------------
    |
    | Settings for template messages
    |
    */

    'default_language' => env('WHAPI_DEFAULT_LANGUAGE', 'pt_BR'),

    'template_namespace' => env('WHAPI_TEMPLATE_NAMESPACE', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Settings for incoming webhooks
    |
    */

    'webhook_verify_token' => env('WHAPI_WEBHOOK_VERIFY_TOKEN'),

    'webhook_secret' => env('WHAPI_WEBHOOK_SECRET'),

    'webhook_events' => [
        'message',
        'message_status',
        'media',
        'contact',
        'location',
        'button',
        'list',
        'template',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting settings for API calls
    |
    */

    'rate_limit' => [
        'messages_per_minute' => env('WHAPI_RATE_LIMIT_MESSAGES_PER_MINUTE', 60),
        'messages_per_hour' => env('WHAPI_RATE_LIMIT_MESSAGES_PER_HOUR', 1000),
        'messages_per_day' => env('WHAPI_RATE_LIMIT_MESSAGES_PER_DAY', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Logging settings for debugging and monitoring
    |
    */

    'log_level' => env('WHAPI_LOG_LEVEL', 'info'),

    'log_requests' => env('WHAPI_LOG_REQUESTS', true),

    'log_responses' => env('WHAPI_LOG_RESPONSES', true),

    'log_webhooks' => env('WHAPI_LOG_WEBHOOKS', true),

];
