<?php

use Spatie\LaravelPdf\Caching\DefaultPdfCache;
use Spatie\LaravelPdf\Encryption\DefaultPdfEncrypter;
use Spatie\LaravelPdf\Jobs\GeneratePdfJob;

return [
    /*
     * The default driver to use for PDF generation.
     *
     * Default is `dompdf` for this project — Browsershot/Puppeteer is too
     * fragile to keep green on Alpine (Node 24 + chromium path/sandbox drift),
     * and the only PDF going through this facade (the MD payout statement)
     * is a plain HTML/CSS layout that DomPDF renders cleanly.
     *
     * Supported: "browsershot", "cloudflare", "dompdf", "gotenberg", "chrome"
     */
    'driver' => env('LARAVEL_PDF_DRIVER', 'dompdf'),

    /*
     * Render caching. When you call `->cache()` on a PDF, the generated
     * content is stored so identical renders are served from the cache.
     */
    'cache' => [
        'class' => DefaultPdfCache::class,
        'automatic' => env('LARAVEL_PDF_CACHE_AUTOMATIC', false),
        'store' => env('LARAVEL_PDF_CACHE_STORE'),
        'prefix' => 'laravel-pdf',
        'ttl' => env('LARAVEL_PDF_CACHE_TTL', 60 * 60 * 24),
    ],

    /*
     * Browsershot driver configuration — kept so swapping LARAVEL_PDF_DRIVER
     * back to "browsershot" would still work if we ever install the stack.
     */
    'browsershot' => [
        'node_binary' => env('LARAVEL_PDF_NODE_BINARY'),
        'npm_binary' => env('LARAVEL_PDF_NPM_BINARY'),
        'include_path' => env('LARAVEL_PDF_INCLUDE_PATH'),
        'chrome_path' => env('LARAVEL_PDF_CHROME_PATH'),
        'node_modules_path' => env('LARAVEL_PDF_NODE_MODULES_PATH'),
        'bin_path' => env('LARAVEL_PDF_BIN_PATH'),
        'temp_path' => env('LARAVEL_PDF_TEMP_PATH'),
        'write_options_to_file' => env('LARAVEL_PDF_WRITE_OPTIONS_TO_FILE', true),
        'no_sandbox' => env('LARAVEL_PDF_NO_SANDBOX', false),
    ],

    /*
     * Cloudflare Browser Rendering driver configuration (unused).
     */
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    ],

    /*
     * Gotenberg driver configuration (unused).
     */
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://localhost:3000'),
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

    /*
     * DOMPDF driver configuration — the active driver for this project.
     *
     * dompdf/dompdf is already installed via barryvdh/laravel-dompdf and
     * handles the plain-HTML payout statement without any binaries.
     */
    'dompdf' => [
        'is_remote_enabled' => env('LARAVEL_PDF_DOMPDF_REMOTE_ENABLED', false),
        'chroot' => env('LARAVEL_PDF_DOMPDF_CHROOT'),
        'font_dir' => env('LARAVEL_PDF_DOMPDF_FONT_DIR', storage_path('fonts')),
        'font_cache' => env('LARAVEL_PDF_DOMPDF_FONT_CACHE', storage_path('fonts')),
    ],

    /*
     * WeasyPrint driver configuration (unused).
     */
    'weasyprint' => [
        'binary' => env('LARAVEL_PDF_WEASYPRINT_BINARY', 'weasyprint'),
        'timeout' => 10,
    ],

    /*
     * Chrome PHP driver configuration (unused).
     */
    'chrome' => [
        'chrome_binary' => env('LARAVEL_PDF_CHROME_BINARY'),
        'no_sandbox' => env('LARAVEL_PDF_CHROME_NO_SANDBOX', false),
        'startup_timeout' => env('LARAVEL_PDF_CHROME_STARTUP_TIMEOUT', 30),
        'timeout' => env('LARAVEL_PDF_CHROME_TIMEOUT', 30000),
        'operation_timeout' => env('LARAVEL_PDF_CHROME_OPERATION_TIMEOUT', 5000),
        'user_data_dir' => env('LARAVEL_PDF_CHROME_USER_DATA_DIR'),
        'custom_flags' => [],
        'env_variables' => [],
    ],

    /*
     * The job class used for queued PDF generation.
     */
    'job' => GeneratePdfJob::class,

    /*
     * The class used to encrypt and decrypt password-protected PDFs.
     */
    'encrypter' => DefaultPdfEncrypter::class,
];
