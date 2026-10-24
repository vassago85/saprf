<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MediaAlbum;
use App\Models\MediaItem;
use App\Support\MatchName;
use Illuminate\Console\Command;

/**
 * One-shot cleanup command for existing database rows that pre-date the
 * MatchName normalizer on the write path. Dry-run by default so an operator
 * can review every proposed change before anything is persisted.
 *
 *   php artisan matches:normalize-names           # dry-run
 *   php artisan matches:normalize-names --write   # persist
 *
 * Also rewrites the hand-typed "Centerfire" spelling in MediaAlbum.title,
 * MediaItem.home_headline, MediaItem.caption and MediaItem.alt — those strings
 * live in the DB rather than Blade and would otherwise still read "Centerfire"
 * on the public media surfaces.
 */
class NormalizeMatchNamesCommand extends Command
{
    protected $signature = 'matches:normalize-names {--write : Persist changes. Omit to see a dry-run diff.}';

    protected $description = 'Rewrite existing match names (and the Worlds poster copy) to the canonical "Centrefire" spelling.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $this->line($write ? '<comment>Writing changes.</comment>' : '<info>Dry-run.</info> Pass --write to persist.');

        $matchChanges = 0;
        MatchEvent::query()->orderBy('id')->each(function (MatchEvent $match) use ($write, &$matchChanges) {
            $normalized = MatchName::normalize($match->name);
            if ($normalized === (string) $match->name) {
                return;
            }
            $matchChanges++;
            $this->line(sprintf('  match #%d  "%s"  →  "%s"', $match->id, $match->name, $normalized));
            if ($write) {
                $match->forceFill(['name' => $normalized])->saveQuietly();
            }
        });
        $this->info("Matches: {$matchChanges} name(s) would change.");

        // Centerfire → Centrefire in media copy. Narrowly scoped so we don't
        // touch unrelated album titles / captions.
        $mediaChanges = 0;
        $rewrite = fn (?string $value) => $value === null
            ? null
            : (string) preg_replace('/\bCenterfire\b/', 'Centrefire', $value);

        MediaAlbum::query()->where('title', 'like', '%Centerfire%')->each(function (MediaAlbum $album) use ($write, $rewrite, &$mediaChanges) {
            $newTitle = $rewrite($album->title);
            if ($newTitle === $album->title) {
                return;
            }
            $mediaChanges++;
            $this->line(sprintf('  album #%d  "%s"  →  "%s"', $album->id, $album->title, $newTitle));
            if ($write) {
                $album->forceFill(['title' => $newTitle])->saveQuietly();
            }
        });

        MediaItem::query()
            ->where(function ($q) {
                $q->where('home_headline', 'like', '%Centerfire%')
                    ->orWhere('caption', 'like', '%Centerfire%')
                    ->orWhere('alt', 'like', '%Centerfire%');
            })
            ->each(function (MediaItem $item) use ($write, $rewrite, &$mediaChanges) {
                $patch = [];
                foreach (['home_headline', 'caption', 'alt'] as $field) {
                    $new = $rewrite($item->getAttribute($field));
                    if ($new !== null && $new !== $item->getAttribute($field)) {
                        $patch[$field] = $new;
                        $this->line(sprintf('  item #%d.%s  "%s"  →  "%s"', $item->id, $field, $item->getAttribute($field), $new));
                        $mediaChanges++;
                    }
                }
                if ($write && $patch !== []) {
                    $item->forceFill($patch)->saveQuietly();
                }
            });

        $this->info("Media: {$mediaChanges} field(s) would change.");

        if (! $write) {
            $this->line('<info>Re-run with --write to persist.</info>');
        }

        return self::SUCCESS;
    }
}
