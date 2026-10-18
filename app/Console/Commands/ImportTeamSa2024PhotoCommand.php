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
 * Import the 2024 Team SA group photo (taken in Colorado with the whole
 * squad kitted up on the Cameo Shooting Centre range) into the Media
 * album linked to the 2024 team year, and set it as the team-year cover.
 *
 * Mirrors ImportTeamSa2025PhotoCommand but with the Colorado stage photo
 * instead of a studio portrait. If a studio portrait surfaces later, add
 * a second upsert block below the stage-photo upsert — the same way the
 * 2026 command handles two photos.
 *
 * Idempotent: safe to re-run after a deploy. Existing items are matched
 * by caption and refreshed from the source file under /public/images.
 *
 * After the deploy, run:
 *   docker exec saprf-app php artisan saprf:import-team-sa-2024-photo --sync
 */
class ImportTeamSa2024PhotoCommand extends Command
{
    protected $signature = 'saprf:import-team-sa-2024-photo
        {--group=images/team-sa-iprf-worlds-2024.png : Colorado group photo under /public (team page cover)}
        {--sync : Run ProcessMediaItem inline instead of dispatching to the queue}';

    protected $description = 'Import the 2024 Team SA Colorado group photo for /sa-team/2024 as the team-year cover.';

    private const YEAR = 2024;

    private const CHAMPIONSHIP = '2024 IPRF World Championship (Centrefire)';

    private const DISCIPLINE = 'centrefire';

    private const HOST_COUNTRY = 'US';

    private const VENUE = 'Cameo Shooting Centre';

    private const CITY = 'Grand Junction, Colorado';

    private const STARTS_ON = '2024-09-11';

    private const ENDS_ON = '2024-09-17';

    private const ALBUM_TITLE = '2024 IPRF World Championship';

    private const ALBUM_SLUG = '2024-iprf-world-championship';

    private const GROUP_CAPTION = 'Team South Africa · 2024 IPRF World Championship, Grand Junction, Colorado';

    private const GROUP_ALT = 'The 2024 South African IPRF World Championship team on the Cameo Shooting Centre range in Grand Junction, Colorado';

    public function handle(): int
    {
        $groupAbs = public_path((string) $this->option('group'));

        if (! File::exists($groupAbs)) {
            $this->error("Group photo not found: {$groupAbs}");

            return self::FAILURE;
        }

        // 1. Ensure the 2024 NationalTeam row exists and has venue/dates.
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
                'venue_label' => self::CITY,
                'is_published' => true,
            ],
        );
        if ($album->national_team_id !== $team->id) {
            $album->update(['national_team_id' => $team->id]);
        }
        $this->info("MediaAlbum id={$album->id} '{$album->title}' ready.");

        $disk = Storage::disk(config('media.disk', 'public'));

        // 3. Group photo → team + album cover.
        $group = $this->upsertItem(
            disk: $disk,
            album: $album,
            sourceAbs: $groupAbs,
            caption: self::GROUP_CAPTION,
            alt: self::GROUP_ALT,
            credit: null,
            sortOrder: 10,
            matchCaptions: [self::GROUP_CAPTION],
            preferredId: $team->cover_media_item_id,
        );

        if ($album->cover_media_item_id !== $group->id) {
            $album->update(['cover_media_item_id' => $group->id]);
            $this->info('Set album cover to Colorado group photo.');
        }
        if ($team->cover_media_item_id !== $group->id) {
            $team->update(['cover_media_item_id' => $group->id]);
            $this->info('Set 2024 team cover to Colorado group photo.');
        }

        $this->newLine();
        $this->comment('Done. /sa-team/2024 now uses the Colorado group photo as its hero cover.');

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

        // Preserve the source file's extension so variant generation
        // sees the right format (PNG vs JPG matters for Intervention).
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
