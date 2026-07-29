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
    | SET holiday calendar (https://www.set.or.th) — `SET_HOLIDAYS` overrides
    | this list wholesale, so a missing year can be patched without a deploy.
    | `marketStatus` is the runtime backstop if a holiday is missing here.
    |
    | Why this matters: on a no-draw day the upstream 2D feeds do NOT report
    | "--". thaistock2d backfills every slot with the current live value and
    | htayapi keeps serving the last trading day's block, so an unlisted
    | holiday shows up as a real-looking result — the 2026-07-29 Asarnha Bucha
    | closure published the same number (73) against all four slots.
    |
    | 2026: 19 full closures, cross-checked against the published SET calendar
    | and the Thai public-holiday dates. Note Khao Phansa (2026-07-30) is a
    | national holiday but NOT a SET closure — the exchange trades that day.
    |
    */

    'holidays' => array_filter(explode(',', (string) env('SET_HOLIDAYS', '')))
        ?: [
            '2026-01-01', // New Year's Day
            '2026-01-02', // New Year's Day holiday
            '2026-03-03', // Makha Bucha Day
            '2026-04-06', // Chakri Memorial Day
            '2026-04-13', // Songkran Festival
            '2026-04-14', // Songkran Festival
            '2026-04-15', // Songkran Festival
            '2026-05-01', // National Labour Day
            '2026-05-04', // Coronation Day
            '2026-06-01', // Substitution for Visakha Bucha Day
            '2026-06-03', // H.M. Queen Suthida's Birthday
            '2026-07-28', // H.M. King Maha Vajiralongkorn's Birthday
            '2026-07-29', // Asarnha Bucha Day
            '2026-08-12', // H.M. Queen Sirikit The Queen Mother's Birthday
            '2026-10-13', // H.M. King Bhumibol Adulyadej The Great Memorial Day
            '2026-10-23', // Chulalongkorn Day
            '2026-12-07', // Substitution for H.M. King Bhumibol Adulyadej's Birthday
            '2026-12-10', // Constitution Day
            '2026-12-31', // New Year's Eve
        ],

];
