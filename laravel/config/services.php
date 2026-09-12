<?php

return [

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret' => env('YOOKASSA_SECRET'),
    ],

    'freekassa' => [
        'shop_id' => env('FREEKASSA_SHOP_ID'),
        'api_key' => env('FREEKASSA_API_KEY'),
        'secret2' => env('FREEKASSA_SECRET2'),
        'payment_id' => env('FREEKASSA_PAYMENT_ID', '44'),
    ],

    'remnawave' => [
        'url' => env('REMNAWAVE_URL'),
        'token' => env('REMNAWAVE_TOKEN'),
        'squad' => env('REMNAWAVE_SQUAD_UUID'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    ],

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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
