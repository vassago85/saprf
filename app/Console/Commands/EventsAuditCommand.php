<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Support\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 6 — read-only audit of the `matches` table. Produces four
 * sections, printed as tables and optionally dumped to a single CSV
 * under storage/app/reports/, plus a short naming-convention
 * recommendation at the end. The command never writes to the database.
 *
 * Sections:
 *   A. Inconsistent names — matches whose name canonicalises to the
 *      same string under App\Support\NameNormalizer, i.e. duplicates
 *      hiding under "PR22 Provincial" vs "PR22   provincial".
 *   B. Test matches — names that look like fixtures left behind
 *      (contain "test", "demo", "sandbox", "ignore", "delete").
 *   C. TBC venues — venue_name/venue_location/city combinations that
 *      are blank, "TBC", "TBA", or similar placeholders on a match
 *      that is already published.
 *   D. Null required fields — published matches that are missing one
 *      of a short list of fields the UI reasonably expects
 *      (season, series, series_level, province_id, match_date).
 */
class EventsAuditCommand extends Command
{
    protected $signature = 'events:audit
        {--csv : Also write a CSV report to storage/app/reports/events-audit-{timestamp}.csv}';

    protected $description = 'Read-only audit of matches: inconsistent names, test matches, TBC venues, missing fields.';

    /** Placeholder strings an admin typed instead of real data. */
    private const PLACEHOLDER_VENUES = ['tbc', 'tba', 'tbd', 't.b.c.', 't.b.a.', 'to be confirmed', 'to be announced', 'unknown', 'n/a', 'na', '-'];

    /** Needles that strongly suggest a fixture/test row leaked in. */
    private const TEST_NEEDLES = ['test', 'demo', 'sandbox', 'ignore', 'delete me', 'do not use', 'placeholder', 'fixture'];

    /**
     * Fields a published match should always have populated. These are
     * the ones the public events list and detail pages rely on; a
     * missing value here is always a bug worth looking at.
     *
     * @var array<int, string>
     */
    private const REQUIRED_FIELDS = ['season', 'series', 'series_level', 'province_id', 'match_date'];

    public function handle(): int
    {
        $this->info('=== events:audit (read-only) ===');
        $this->newLine();

        $matches = MatchEvent::query()
            ->orderByDesc('match_date')
            ->orderBy('id')
            ->get([
                'id', 'name', 'slug', 'match_type', 'series', 'series_level', 'season',
                'province_id', 'venue_name', 'venue_location', 'city', 'match_date',
                'status', 'published',
            ]);

        $inconsistent = $this->findInconsistentNames($matches);
        $testMatches = $this->findTestMatches($matches);
        $tbcVenues = $this->findTbcVenues($matches);
        $nullFields = $this->findMissingFields($matches);

        $this->renderInconsistent($inconsistent);
        $this->newLine();
        $this->renderTestMatches($testMatches);
        $this->newLine();
        $this->renderTbcVenues($tbcVenues);
        $this->newLine();
        $this->renderNullFields($nullFields);
        $this->newLine();

        $this->renderRecommendation();

        if ($this->option('csv')) {
            $path = $this->writeCsv($inconsistent, $testMatches, $tbcVenues, $nullFields);
            $this->newLine();
            $this->info("CSV written: {$path}");
        }

        $total = $inconsistent->sum(fn ($group) => $group->count())
            + $testMatches->count()
            + $tbcVenues->count()
            + $nullFields->count();

        $this->newLine();
        $this->line("Scanned {$matches->count()} matches, flagged {$total} issue(s).");

        // Read-only. Always exit 0 regardless of findings so this is safe
        // in a cron. An admin reads the output / CSV to act on it.
        return self::SUCCESS;
    }

    /**
     * Group matches by canonical name and return every group with more
     * than one match. The canonical form collapses case and whitespace.
     *
     * @return Collection<string, Collection<int, MatchEvent>>
     */
    private function findInconsistentNames(Collection $matches): Collection
    {
        return $matches
            ->groupBy(fn (MatchEvent $m) => NameNormalizer::canonical((string) $m->name))
            ->filter(fn (Collection $group) => $group->count() > 1);
    }

    /**
     * @return Collection<int, MatchEvent>
     */
    private function findTestMatches(Collection $matches): Collection
    {
        return $matches->filter(function (MatchEvent $m): bool {
            $haystack = Str::lower((string) $m->name.' '.$m->slug);

            foreach (self::TEST_NEEDLES as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Published matches whose venue_name/venue_location/city are all
     * blank or placeholder-like. Draft matches are skipped — they are
     * expected to have gaps while the MD fills the form.
     *
     * @return Collection<int, MatchEvent>
     */
    private function findTbcVenues(Collection $matches): Collection
    {
        return $matches->filter(function (MatchEvent $m): bool {
            if (! $m->published) {
                return false;
            }

            $candidates = [$m->venue_name, $m->venue_location, $m->city];

            foreach ($candidates as $candidate) {
                if (filled($candidate) && ! in_array(Str::lower(trim((string) $candidate)), self::PLACEHOLDER_VENUES, true)) {
                    // At least one field is a real value — not a TBC row.
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @return Collection<int, array{match: MatchEvent, missing: array<int, string>}>
     */
    private function findMissingFields(Collection $matches): Collection
    {
        return $matches
            ->filter(fn (MatchEvent $m) => (bool) $m->published)
            ->map(function (MatchEvent $m) {
                $missing = [];
                foreach (self::REQUIRED_FIELDS as $field) {
                    if (blank($m->{$field})) {
                        $missing[] = $field;
                    }
                }

                return $missing === [] ? null : ['match' => $m, 'missing' => $missing];
            })
            ->filter()
            ->values();
    }

    private function renderInconsistent(Collection $groups): void
    {
        $this->line('<fg=yellow>A. Inconsistent match names (same canonical form, different stored name)</>');

        if ($groups->isEmpty()) {
            $this->info('  — none found.');

            return;
        }

        $rows = [];
        foreach ($groups as $canonical => $group) {
            foreach ($group as $match) {
                $rows[] = [$canonical, $match->id, $match->name, $match->match_date?->format('Y-m-d') ?? '—'];
            }
        }

        $this->table(['Canonical', 'ID', 'Stored name', 'Match date'], $rows);
    }

    private function renderTestMatches(Collection $matches): void
    {
        $this->line('<fg=yellow>B. Likely test / fixture matches still in the DB</>');

        if ($matches->isEmpty()) {
            $this->info('  — none found.');

            return;
        }

        $rows = $matches->map(fn (MatchEvent $m) => [
            $m->id,
            $m->name,
            $m->slug ?? '—',
            $m->status ?? '—',
            $m->published ? 'yes' : 'no',
        ])->all();

        $this->table(['ID', 'Name', 'Slug', 'Status', 'Published'], $rows);
    }

    private function renderTbcVenues(Collection $matches): void
    {
        $this->line('<fg=yellow>C. Published matches with TBC/blank venue data</>');

        if ($matches->isEmpty()) {
            $this->info('  — none found.');

            return;
        }

        $rows = $matches->map(fn (MatchEvent $m) => [
            $m->id,
            $m->name,
            $m->venue_name ?? '—',
            $m->venue_location ?? '—',
            $m->city ?? '—',
            $m->match_date?->format('Y-m-d') ?? '—',
        ])->all();

        $this->table(['ID', 'Name', 'Venue name', 'Venue location', 'City', 'Match date'], $rows);
    }

    private function renderNullFields(Collection $findings): void
    {
        $this->line('<fg=yellow>D. Published matches missing required fields</>');

        if ($findings->isEmpty()) {
            $this->info('  — none found.');

            return;
        }

        $rows = $findings->map(fn (array $f) => [
            $f['match']->id,
            $f['match']->name,
            implode(', ', $f['missing']),
            $f['match']->match_date?->format('Y-m-d') ?? '—',
        ])->all();

        $this->table(['ID', 'Name', 'Missing fields', 'Match date'], $rows);
    }

    private function renderRecommendation(): void
    {
        $this->line('<fg=cyan>Naming-convention recommendation</>');
        $this->line('  Match names should follow:  <options=bold>[Season] [Series/Level] [Province or Host] [#] — [Discipline]</>');
        $this->line('  Examples:');
        $this->line('    • "2026 GP Provincial #3 — PR22"');
        $this->line('    • "2027 National Championship Day 1 — PRS"');
        $this->line('    • "2026 BLW League Round 4 — PRS"');
        $this->line('  Prefer en-dashes (—) between the host prefix and the discipline suffix,');
        $this->line('  keep whitespace single-spaced, and reserve "Test" / "Demo" / "Sandbox"');
        $this->line('  for seeders only — never ship them on `published = true` rows.');
    }

    /**
     * Writes a single flat CSV with a `section` column so Excel/Numbers
     * can filter by section. Returns the absolute path.
     */
    private function writeCsv(
        Collection $inconsistent,
        Collection $testMatches,
        Collection $tbcVenues,
        Collection $nullFields,
    ): string {
        $disk = Storage::disk('local');
        $disk->makeDirectory('reports');

        $filename = 'reports/events-audit-'.now()->format('Ymd-His').'.csv';

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['section', 'id', 'name', 'extra', 'match_date', 'status', 'published']);

        foreach ($inconsistent as $canonical => $group) {
            foreach ($group as $m) {
                fputcsv($handle, [
                    'inconsistent_name',
                    $m->id,
                    $m->name,
                    'canonical='.$canonical,
                    $m->match_date?->format('Y-m-d') ?? '',
                    $m->status ?? '',
                    $m->published ? '1' : '0',
                ]);
            }
        }

        foreach ($testMatches as $m) {
            fputcsv($handle, [
                'test_match',
                $m->id,
                $m->name,
                'slug='.($m->slug ?? ''),
                $m->match_date?->format('Y-m-d') ?? '',
                $m->status ?? '',
                $m->published ? '1' : '0',
            ]);
        }

        foreach ($tbcVenues as $m) {
            fputcsv($handle, [
                'tbc_venue',
                $m->id,
                $m->name,
                'venue_name='.($m->venue_name ?? '').'; venue_location='.($m->venue_location ?? '').'; city='.($m->city ?? ''),
                $m->match_date?->format('Y-m-d') ?? '',
                $m->status ?? '',
                $m->published ? '1' : '0',
            ]);
        }

        foreach ($nullFields as $f) {
            $m = $f['match'];
            fputcsv($handle, [
                'missing_fields',
                $m->id,
                $m->name,
                'missing='.implode(',', $f['missing']),
                $m->match_date?->format('Y-m-d') ?? '',
                $m->status ?? '',
                $m->published ? '1' : '0',
            ]);
        }

        rewind($handle);
        $disk->put($filename, stream_get_contents($handle));
        fclose($handle);

        return (string) $disk->path($filename);
    }
}
