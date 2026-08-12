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

    /*
     * Payfast's extended/redundancy source ranges for ITN callbacks. These may
     * not resolve via the hostnames above, so they are matched explicitly (CIDR
     * or single IP) to avoid rejecting legitimate notifications during failover.
     *
     * @see https://developers.payfast.co.za (extended IP range notice, 2023)
     */
    'valid_ip_ranges' => [
        '197.97.145.144/28',
        '41.74.179.192/27',
        '102.216.36.0/28',
        '102.216.36.128/28',
        '144.126.193.139',
    ],
];
