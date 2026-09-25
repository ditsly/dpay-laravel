<?php

declare(strict_types=1);

/*
 * DPay — إعدادات الحزمة / package configuration.
 *
 * Every secret comes from the environment (config:cache safe) and is never
 * persisted by the package. Switch DPAY_MODE between `sandbox` and `live`;
 * nothing else changes.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | الوضع / Mode
    |--------------------------------------------------------------------------
    | `sandbox` uses DPAY_SANDBOX_TOKEN (sb_tk_…) on the simulated banks —
    | magic OTPs 111111 / 000000, ids cs_test_…, webhooks with live:false.
    | `live` uses DPAY_API_TOKEN (an integration token with role:api).
    */
    'mode' => env('DPAY_MODE', 'sandbox'),

    'api_token' => env('DPAY_API_TOKEN'),
    'sandbox_token' => env('DPAY_SANDBOX_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | عنوان الواجهة / Base URL
    |--------------------------------------------------------------------------
    | https only, and only a DPay host (dpay.ly, next.dpay.ly, pg.dits.ly)
    | unless `allowed_hosts` opts a host in explicitly. `http://localhost`
    | is accepted only when `allow_http_localhost` is on (a local API stack).
    */
    'base_url' => env('DPAY_BASE_URL', 'https://dpay.ly'),
    'allowed_hosts' => array_filter(explode(',', (string) env('DPAY_ALLOWED_HOSTS', ''))),
    'allow_http_localhost' => (bool) env('DPAY_ALLOW_HTTP_LOCALHOST', false),

    /* Seconds per attempt; the API answers well inside 15 s. */
    'timeout' => (float) env('DPAY_TIMEOUT', 15),
    'connect_timeout' => (float) env('DPAY_CONNECT_TIMEOUT', 5),

    /* `ar` | `en` — Accept-Language on API calls and the hosted page's first-visit language. */
    'locale' => env('DPAY_LOCALE', 'ar'),

    /* A log channel name for the SDK's redacted request log, or null to stay silent. */
    'log_channel' => env('DPAY_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | مفاتيح التكرار / Idempotency
    |--------------------------------------------------------------------------
    | `DPay::checkout()->forOrder($id, $attempt)` derives a deterministic
    | Idempotency-Key from platform:store_uid:order:attempt, so a double-click
    | replays the same checkout. `store_uid` must be a random per-install
    | value (`php artisan dpay:doctor` prints one) — never copy it to a clone.
    */
    'platform' => env('DPAY_PLATFORM', 'laravel'),
    'store_uid' => env('DPAY_STORE_UID'),

    /*
    |--------------------------------------------------------------------------
    | الـ Webhook
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        /* Register POST {path} → DPay\Laravel\Http\Controllers\WebhookController. */
        'enabled' => (bool) env('DPAY_WEBHOOK_ENABLED', true),
        'path' => env('DPAY_WEBHOOK_PATH', 'dpay/webhook'),

        /* whsec_… from the dashboard; `previous_secret` keeps the old one valid during a rotation. */
        'secret' => env('DPAY_WEBHOOK_SECRET'),
        'previous_secret' => env('DPAY_WEBHOOK_PREVIOUS_SECRET'),

        /* |now − X-DPAY-Timestamp| ≤ tolerance (seconds). 300 per the DPay docs. */
        'tolerance' => (int) env('DPAY_WEBHOOK_TOLERANCE', 300),

        /*
         * A `live:false` event on a live store (or vice versa):
         *   `ignore` — answer 200 and dispatch nothing (the DPay guidance; the
         *              endpoint is set to "Live and test" on purpose),
         *   `reject` — answer 400 so the delivery shows as failed in the dashboard.
         */
        'environment_mismatch' => env('DPAY_WEBHOOK_ENVIRONMENT_MISMATCH', 'ignore'),

        /*
         * Duplicate suppression on (live, id, event). The same event can
         * legitimately arrive twice (retries after a late 2xx, "Send again").
         *   `cache`    — Cache::add() on the store below (works out of the box),
         *   `database` — the dpay_webhook_events table (publish the migration),
         *   `none`     — dispatch every delivery (your listeners dedupe).
         */
        'dedupe' => env('DPAY_WEBHOOK_DEDUPE', 'cache'),
        'dedupe_ttl' => (int) env('DPAY_WEBHOOK_DEDUPE_TTL', 7 * 24 * 3600),
        'cache_store' => env('DPAY_WEBHOOK_CACHE_STORE'),
        'table' => env('DPAY_WEBHOOK_TABLE', 'dpay_webhook_events'),

        /*
         * `sync`  — listeners run inside the request (answer 2xx when they return),
         * `queue` — the verified event is queued (DPay\Laravel\Jobs\HandleWebhook)
         *           and 2xx is answered at once; use it when listeners are slow.
         */
        'dispatch' => env('DPAY_WEBHOOK_DISPATCH', 'sync'),
        'queue' => env('DPAY_WEBHOOK_QUEUE'),
        'connection' => env('DPAY_WEBHOOK_QUEUE_CONNECTION'),

        /* Extra middleware for the route (the package adds its own signature check; no CSRF, no session). */
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | التسوية / Reconcile
    |--------------------------------------------------------------------------
    | `php artisan dpay:reconcile` re-reads pending checkouts; keep a run
    | under this many reads to stay inside the 120/min read throttle.
    */
    'reconcile' => [
        'limit' => (int) env('DPAY_RECONCILE_LIMIT', 30),
    ],
];
