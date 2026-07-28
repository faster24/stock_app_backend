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
    ],

];
