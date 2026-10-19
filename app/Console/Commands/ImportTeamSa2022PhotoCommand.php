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
 * Import the 2022 Team SA formal group portrait into the Media album
 * linked to the 2022 team year, and set it as the team-year cover.
 *
 * Roster names are deliberately NOT seeded here — Exco will add
 * appearances later via the admin UI (or a future seeder). Until then
 * /sa-team/2022 shows the cover with "Squad to be announced".
 *
 * Venue / dates / host country are left null so Exco can fill them in
 * the admin UI once confirmed.
 *
 * Idempotent: safe to re-run after a deploy. Existing items are matched
 * by caption and refreshed from the source file under /public/images.
 *
 * After the deploy, run:
 *   docker exec saprf-app php artisan saprf:import-team-sa-2022-photo --sync
 */
class ImportTeamSa2022PhotoCommand extends Command
{
    protected $signature = 'saprf:import-team-sa-2022-photo
        {--studio=images/team-sa-iprf-worlds-2022.png : Studio portrait under /public (team page cover)}
        {--sync : Run ProcessMediaItem inline instead of dispatching to the queue}';

    protected $description = 'Import the 2022 Team SA studio portrait for /sa-team/2022 as the team-year cover (no roster yet).';

    private const YEAR = 2022;

    private const CHAMPIONSHIP = '2022 IPRF World Championship (Centrefire)';

    private const DISCIPLINE = 'centrefire';

    private const ALBUM_TITLE = '2022 IPRF World Championship';

    private const ALBUM_SLUG = '2022-iprf-world-championship';

    private const STUDIO_CAPTION = 'Team South Africa · 2022 IPRF World Championship';

    private const STUDIO_ALT = 'Formal group portrait of the 2022 South African IPRF World Championship team in green blazers';

    public function handle(): int
    {
        $studioAbs = public_path((string) $this->option('studio'));

        if (! File::exists($studioAbs)) {
            $this->error("Studio photo not found: {$studioAbs}");

            return self::FAILURE;
        }

        // 1. Ensure the 2022 NationalTeam row exists. Venue/dates/host
        // stay null until Exco confirms them in the admin UI.
        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            ['discipline' => self::DISCIPLINE],
        );
        $this->info("NationalTeam id={$team->id} ({$team->year} {$team->championship_name}) ready.");

        // 2. Album.
        $album = MediaAlbum::firstOrCreate(
            ['slug' => self::ALBUM_SLUG],
            [
                'title' => self::ALBUM_TITLE,
                'category' => 'team_sa',
                'national_team_id' => $team->id,
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
            $this->info('Set 2022 team cover to studio portrait.');
        }

        $this->newLine();
        $this->comment('Done. /sa-team/2022 uses the studio portrait as its hero cover. Roster names still TBD.');

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
