<?php

declare(strict_types=1);

// Laravel configuration of the Enigmoss SDK. Publish it with:
//   php artisan vendor:publish --tag=enigmoss-config

return [
    // Connection used by the Enigmoss facade and by an injected EnigmossClient.
    'default' => env('ENIGMOSS_CONNECTION', 'default'),

    // One connection per project. Platforms serving several projects add more entries and use
    // Enigmoss::connection('name').
    'connections' => [
        'default' => [
            // API host from your dashboard, e.g. "https://api.example.com". Required.
            'base_url' => env('ENIGMOSS_BASE_URL'),
            'project_id' => env('ENIGMOSS_PROJECT_ID'),
            // Payments, wallets, balances, webhooks. Also verifies incoming webhooks.
            'payment_key' => env('ENIGMOSS_PAYMENT_KEY'),
            // Payouts, refunds, transfers. Leave empty if you do not send money out.
            'payout_key' => env('ENIGMOSS_PAYOUT_KEY'),
            // Optional host asked for the current API origin when base_url cannot be reached
            // (https://tavrion.org for the production API).
            // The origin it names is kept in the default cache store.
            'fallback_base_url' => env('ENIGMOSS_FALLBACK_BASE_URL'),
        ],
    ],

    'timeout' => (float) env('ENIGMOSS_TIMEOUT', 30),
    'connect_timeout' => (float) env('ENIGMOSS_CONNECT_TIMEOUT', 10),
    'max_retries' => (int) env('ENIGMOSS_MAX_RETRIES', 2),
    'max_retry_delay' => (float) env('ENIGMOSS_MAX_RETRY_DELAY', 10),

    // Only for a local API over plain http.
    'allow_insecure_http' => (bool) env('ENIGMOSS_ALLOW_INSECURE_HTTP', false),

    // Log channel for API calls (method, path, status, request id); null disables logging.
    'log_channel' => env('ENIGMOSS_LOG_CHANNEL'),

    'webhooks' => [
        // Accepted age of the X-Timestamp header, seconds.
        'tolerance' => (int) env('ENIGMOSS_WEBHOOK_TOLERANCE', 300),
        // Webhooks are acknowledged at once and handled by a queued job that fires
        // Enigmoss\Bridge\Laravel\Events\WebhookReceived. Null uses the default queue.
        'queue_connection' => env('ENIGMOSS_WEBHOOK_QUEUE_CONNECTION'),
        'queue' => env('ENIGMOSS_WEBHOOK_QUEUE'),
        // How long a handled event_id is remembered to drop redeliveries, seconds.
        'dedupe_ttl' => (int) env('ENIGMOSS_WEBHOOK_DEDUPE_TTL', 259200),
    ],
];
