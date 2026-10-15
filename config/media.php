<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    | The filesystem disk media items live on. Defaults to `public` which is
    | storage/app/public, exposed via the `storage` symlink. In production
    | you can point this at `r2` or `s3`.
    */

    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Variant widths
    |--------------------------------------------------------------------------
    | The ProcessMediaItem job writes one JPEG + one WebP per width.
    | Keep this list in sync with App\Models\MediaItem::WIDTHS.
    */

    'widths' => [480, 960, 1600],

    /*
    |--------------------------------------------------------------------------
    | JPEG / WebP quality
    |--------------------------------------------------------------------------
    */

    'quality' => [
        'jpg' => 82,
        'webp' => 80,
    ],

];
