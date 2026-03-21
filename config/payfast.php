<?php

return [
    'merchant_id' => env('PAYFAST_MERCHANT_ID', '10000100'),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY', '46f0cd694581a'),
    'passphrase' => env('PAYFAST_PASSPHRASE', 'jt7NOE43FZPn'),
    'sandbox' => env('PAYFAST_SANDBOX', true),

    'urls' => [
        'sandbox' => 'https://sandbox.payfast.co.za/eng/process',
        'live' => 'https://www.payfast.co.za/eng/process',
    ],

    'valid_hosts' => [
        'www.payfast.co.za',
        'sandbox.payfast.co.za',
        'w1w.payfast.co.za',
        'w2w.payfast.co.za',
    ],
];
