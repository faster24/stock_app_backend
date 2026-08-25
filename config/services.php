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

    /*
    |--------------------------------------------------------------------------
    | Scheduler Dead-Man's Switch
    |--------------------------------------------------------------------------
    |
    | Base ping URL (e.g. https://hc-ping.com/<ping-key>) for an external
    | watchdog. The scheduler pings it on every run; the watchdog alerts on the
    | *absence* of a ping. This is the one failure nothing inside the app can
    | report — if cron stops, the code that would notice never executes.
    |
    | Leave unset to disable pinging entirely.
    |
    */

    'healthchecks' => [
        'ping_url' => env('HEALTHCHECKS_PING_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 2D Result Provider
    |--------------------------------------------------------------------------
    |
    | The source of 2D lottery results used for settlement. `driver` selects the
    | active provider (see App\Services\TwoD\TwoDLiveProviderManager). Switching
    | vendors is an env change — no code deploy.
    |
    */

    'twod' => [
        'driver' => env('TWOD_DRIVER', 'thaistock2d'),
        'thaistock2d' => [
            'url' => env('THAISTOCK2D_URL', 'https://api.thaistock2d.com/live'),
            'timeout' => (int) env('THAISTOCK2D_TIMEOUT', 20),
        ],
        // Opt-in only — TWOD_DRIVER stays thaistock2d until manually flipped.
        //
        // daily_limit is an internal circuit breaker enforced by
        // HtayApiCallBudget, not the vendor's own quota. Sized against a
        // 30,000/day key: the live ticker's worst case is ~2,500/day (see the
        // TTL tiers below), so 8,000 is ~3x expected while still capping the
        // blast radius of a runaway loop at roughly a quarter of the quota.
        // Settlement and health checks share this budget and cost <50/day.
        //
        // The live_ttl_* values set how long TwoDLiveTickerService serves a
        // cached snapshot: tight around the 12:01/16:30 draws, relaxed during
        // the rest of the session, and slow overnight. Upstream cost is a
        // function of these alone — it does not scale with user count.
        //
        // threed_history_* and threed_live_* drive the read-only 3D feeds. It lives in this
        // block because it is the same vendor and the same key, and so shares
        // the daily_limit budget above. Nothing here settles a 3D bet — that
        // still runs off admin-entered ThreeDResult records. Draws land on the
        // 1st and 16th, so an hour of staleness costs nothing and one client
        // poll per hour is the whole upstream bill. threed_live_ttl is shorter
        // because that card sits behind an admin refresh button.
        'htayapi' => [
            'url' => env('HTAYAPI_URL', 'https://htayapi.com/mm-twod/thai/2dlive'),
            'key' => env('HTAYAPI_KEY'),
            'timeout' => (int) env('HTAYAPI_TIMEOUT', 20),
            'daily_limit' => (int) env('HTAYAPI_DAILY_LIMIT', 8000),
            'live_ttl_hot' => (int) env('HTAYAPI_LIVE_TTL_HOT', 5),
            'live_ttl_warm' => (int) env('HTAYAPI_LIVE_TTL_WARM', 20),
            'live_ttl_cold' => (int) env('HTAYAPI_LIVE_TTL_COLD', 300),
            'threed_history_url' => env('HTAYAPI_3D_HISTORY_URL', 'https://htayapi.com/mm-twod/thai/3dhistory'),
            'threed_history_ttl' => (int) env('HTAYAPI_3D_HISTORY_TTL', 3600),
            'threed_live_url' => env('HTAYAPI_3D_LIVE_URL', 'https://htayapi.com/mm-twod/thai/3dlive'),
            'threed_live_ttl' => (int) env('HTAYAPI_3D_LIVE_TTL', 60),
        ],
    ],

];
