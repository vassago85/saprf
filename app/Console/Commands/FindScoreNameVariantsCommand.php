<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Report-only sweep for name variants that likely represent the same
 * shooter under different spellings. Two sections:
 *
 *   A. Users whose canonical name matches more than one row in `users`
 *      — e.g. "Terblanche De Jager" and "Terblanche de Jager".
 *
 *   B. Scores where user_id IS NULL but the shooter_name canonicalises
 *      to an existing user — the import heuristics missed a straight
 *      case/whitespace match. Each row is a candidate for a manual
 *      "link this score to that user" fix.
 *
 * Writes a CSV under storage/app/reports/ so an admin can walk the list
 * off-line. Never writes to the database.
 */
class FindScoreNameVariantsCommand extends Command
{
    protected $signature = 'scores:find-name-variants
        {--csv : Also write a CSV report to storage/app/reports/}';

    protected $description = 'Report shooter-name variants that likely resolve to the same user. Read-only.';

    public function handle(): int
    {
        $this->info('=== scores:find-name-variants ===');
        $this->newLine();

        $usersReport = $this->duplicateCanonicalUsers();
        $scoresReport = $this->orphanScoresMatchingUsers();

        $this->renderUsers($usersReport);
        $this->newLine();
        $this->renderOrphans($scoresReport);

        if ($this->option('csv')) {
            $this->writeCsv($usersReport, $scoresReport);
        }

        return $usersReport === [] && $scoresReport === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Group users by canonical name; return every group that is bigger
     * than one.
     *
     * @return array<string, array<int, array{id:int,name:string}>>
     */
    private function duplicateCanonicalUsers(): array
    {
        $byCanon = [];

        DB::table('users')
            ->select(['id', 'name'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(500, function ($users) use (&$byCanon): void {
                foreach ($users as $user) {
                    $canon = NameNormalizer::canonical($user->name ?? '');
                    if ($canon === '') {
                        continue;
                    }
                    $byCanon[$canon][] = ['id' => (int) $user->id, 'name' => (string) $user->name];
                }
            });

        return array_filter($byCanon, fn (array $rows) => count($rows) > 1);
    }

    /**
     * Scores with no user_id whose canonical shooter_name matches
     * exactly one existing user.
     *
     * @return array<int, array{score_id:int,shooter_name:string,match_id:int,candidate_user_id:int,candidate_user_name:string}>
     */
    private function orphanScoresMatchingUsers(): array
    {
        $usersByCanon = [];
        DB::table('users')
            ->select(['id', 'name'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(500, function ($users) use (&$usersByCanon): void {
                foreach ($users as $user) {
                    $canon = NameNormalizer::canonical($user->name ?? '');
                    if ($canon === '') {
                        continue;
                    }
                    $usersByCanon[$canon][] = ['id' => (int) $user->id, 'name' => (string) $user->name];
                }
            });

        $hits = [];

        DB::table('scores')
            ->select(['id', 'shooter_name', 'match_id'])
            ->whereNull('user_id')
            ->orderBy('id')
            ->chunk(500, function ($scores) use ($usersByCanon, &$hits): void {
                foreach ($scores as $score) {
                    $canon = NameNormalizer::canonical($score->shooter_name ?? '');
                    if ($canon === '' || ! isset($usersByCanon[$canon])) {
                        continue;
                    }

                    // Only flag when the match is unambiguous — multiple users
                    // sharing a canonical name is covered by the first section
                    // and should not auto-link to a specific id.
                    if (count($usersByCanon[$canon]) !== 1) {
                        continue;
                    }

                    $candidate = $usersByCanon[$canon][0];
                    $hits[] = [
                        'score_id' => (int) $score->id,
                        'shooter_name' => (string) $score->shooter_name,
                        'match_id' => (int) $score->match_id,
                        'candidate_user_id' => $candidate['id'],
                        'candidate_user_name' => $candidate['name'],
                    ];
                }
            });

        return $hits;
    }

    /**
     * @param  array<string, array<int, array{id:int,name:string}>>  $byCanon
     */
    private function renderUsers(array $byCanon): void
    {
        if ($byCanon === []) {
            $this->info('No duplicate canonical users found.');

            return;
        }

        $this->warn(count($byCanon).' canonical name(s) map to more than one user:');
        foreach ($byCanon as $canon => $rows) {
            $this->line("  {$canon}");
            foreach ($rows as $row) {
                $this->line("    #{$row['id']} — {$row['name']}");
            }
        }
    }

    /**
     * @param  array<int, array{score_id:int,shooter_name:string,match_id:int,candidate_user_id:int,candidate_user_name:string}>  $hits
     */
    private function renderOrphans(array $hits): void
    {
        if ($hits === []) {
            $this->info('No orphan scores look like they belong to an existing user.');

            return;
        }

        $this->warn(count($hits).' orphan score(s) with an obvious matching user:');
        foreach ($hits as $hit) {
            $this->line(sprintf(
                '  score #%d · match #%d · "%s" -> user #%d (%s)',
                $hit['score_id'],
                $hit['match_id'],
                $hit['shooter_name'],
                $hit['candidate_user_id'],
                $hit['candidate_user_name'],
            ));
        }
    }

    /**
     * @param  array<string, array<int, array{id:int,name:string}>>  $users
     * @param  array<int, array<string, mixed>>  $orphans
     */
    private function writeCsv(array $users, array $orphans): void
    {
        $stamp = now()->format('Ymd-His');
        Storage::disk('local')->makeDirectory('reports');

        if ($users !== []) {
            $buf = fopen('php://temp', 'r+');
            if ($buf === false) {
                $this->error('Could not open temp stream for CSV output.');

                return;
            }
            fputcsv($buf, ['canonical_name', 'user_id', 'raw_name']);
            foreach ($users as $canon => $rows) {
                foreach ($rows as $row) {
                    fputcsv($buf, [$canon, $row['id'], $row['name']]);
                }
            }
            rewind($buf);
            Storage::disk('local')->put(
                "reports/name-variants-users-{$stamp}.csv",
                (string) stream_get_contents($buf),
            );
            fclose($buf);
            $this->line("CSV written: reports/name-variants-users-{$stamp}.csv");
        }

        if ($orphans !== []) {
            $buf = fopen('php://temp', 'r+');
            if ($buf === false) {
                return;
            }
            fputcsv($buf, array_keys($orphans[0]));
            foreach ($orphans as $row) {
                fputcsv($buf, $row);
            }
            rewind($buf);
            Storage::disk('local')->put(
                "reports/name-variants-orphans-{$stamp}.csv",
                (string) stream_get_contents($buf),
            );
            fclose($buf);
            $this->line("CSV written: reports/name-variants-orphans-{$stamp}.csv");
        }
    }
}
