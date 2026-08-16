<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Set SECURITY_HEADERS_CSP=false to drop the Content-Security-Policy header
    | while debugging a third-party embed. The other hardening headers in
    | App\Http\Middleware\SecurityHeaders are always sent.
    |
    */

    'csp_enabled' => env('SECURITY_HEADERS_CSP', true),

];
