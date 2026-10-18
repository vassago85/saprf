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
 * Import the 2025 Team SA studio portrait into the Media album linked
 * to the 2025 PR22lr World Championships team year.
 *
 * Mirrors ImportTeamSa2026PhotoCommand but only handles the studio
 * portrait — the 2025 roster doesn't have a published stage photo
 * from the UK trip (yet), so there's no homepage-feature import pass.
 * When one surfaces, add a Texas-style second block here.
 *
 * Idempotent: safe to re-run after a deploy. Existing items are matched
 * by caption and refreshed from the source file under /public/images.
 *
 * After the deploy, run:
 *   docker exec saprf-app php artisan saprf:import-team-sa-2025-photo --sync
 */
class ImportTeamSa2025PhotoCommand extends Command
{
    protected $signature = 'saprf:import-team-sa-2025-photo
        {--studio=images/team-sa-iprf-pr22lr-worlds-2025.png : Studio portrait under /public (team page cover)}
        {--sync : Run ProcessMediaItem inline instead of dispatching to the queue}';

    protected $description = 'Import the 2025 Team SA studio portrait for /sa-team/2025 as the team-year cover.';

    private const YEAR = 2025;

    private const CHAMPIONSHIP = '2025 IPRF PR22lr World Championships';

    private const DISCIPLINE = 'rimfire';

    private const HOST_COUNTRY = 'GB';

    private const VENUE = 'West Midlands Shooting Centre';

    private const CITY = 'West Midlands';

    private const STARTS_ON = '2025-08-17';

    private const ENDS_ON = '2025-08-24';

    private const ALBUM_TITLE = '2025 IPRF PR22lr World Championships';

    private const ALBUM_SLUG = '2025-iprf-pr22lr-world-championships';

    private const STUDIO_CAPTION = 'Team South Africa · 2025 IPRF PR22lr World Championships';

    private const STUDIO_ALT = 'Formal group portrait of the 2025 South African IPRF PR22lr World Championship team in green blazers';

    public function handle(): int
    {
        $studioAbs = public_path((string) $this->option('studio'));

        if (! File::exists($studioAbs)) {
            $this->error("Studio photo not found: {$studioAbs}");

            return self::FAILURE;
        }

        // 1. Ensure the 2025 NationalTeam row exists and has venue/dates.
        // firstOrCreate only writes defaults on insert, so any Exco
        // adjustments to venue/dates in the admin UI are preserved.
        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            ['discipline' => self::DISCIPLINE, 'host_country' => self::HOST_COUNTRY],
        );
        $teamUpdates = array_filter([
            'city' => $team->city ?: self::CITY,
            'venue' => $team->venue ?: self::VENUE,
            'starts_on' => $team->starts_on ?: self::STARTS_ON,
            'ends_on' => $team->ends_on ?: self::ENDS_ON,
            'host_country' => $team->host_country ?: self::HOST_COUNTRY,
        ], fn ($v) => $v !== null && $v !== '');
        if ($teamUpdates !== []) {
            $team->fill($teamUpdates)->save();
        }
        $this->info("NationalTeam id={$team->id} ({$team->year} {$team->championship_name}) ready.");

        // 2. Album.
        $album = MediaAlbum::firstOrCreate(
            ['slug' => self::ALBUM_SLUG],
            [
                'title' => self::ALBUM_TITLE,
                'category' => 'team_sa',
                'national_team_id' => $team->id,
                'event_date' => self::STARTS_ON,
                'venue_label' => self::VENUE,
                'is_published' => true,
            ],
        );
        if ($album->national_team_id !== $team->id) {
            $album->update(['national_team_id' => $team->id]);
        }
        $this->info("MediaAlbum id={$album->id} '{$album->title}' ready.");

        $disk = Storage::disk(config('media.disk', 'public'));

        // 3. Studio portrait → team + album cover.
        $studio = $this->upsertItem(
            disk: $disk,
            album: $album,
            sourceAbs: $studioAbs,
            caption: self::STUDIO_CAPTION,
            alt: self::STUDIO_ALT,
            credit: null,
            sortOrder: 10,
            matchCaptions: [self::STUDIO_CAPTION],
            preferredId: $team->cover_media_item_id,
        );

        if ($album->cover_media_item_id !== $studio->id) {
            $album->update(['cover_media_item_id' => $studio->id]);
            $this->info('Set album cover to studio portrait.');
        }
        if ($team->cover_media_item_id !== $studio->id) {
            $team->update(['cover_media_item_id' => $studio->id]);
            $this->info('Set 2025 team cover to studio portrait.');
        }

        $this->newLine();
        $this->comment('Done. /sa-team/2025 now uses the studio portrait as its hero cover.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $matchCaptions
     */
    private function upsertItem(
        $disk,
        MediaAlbum $album,
        string $sourceAbs,
        string $caption,
        string $alt,
        ?string $credit,
        int $sortOrder,
        array $matchCaptions,
        ?int $preferredId,
    ): MediaItem {
        $item = $preferredId ? MediaItem::find($preferredId) : null;

        if ($item === null) {
            $item = MediaItem::query()
                ->where('media_album_id', $album->id)
                ->whereIn('caption', $matchCaptions)
                ->first();
        }

        // Preserve the source file's extension so Image::make /
        // Intervention can detect the format. The 2025 source is a PNG;
        // storing it as .jpg would truncate the file + mislead variant
        // generation.
        $extension = strtolower(pathinfo($sourceAbs, PATHINFO_EXTENSION) ?: 'jpg');

        if ($item === null) {
            $filename = Str::uuid()->toString().'.'.$extension;
            $destRel = 'media/'.$album->id.'/'.$filename;
            $disk->put($destRel, File::get($sourceAbs));

            $item = MediaItem::create([
                'media_album_id' => $album->id,
                'disk' => config('media.disk', 'public'),
                'path' => $destRel,
                'alt' => $alt,
                'caption' => $caption,
                'credit' => $credit,
                'sort_order' => $sortOrder,
            ]);
            $this->info("Created MediaItem id={$item->id} ({$caption}) at {$destRel}");
        } else {
            $disk->put($item->path, File::get($sourceAbs));
            $item->fill([
                'alt' => $alt,
                'caption' => $caption,
                'credit' => $credit,
                'sort_order' => $sortOrder,
            ])->save();
            $this->info("Updated MediaItem id={$item->id} ({$caption}) from source photo.");
        }

        if ($this->option('sync')) {
            (new ProcessMediaItem($item))->handle();
            $this->info("Processed variants for MediaItem id={$item->id}.");
        } else {
            Bus::dispatch(new ProcessMediaItem($item));
            $this->line("Dispatched ProcessMediaItem for id={$item->id}.");
        }

        return $item->fresh();
    }
}
