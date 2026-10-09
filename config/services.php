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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
        
    'shopify' => [
        'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
    
        'admin_access_token' => env('SHOPIFY_ADMIN_ACCESS_TOKEN'),
    
        'api_secret' => env('SHOPIFY_API_SECRET'),
    
        'api_version' => env(
            'SHOPIFY_API_VERSION',
            '2026-07'
        ),
    
        'storefront_url' => env(
            'SHOPIFY_STOREFRONT_URL',
            'https://maisonchatkw.com'
        ),
    
        'proxy_path' => env(
            'SHOPIFY_PROXY_PATH',
            '/apps/customer-dashboard'
        ),
    ],

];
