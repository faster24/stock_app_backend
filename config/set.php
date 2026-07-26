<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SET Index Scraper
    |--------------------------------------------------------------------------
    |
    | Configuration for the headless-browser scraper that reads the SET index
    | (behind Incapsula) and the Myanmar 2D pipeline built on top of it. The
    | Laravel `set:capture` command shells out to the Node script via
    | Symfony\Component\Process.
    |
    */

    'node_binary' => env('SET_NODE_BINARY', 'node'),

    'script_path' => env('SET_SCRAPER_PATH', base_path('scripts/set-scraper/set-capture.mjs')),

    'symbol' => env('SET_SYMBOL', 'SET'),

    'warmup_url' => env('SET_WARMUP_URL', 'https://www.set.or.th/en/market/product/stock/overview'),

    'api_url' => env('SET_API_URL', 'https://www.set.or.th/api/set/index/info/list?type=INDEX'),

    // Market timezone (Asia/Bangkok, UTC+7). Distinct from the existing
    // settlement date logic which uses Asia/Yangon — captures run mid-day so
    // the result_date is unambiguous in either zone.
    'timezone' => env('SET_TIMEZONE', 'Asia/Bangkok'),

    // Hard ceiling (seconds) on the Node process before Symfony\Process kills it.
    // Must exceed the poll budget below.
    'process_timeout' => (int) env('SET_PROCESS_TIMEOUT', 180),

    // Open sessions (09:30, 14:00): values oscillate, poll until stable.
    'poll' => [
        'interval' => (int) env('SET_POLL_INTERVAL', 12),
        'max_duration' => (int) env('SET_POLL_MAX_DURATION', 90),
        'stable_streak' => (int) env('SET_POLL_STABLE_STREAK', 2),
    ],

    // Close sessions (12:01, 16:30): final value, retry through API latency.
    'retry' => [
        'interval' => (int) env('SET_RETRY_INTERVAL', 10),
        'max_attempts' => (int) env('SET_RETRY_MAX_ATTEMPTS', 5),
    ],

    // marketStatus values that should ABORT storage (treat the reading as a
    // non-draw / invalid state). Left empty by default: the 12:01 "morning
    // close" is only a snapshot time — the SET morning session is still "Open"
    // then — so status is NOT a reliable finalized-close gate. Weekends +
    // config('set.holidays') are the real no-draw guard. Populate this only once
    // SET's holiday status vocabulary is confirmed.
    'abort_market_statuses' => array_filter(explode(',', (string) env('SET_ABORT_MARKET_STATUSES', ''))),

    /*
    |--------------------------------------------------------------------------
    | Thai SET Public Holidays (no draw)
    |--------------------------------------------------------------------------
    |
    | Y-m-d strings. Weekends are handled in code; list ONLY weekday holidays
    | when the exchange is shut. MUST be maintained yearly against the official
    | SET holiday calendar (https://www.set.or.th) — the entries below are a
    | starting point and are NOT authoritative. `marketStatus` is the runtime
    | backstop if a holiday is missing here.
    |
    */

    'holidays' => array_filter(explode(',', (string) env('SET_HOLIDAYS', '')))
        ?: [
            // TODO: replace with the verified official SET calendar for the year.
            '2026-01-01', // New Year's Day
        ],

];
