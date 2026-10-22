<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMediaItem;
use App\Models\MediaAlbum;
use App\Models\MediaItem;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Put the 2028 Worlds announcement poster on the public landing page.
 *
 * The file ships in the image under public/images. This command copies it
 * onto the media disk (a Docker volume, so a rebuild does not already
 * contain it) and features it once per poster version. Later homepage
 * photo changes in admin stay put until the version constant changes.
 *
 * The production entrypoint runs this on the web container.
 */
class FeatureWorlds2028PosterCommand extends Command
{
    protected $signature = 'saprf:feature-worlds-2028-poster
        {--poster=images/iprf-centerfire-worlds-2028.png : Poster path, absolute or under /public}
        {--sync : Generate image variants inline instead of queueing them}';

    protected $description = 'Feature the 2028 IPRF Worlds poster on the public landing page.';

    private const VERSION = '2028-rondavels-v1';

    private const VERSION_KEY = 'landing_poster_version';

    private const HASH_KEY = 'landing_poster_sha256';

    private const ALBUM_SLUG = 'iprf-centerfire-world-championship-2028';

    private const ALBUM_TITLE = 'IPRF Centerfire World Championship 2028';

    private const CAPTION = 'IPRF Centerfire World Championship, Mpumalanga';

    private const ALT = 'Poster announcing that South Africa will host the IPRF Centerfire World Championship in Mpumalanga in August 2028';

    public function handle(SettingsService $settings): int
    {
        $source = $this->sourcePath();

        if (! File::exists($source)) {
            $this->error("Poster not found: {$source}");

            return self::FAILURE;
        }

        $album = MediaAlbum::firstOrCreate(
            ['slug' => self::ALBUM_SLUG],
            [
                'title' => self::ALBUM_TITLE,
                'category' => 'other',
                'event_date' => '2028-08-01',
                'venue_label' => 'Mpumalanga',
                'is_published' => true,
            ],
        );

        if (! $album->is_published) {
            $album->update(['is_published' => true]);
        }

        $disk = Storage::disk(config('media.disk', 'public'));
        $hash = hash_file('sha256', $source);
        $item = $this->upsertItem($disk, $album, $source);

        $storedHash = (string) $settings->get(self::HASH_KEY, '');
        if ($storedHash !== $hash || $item->processed_at === null) {
            $disk->put($item->path, File::get($source));

            if ($this->option('sync')) {
                (new ProcessMediaItem($item))->handle();
                $this->info("Processed variants for MediaItem id={$item->id}.");
            } else {
                ProcessMediaItem::dispatch($item);
                $this->line("Dispatched ProcessMediaItem for id={$item->id}.");
            }

            $settings->set(self::HASH_KEY, $hash, 'SHA-256 of the landing-page Worlds poster source file.');
            $item = $item->fresh();
        }

        if ($album->cover_media_item_id !== $item->id) {
            $album->update(['cover_media_item_id' => $item->id]);
        }

        if ((string) $settings->get(self::VERSION_KEY, '') !== self::VERSION) {
            $item->fill([
                'alt' => self::ALT,
                'caption' => self::CAPTION,
                'home_label' => 'Host federation',
                'home_headline' => 'South Africa will host the IPRF Centerfire World Championship',
                'home_summary' => 'August 2028 in Mpumalanga.',
                'home_link_type' => 'custom',
                'home_link_url' => route('events.index'),
            ])->save();
            $item->featureOnHomepage();
            $settings->set(self::VERSION_KEY, self::VERSION, 'Landing-page poster version last applied on boot.');
            $this->info('Featured the 2028 Worlds poster on the homepage.');
        } else {
            $this->info('Homepage poster version already applied.');
        }

        return self::SUCCESS;
    }

    private function sourcePath(): string
    {
        $path = (string) $this->option('poster');

        if (File::exists($path)) {
            return $path;
        }

        return public_path($path);
    }

    private function upsertItem($disk, MediaAlbum $album, string $source): MediaItem
    {
        $item = MediaItem::query()
            ->where('media_album_id', $album->id)
            ->where('caption', self::CAPTION)
            ->first();

        if ($item !== null) {
            return $item;
        }

        $filename = Str::uuid()->toString().'.png';
        $destRel = 'media/'.$album->id.'/'.$filename;
        $disk->put($destRel, File::get($source));

        return MediaItem::create([
            'media_album_id' => $album->id,
            'disk' => config('media.disk', 'public'),
            'path' => $destRel,
            'alt' => self::ALT,
            'caption' => self::CAPTION,
            'sort_order' => 10,
        ]);
    }
}
