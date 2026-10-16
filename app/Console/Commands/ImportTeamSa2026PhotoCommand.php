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
 * Import the two 2026 Team SA photos into the Media album:
 *
 *   - Studio group portrait → NationalTeam cover (/sa-team hero)
 *   - Texas stage photo     → homepage feature band
 *
 * Idempotent: safe to re-run after a deploy. Existing items are matched
 * by caption (including legacy captions) and refreshed from the source
 * files under /public/images.
 *
 * After the deploy, run:
 *   docker exec saprf-app php artisan saprf:import-team-sa-2026-photo --sync
 */
class ImportTeamSa2026PhotoCommand extends Command
{
    protected $signature = 'saprf:import-team-sa-2026-photo
        {--studio=images/team-sa-iprf-worlds-2026.jpg : Studio portrait under /public (team page cover)}
        {--texas=images/team-sa-iprf-worlds-2026-texas.jpg : Texas stage photo under /public (homepage feature)}
        {--sync : Run ProcessMediaItem inline instead of dispatching to the queue}';

    protected $description = 'Import the 2026 Team SA studio + Texas photos: studio for /sa-team, Texas for the homepage.';

    private const YEAR = 2026;

    private const CHAMPIONSHIP = '2026 IPRF World Championship (Centrefire)';

    private const ALBUM_TITLE = '2026 IPRF World Championship';

    private const ALBUM_SLUG = '2026-iprf-world-championship';

    private const STUDIO_CAPTION = 'Team South Africa · 2026 IPRF World Championship';

    private const STUDIO_ALT = 'Formal group portrait of the 2026 South African IPRF World Championship team in green blazers';

    private const TEXAS_CAPTION = 'Team South Africa · 2026 IPRF World Championship, Lipan, Texas';

    private const TEXAS_ALT = 'The South African team on stage at the 2026 IPRF World Championship in Lipan, Texas';

    /** @var list<string> */
    private const STUDIO_LEGACY_CAPTIONS = [
        // Previously the studio import briefly reused the Texas caption.
    ];

    public function handle(): int
    {
        $studioAbs = public_path((string) $this->option('studio'));
        $texasAbs = public_path((string) $this->option('texas'));

        foreach (['Studio' => $studioAbs, 'Texas' => $texasAbs] as $label => $abs) {
            if (! File::exists($abs)) {
                $this->error("{$label} photo not found: {$abs}");

                return self::FAILURE;
            }
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

        $disk = Storage::disk(config('media.disk', 'public'));

        // 3a. Studio portrait → team + album cover (NOT homepage feature).
        $studio = $this->upsertItem(
            disk: $disk,
            album: $album,
            sourceAbs: $studioAbs,
            caption: self::STUDIO_CAPTION,
            alt: self::STUDIO_ALT,
            credit: null,
            sortOrder: 10,
            matchCaptions: array_merge([self::STUDIO_CAPTION], self::STUDIO_LEGACY_CAPTIONS),
            // Prefer the current team cover when it isn't the Texas photo —
            // handles the earlier single-photo import that overwrote Texas
            // bytes into the cover row and later retitled it to the studio caption.
            preferredId: $this->preferredStudioId($team, $album),
        );

        if ($album->cover_media_item_id !== $studio->id) {
            $album->update(['cover_media_item_id' => $studio->id]);
            $this->info('Set album cover to studio portrait.');
        }
        if ($team->cover_media_item_id !== $studio->id) {
            $team->update(['cover_media_item_id' => $studio->id]);
            $this->info('Set 2026 team cover to studio portrait.');
        }

        // 3b. Texas stage photo → homepage feature.
        $texas = $this->upsertItem(
            disk: $disk,
            album: $album,
            sourceAbs: $texasAbs,
            caption: self::TEXAS_CAPTION,
            alt: self::TEXAS_ALT,
            credit: 'On Target Africa',
            sortOrder: 20,
            matchCaptions: [self::TEXAS_CAPTION],
            preferredId: null,
        );

        $texas->fill([
            'home_label' => 'Team South Africa',
            'home_headline' => 'Flying the flag at the 2026 IPRF World Championship',
            'home_summary' => 'The South African team that represented the country in Lipan, Texas.',
            'home_link_type' => 'sa_team',
        ])->save();

        if (! $texas->is_home_feature) {
            $texas->featureOnHomepage();
            $this->info('Featured Texas photo on homepage.');
        } else {
            $this->info('Homepage feature already points at Texas photo.');
        }

        // Ensure the studio row is not still flagged as the home feature.
        if ($studio->fresh()->is_home_feature && $studio->id !== $texas->id) {
            $studio->update(['is_home_feature' => false]);
            $this->info('Cleared homepage feature flag from studio portrait.');
        }

        $this->newLine();
        $this->comment('Done. Homepage uses the Texas stage photo; /sa-team/2026 uses the studio portrait.');

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

        if ($item === null) {
            $filename = Str::uuid()->toString().'.jpg';
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

    /**
     * When only one media item exists (the earlier single-photo import),
     * treat it as the studio cover unless its caption is already the
     * Texas one — in which case leave it for the Texas upsert.
     */
    private function preferredStudioId(NationalTeam $team, MediaAlbum $album): ?int
    {
        if ($team->cover_media_item_id) {
            $cover = MediaItem::find($team->cover_media_item_id);
            if ($cover && $cover->caption !== self::TEXAS_CAPTION) {
                return $cover->id;
            }
        }

        if ($album->cover_media_item_id) {
            $cover = MediaItem::find($album->cover_media_item_id);
            if ($cover && $cover->caption !== self::TEXAS_CAPTION) {
                return $cover->id;
            }
        }

        return null;
    }
}
