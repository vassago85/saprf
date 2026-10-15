<?php

namespace App\Jobs;

use App\Models\MediaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Generate responsive JPEG + WebP variants for a freshly uploaded
 * MediaItem.
 *
 * Writes files alongside the original under `media/{album}/{item}/`
 * named `{width}.jpg` and `{width}.webp`. The original full-resolution
 * upload is kept untouched so the admin UI can serve it when needed.
 *
 * Idempotent: re-running overwrites existing variants in place.
 */
class ProcessMediaItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public MediaItem $item) {}

    public function handle(): void
    {
        $disk = Storage::disk($this->item->disk ?: config('media.disk', 'public'));

        if (! $disk->exists($this->item->path)) {
            Log::warning('ProcessMediaItem: source missing', [
                'item' => $this->item->id,
                'path' => $this->item->path,
            ]);

            return;
        }

        $manager = new ImageManager(new GdDriver);
        // Intervention Image v4 — decode binary payload.
        $image = $manager->decode($disk->get($this->item->path));

        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // Base path for variants — share a directory with the original so
        // deleting the row via cascade removes everything at once.
        $base = dirname($this->item->path);
        $stem = pathinfo($this->item->path, PATHINFO_FILENAME);

        $variants = [];
        $widths = config('media.widths', [480, 960, 1600]);
        $jpgQ = (int) config('media.quality.jpg', 82);
        $webpQ = (int) config('media.quality.webp', 80);

        foreach ($widths as $w) {
            // Don't upscale: cap each variant at the original's width.
            $target = min($w, $originalWidth);

            $resized = clone $image;
            if ($resized->width() > $target) {
                $resized->scaleDown(width: $target);
            }

            $jpgPath = $base.'/'.$stem.'-'.$w.'.jpg';
            $webpPath = $base.'/'.$stem.'-'.$w.'.webp';

            $disk->put($jpgPath, (string) $resized->encode(new JpegEncoder(quality: $jpgQ)));
            $disk->put($webpPath, (string) $resized->encode(new WebpEncoder(quality: $webpQ)));

            $variants[(string) $w] = [
                'jpg' => $jpgPath,
                'webp' => $webpPath,
            ];
        }

        $this->item->forceFill([
            'width' => $originalWidth,
            'height' => $originalHeight,
            'variants' => $variants,
            'processed_at' => now(),
        ])->save();
    }
}
