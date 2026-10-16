<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMediaItem;
use App\Models\MediaAlbum;
use App\Models\MediaItem;
use App\Models\NationalTeam;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One-shot import: take the existing hard-coded 2026 Team SA photo
 * (public/images/team-sa-iprf-worlds-2026.jpg), move it into a Media
 * album, set it as the 2026 team cover, and promote it on the homepage
 * feature band.
 *
 * Idempotent: safe to re-run after a deploy. If the album, item, or
 * team row already exists, the command updates them in place rather
 * than creating duplicates. Re-running always refreshes the stored
 * original from the source file so a new team photo can be swapped in.
 *
 * After the deploy, run:
 *   docker exec saprf-app php artisan saprf:import-team-sa-2026-photo
 */
class ImportTeamSa2026PhotoCommand extends Command
{
    protected $signature = 'saprf:import-team-sa-2026-photo
        {--source=images/team-sa-iprf-worlds-2026.jpg : Path under /public to import}
        {--sync : Run ProcessMediaItem inline instead of dispatching to the queue}';

    protected $description = 'Import the 2026 Team SA photo into a Media album, set it as the team cover and feature on homepage.';

    private const YEAR = 2026;

    private const CHAMPIONSHIP = '2026 IPRF World Championship (Centrefire)';

    private const ALBUM_TITLE = '2026 IPRF World Championship';

    private const ALBUM_SLUG = '2026-iprf-world-championship';

    private const CAPTION = 'Team South Africa · 2026 IPRF World Championship';

    private const ALT_TEXT = 'Formal group portrait of the 2026 South African IPRF World Championship team in green blazers';

    /** @var list<string> */
    private const LEGACY_CAPTIONS = [
        'Team South Africa · 2026 IPRF World Championship, Lipan, Texas',
    ];

    public function handle(): int
    {
        $sourceRel = (string) $this->option('source');
        $sourceAbs = public_path($sourceRel);

        if (! File::exists($sourceAbs)) {
            $this->error("Source photo not found: {$sourceAbs}");

            return self::FAILURE;
        }

        // 1. Ensure the 2026 NationalTeam row exists and has venue/dates.
        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            ['discipline' => 'centrefire', 'host_country' => 'US'],
        );
        $teamUpdates = array_filter([
            'city' => $team->city ?: 'Lipan, Texas',
            'venue' => $team->venue ?: 'Gravestone Precision Shooting',
            'starts_on' => $team->starts_on ?: '2026-09-23',
            'ends_on' => $team->ends_on ?: '2026-09-28',
            'host_country' => $team->host_country ?: 'US',
        ]);
        $team->fill($teamUpdates)->save();
        $this->info("NationalTeam id={$team->id} ({$team->year} {$team->championship_name}) ready.");

        // 2. Album.
        $album = MediaAlbum::firstOrCreate(
            ['slug' => self::ALBUM_SLUG],
            [
                'title' => self::ALBUM_TITLE,
                'category' => 'team_sa',
                'national_team_id' => $team->id,
                'event_date' => '2026-09-23',
                'venue_label' => 'Lipan, Texas',
                'is_published' => true,
            ],
        );
        if ($album->national_team_id !== $team->id) {
            $album->update(['national_team_id' => $team->id]);
        }
        $this->info("MediaAlbum id={$album->id} '{$album->title}' ready.");

        // 3. MediaItem — prefer the existing team/album cover, then caption match.
        $disk = Storage::disk(config('media.disk', 'public'));
        $item = $this->resolveExistingItem($team, $album);

        if ($item === null) {
            $filename = Str::uuid()->toString().'.jpg';
            $destRel = 'media/'.$album->id.'/'.$filename;
            $disk->put($destRel, File::get($sourceAbs));

            $item = MediaItem::create([
                'media_album_id' => $album->id,
                'disk' => config('media.disk', 'public'),
                'path' => $destRel,
                'alt' => self::ALT_TEXT,
                'caption' => self::CAPTION,
                'credit' => null,
                'sort_order' => 10,
            ]);
            $this->info("Created MediaItem id={$item->id} at {$destRel}");
        } else {
            $disk->put($item->path, File::get($sourceAbs));
            $item->fill([
                'alt' => self::ALT_TEXT,
                'caption' => self::CAPTION,
                'credit' => null,
            ])->save();
            $this->info("Updated MediaItem id={$item->id} from source photo.");
        }

        // 4. Generate variants (safe to re-run — overwrites).
        if ($this->option('sync')) {
            (new ProcessMediaItem($item))->handle();
            $this->info('Processed media variants inline.');
        } else {
            Bus::dispatch(new ProcessMediaItem($item));
            $this->line('Dispatched ProcessMediaItem — variants will be generated by the queue worker.');
        }

        // 5. Album cover + Team cover.
        if ($album->cover_media_item_id !== $item->id) {
            $album->update(['cover_media_item_id' => $item->id]);
            $this->info('Set album cover.');
        }
        if ($team->cover_media_item_id !== $item->id) {
            $team->update(['cover_media_item_id' => $item->id]);
            $this->info('Set 2026 team cover.');
        }

        // 6. Feature on homepage (refresh copy even when already featured).
        $item->fill([
            'home_label' => 'Team South Africa',
            'home_headline' => 'Flying the flag at the 2026 IPRF World Championship',
            'home_summary' => 'The South African team that represented the country in Lipan, Texas.',
            'home_link_type' => 'sa_team',
        ])->save();

        if (! $item->is_home_feature) {
            $item->featureOnHomepage();
            $this->info('Featured on homepage.');
        } else {
            $this->info('Homepage feature copy refreshed.');
        }

        $this->newLine();
        $this->comment('Done. The homepage feature band, /sa-team/2026 hero and /media now use this photo.');

        return self::SUCCESS;
    }

    private function resolveExistingItem(NationalTeam $team, MediaAlbum $album): ?MediaItem
    {
        if ($team->cover_media_item_id) {
            $item = MediaItem::find($team->cover_media_item_id);
            if ($item !== null) {
                return $item;
            }
        }

        if ($album->cover_media_item_id) {
            $item = MediaItem::find($album->cover_media_item_id);
            if ($item !== null) {
                return $item;
            }
        }

        return MediaItem::query()
            ->where('media_album_id', $album->id)
            ->whereIn('caption', array_merge([self::CAPTION], self::LEGACY_CAPTIONS))
            ->first();
    }
}
