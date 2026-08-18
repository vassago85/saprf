<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VAPID keys
    |--------------------------------------------------------------------------
    |
    | Generate once per environment with:
    |
    |   php artisan webpush:vapid
    |
    | (see App\Console\Commands\GenerateVapidKeysCommand). The public key
    | is safe to expose to the browser; the private key must never leave
    | the server. `subject` is a `mailto:` link the push service uses to
    | contact us if we start sending abusive traffic.
    */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:noreply@saprf.co.za'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
