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
 * Import the 2023 Team SA PR22 (PRS .22) photos into the Media album
 * linked to that team year.
 *
 *   - Formal group portrait → NationalTeam cover (/sa-team hero)
 *   - Range photo           → album only (not the homepage feature)
 *
 * Roster names are deliberately NOT seeded — Exco will add appearances
 * later. Until then /sa-team/2023 shows the cover with "Squad to be
 * announced". Venue / dates / host country stay null until confirmed.
 *
 * Idempotent: safe to re-run after a deploy.
 *
 * After the deploy, run:
 *   docker exec saprf-app php artisan saprf:import-team-sa-2023-photo --sync
 */
class ImportTeamSa2023PhotoCommand extends Command
{
    protected $signature = 'saprf:import-team-sa-2023-photo
        {--studio=images/team-sa-iprf-pr22lr-worlds-2023.png : Formal portrait under /public (team page cover)}
        {--range=images/team-sa-iprf-pr22lr-worlds-2023-range.png : Range photo under /public (album only)}
        {--sync : Run ProcessMediaItem inline instead of dispatching to the queue}';

    protected $description = 'Import the 2023 Team SA PR22 photos for /sa-team/2023 (formal portrait as cover, no roster yet).';

    private const YEAR = 2023;

    private const CHAMPIONSHIP = '2023 IPRF PR22lr World Championships';

    private const DISCIPLINE = 'rimfire';

    private const ALBUM_TITLE = '2023 IPRF PR22lr World Championships';

    private const ALBUM_SLUG = '2023-iprf-pr22lr-world-championships';

    private const STUDIO_CAPTION = 'Team South Africa · 2023 IPRF PR22lr World Championships';

    private const STUDIO_ALT = 'Formal group portrait of the 2023 South African PRS .22 team in green blazers';

    private const RANGE_CAPTION = 'Team South Africa · 2023 IPRF PR22lr World Championships, range';

    private const RANGE_ALT = 'The 2023 South African PRS .22 team in match kit holding the South African flag';

    public function handle(): int
    {
        $studioAbs = public_path((string) $this->option('studio'));
        $rangeAbs = public_path((string) $this->option('range'));

        foreach (['Formal' => $studioAbs, 'Range' => $rangeAbs] as $label => $abs) {
            if (! File::exists($abs)) {
                $this->error("{$label} photo not found: {$abs}");

                return self::FAILURE;
            }
        }

        $team = NationalTeam::firstOrCreate(
            ['year' => self::YEAR, 'championship_name' => self::CHAMPIONSHIP],
            ['discipline' => self::DISCIPLINE],
        );
        $this->info("NationalTeam id={$team->id} ({$team->year} {$team->championship_name}) ready.");

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
            $this->info('Set album cover to formal portrait.');
        }
        if ($team->cover_media_item_id !== $studio->id) {
            $team->update(['cover_media_item_id' => $studio->id]);
            $this->info('Set 2023 team cover to formal portrait.');
        }

        $this->upsertItem(
            disk: $disk,
            album: $album,
            sourceAbs: $rangeAbs,
            caption: self::RANGE_CAPTION,
            alt: self::RANGE_ALT,
            credit: null,
            sortOrder: 20,
            matchCaptions: [self::RANGE_CAPTION],
            preferredId: null,
        );

        $this->newLine();
        $this->comment('Done. /sa-team/2023 uses the formal portrait as its hero cover. Roster names still TBD.');

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
