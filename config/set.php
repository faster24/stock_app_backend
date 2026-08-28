<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Thai Market Calendar
    |--------------------------------------------------------------------------
    |
    | What survives of the retired SET-index scraper. The scraper, the
    | `set:capture` command, the `TWOD_DRIVER=set` provider and the
    | set_session_results table are all gone; the trading calendar it defined is
    | not, because the live htayapi path depends on it — HtayApiFreshnessGuard
    | on the settlement path, and both side-number classes.
    |
    */

    // Market timezone (Asia/Bangkok, UTC+7). Distinct from the settlement date
    // logic, which uses Asia/Yangon — captures run mid-day, so the result_date
    // is unambiguous in either zone.
    'timezone' => env('SET_TIMEZONE', 'Asia/Bangkok'),

    /*
    |--------------------------------------------------------------------------
    | Thai SET Public Holidays (no draw)
    |--------------------------------------------------------------------------
    |
    | Y-m-d strings. Weekends are handled in code; list ONLY weekday holidays
    | when the exchange is shut. MUST be maintained yearly against the official
    | SET holiday calendar (https://www.set.or.th) — `SET_HOLIDAYS` overrides
    | this list wholesale, so a missing year can be patched without a deploy.
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
    | THIS LIST ONLY COVERS 2026. From 2027-01-01 every day reads as a trading
    | day, silently. The 2D feeds carry their own holiday signal (thaistock2d's
    | `holiday.status`); wire that up rather than extending this array again.
    | Losing `marketStatus` with the scraper removed the runtime backstop that
    | used to catch a missing date here, so this is now the only guard.
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
