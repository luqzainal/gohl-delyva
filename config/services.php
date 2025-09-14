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

    'highlevel' => [
        'client_id' => env('HIGHLEVEL_CLIENT_ID'),
        'client_secret' => env('HIGHLEVEL_CLIENT_SECRET'),
        'redirect_uri' => env('HIGHLEVEL_REDIRECT_URI'),
        'base_url' => env('HIGHLEVEL_BASE_URL', 'https://services.leadconnectorhq.com'),
        'shared_secret' => env('HIGHLEVEL_SHARED_SECRET'),
    ],

    'delyva' => [
        'base_url' => env('DELYVA_BASE_URL', 'https://api.delyva.app'),
        'webhook_secret' => env('DELYVA_WEBHOOK_SECRET'),
    ],

];