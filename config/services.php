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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.eu.mailgun.net'),
        // Separate signing key found in Mailgun dashboard → Sending →
        // Webhooks. NOT the same value as MAILGUN_SECRET (which is the
        // sending API key). Every webhook posted at /webhooks/mailgun
        // is HMAC-SHA256 signed with this key, and the request is
        // rejected if the signature or the timestamp doesn't match.
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

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

    'chrome' => [
        'binary' => env('CHROME_PATH'),
    ],

    'google' => [
        // GA4 measurement ID. Leave empty to omit the tag (e.g. local).
        'analytics_id' => env('GOOGLE_ANALYTICS_ID', 'G-DESCP26KTX'),
    ],

];
