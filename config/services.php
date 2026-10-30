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

        // Key of the webhooks (Shopify > Settings > Notifications > Webhooks:
        // "Your webhooks will be signed with ..."). Falls back to api_secret.
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),

        // "authorized" orders (card authorized, captured later) create the purchase
        'accept_authorized' => env('SHOPIFY_ACCEPT_AUTHORIZED', true),

        // "Refresh" button (orders whose webhook was not received):
        // never before this date (start of the auto subscription, YYYY-MM-DD;
        // empty = date of the first order received by webhook)
        'sync_start' => env('SHOPIFY_SYNC_START'),

        // and only the last N days
        'sync_days' => (int) env('SHOPIFY_SYNC_DAYS', 7),

        // Automatic planning: first delivery = order day + N (no Sunday, 18:00 cutoff)
        'first_delivery_after_days' => (int) env('SHOPIFY_FIRST_DELIVERY_DAYS', 1),

        // Next delivery N days BEFORE the previous meals run out (0 = the day they run out)
        'delivery_lead_days' => (int) env('SHOPIFY_DELIVERY_LEAD_DAYS', 0),

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

    /*
    |--------------------------------------------------------------------------
    | ZOKO (WhatsApp OTP)
    |--------------------------------------------------------------------------
    */

    'zoko' => [
        'api_key' => env('ZOKO_API_KEY'),
        'base_url' => env('ZOKO_BASE_URL', 'https://chat.zoko.io/v2'),

        'otp_enabled' => env('ZOKO_OTP_ENABLED', false),
        'otp_template_id' => env('ZOKO_OTP_TEMPLATE_ID'),
        'otp_language' => env('ZOKO_OTP_LANGUAGE', 'ar'),
        'otp_types' => env('ZOKO_OTP_TYPES', 'buttonTemplate,template'),
        'otp_ttl_minutes' => (int) env('ZOKO_OTP_TTL_MINUTES', 10),
        'otp_resend_seconds' => (int) env('ZOKO_OTP_RESEND_SECONDS', 60),
        'otp_max_per_hour' => (int) env('ZOKO_OTP_MAX_PER_HOUR', 5),
        'otp_max_attempts' => (int) env('ZOKO_OTP_MAX_ATTEMPTS', 5),
        'default_country_code' => env('ZOKO_DEFAULT_COUNTRY_CODE', '965'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JAAK (delivery)
    |--------------------------------------------------------------------------
    |
    | Order marked "Prepared" -> JAAK task created (waiting), then the
    | "Request drivers" button in Orders. Statuses read every 10 minutes.
    |
    */

    'jaak' => [
        'enabled' => env('JAAK_ENABLED', false),
        'api_key' => env('JAAK_API_KEY'),
        'base_url' => env('JAAK_BASE_URL', 'https://jaaknow-demo.com/api'),

        // Create the JAAK task as soon as the order is marked Prepared
        'send_on_prepared' => env('JAAK_SEND_ON_PREPARED', true),

        // false = drivers requested with the button in Orders (chosen)
        'auto_request_drivers' => env('JAAK_AUTO_REQUEST_DRIVERS', false),

        // Our order id at JAAK: MC-PO-123 (change for the test server: MCTEST)
        'external_prefix' => env('JAAK_EXTERNAL_PREFIX', 'MC'),

        // First delivery day handled by JAAK (older orders are never sent)
        'start_date' => env('JAAK_START_DATE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery notifications (mobile app): prepared / on the way / delivered
    |--------------------------------------------------------------------------
    */

    'order_notifications' => [
        'enabled' => env('ORDER_NOTIFICATIONS_ENABLED', true),

        // Deliveries before this day are never notified (empty = JAAK_START_DATE)
        'sync_start' => env('SHOPIFY_SYNC_START', '2026-10-29')
    ],

];
