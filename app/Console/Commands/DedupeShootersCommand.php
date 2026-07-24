<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Merge duplicate shooter accounts that share the same name.
 *
 * The scraped imports can produce more than one account for a single real
 * person (e.g. a name-variant that dodged the importer's dedupe, or a member
 * who was also imported as a stub). When that happens the person shows up in
 * several provincial standings tables at once — one row per account — because
 * each account carries its own province_id.
 *
 * This command collapses each duplicate group into a single "keeper" account,
 * reassigning the losers' scores and registrations to the keeper, so every
 * real shooter ends up with exactly one account (and therefore one province).
 *
 * Safe by default: runs as a dry-run and only reports. Pass --commit to apply.
 */
class DedupeShootersCommand extends Command
{
    protected $signature = 'shooters:dedupe
        {--name= : Only process accounts matching this exact name (case-insensitive)}
        {--commit : Actually merge (default is a dry-run that only reports)}
        {--reevaluate : Run scores:reevaluate after a successful commit}';

    protected $description = 'Merge duplicate shooter accounts (same name) into one so each person has a single province';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $nameFilter = $this->option('name');

        $groups = $this->findDuplicateGroups($nameFilter);

        if ($groups->isEmpty()) {
            $this->info('No duplicate shooter accounts found.');

            return self::SUCCESS;
        }

        $this->info($groups->count().' duplicate name group(s) found'.($commit ? '' : ' (dry-run — nothing will change)').':');
        $this->newLine();

        $totalMerged = 0;

        foreach ($groups as $canon => $users) {
            $keeper = $this->pickKeeper($users);
            $losers = $users->reject(fn (User $u) => $u->id === $keeper->id)->values();

            $this->line("<fg=cyan>{$keeper->name}</> — keeping #{$keeper->id} ({$keeper->email}, province ".($keeper->province_id ?? '—').')');
            foreach ($losers as $loser) {
                $this->line("    merge #{$loser->id} ({$loser->email}, province ".($loser->province_id ?? '—').", {$loser->scores()->count()} scores)");
            }

            if ($commit) {
                DB::transaction(function () use ($keeper, $losers) {
                    foreach ($losers as $loser) {
                        $this->mergeInto($keeper, $loser);
                    }
                });
                $totalMerged += $losers->count();
            }
        }

        $this->newLine();

        if (! $commit) {
            $this->warn('Dry-run only. Re-run with --commit to apply, e.g.:');
            $this->line('  php artisan shooters:dedupe --commit --reevaluate');

            return self::SUCCESS;
        }

        $this->info("Merged {$totalMerged} duplicate account(s).");

        if ($this->option('reevaluate')) {
            $this->info('Recalculating scores and standings...');
            $this->call('scores:reevaluate');
        } else {
            $this->warn('Remember to run "php artisan scores:reevaluate" to rebuild standings.');
        }

        return self::SUCCESS;
    }

    /**
     * Group non-owner users by a normalised name, returning only the groups
     * that contain more than one account.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, User>>
     */
    private function findDuplicateGroups(?string $nameFilter)
    {
        $query = User::query()->withCount('scores');

        if ($nameFilter) {
            $query->whereRaw('LOWER(name) = ?', [strtolower(trim($nameFilter))]);
        }

        return $query->get()
            ->reject(fn (User $u) => $u->hasRole('owner'))
            ->groupBy(fn (User $u) => $this->canon($u->name))
            ->filter(fn ($group) => $group->count() > 1);
    }

    private function canon(string $name): string
    {
        return Str::of($name)->lower()->squish()->value();
    }

    /**
     * Choose which account survives a merge. We keep the account the member
     * actually logs in with — a real email — because scores are moved onto the
     * keeper regardless of where they started. Priority (best first):
     *   1. A real (non-imported) email beats an @import.saprf.local stub.
     *   2. A verified email beats an unverified one.
     *   3. More scores beats fewer.
     *   4. Fall back to the oldest account (lowest id).
     *
     * Implemented with a single composite sort key so the ordering is
     * deterministic (an array of closures passed to sortBy() does not compare
     * the way you'd expect).
     *
     * @param \Illuminate\Support\Collection<int, User> $users
     */
    private function pickKeeper($users): User
    {
        return $users->sortBy(function (User $u): string {
            $isImport = Str::endsWith((string) $u->email, '@import.saprf.local') ? 1 : 0;
            $unverified = $u->email_verified_at ? 0 : 1;
            $scores = (int) ($u->scores_count ?? $u->scores()->count());
            // Descending on score count: fewer scores => larger number => sorts later.
            $scoreKey = str_pad((string) max(0, 9999999 - $scores), 7, '0', STR_PAD_LEFT);
            $idKey = str_pad((string) $u->id, 12, '0', STR_PAD_LEFT);

            return "{$isImport}{$unverified}{$scoreKey}{$idKey}";
        })->first();
    }

    /**
     * Fold a loser account's data into the keeper, then delete the loser.
     */
    private function mergeInto(User $keeper, User $loser): void
    {
        // Reassign scores; if the keeper already has a score for the same match,
        // keep the higher raw_score and drop the duplicate so best-of season
        // maths isn't inflated by two entries for one match.
        $keeperMatchIds = $keeper->scores()->pluck('raw_score', 'match_id');
        foreach ($loser->scores()->get() as $score) {
            if ($keeperMatchIds->has($score->match_id)) {
                $existingRaw = (float) $keeperMatchIds->get($score->match_id);
                if ((float) $score->raw_score > $existingRaw) {
                    $keeper->scores()->where('match_id', $score->match_id)->delete();
                    $score->update(['user_id' => $keeper->id]);
                    $keeperMatchIds->put($score->match_id, $score->raw_score);
                } else {
                    $score->delete();
                }

                continue;
            }

            $score->update(['user_id' => $keeper->id]);
            $keeperMatchIds->put($score->match_id, $score->raw_score);
        }

        // Reassign registrations, skipping any the keeper already has.
        $keeperRegMatchIds = $keeper->matchRegistrations()->pluck('match_id')->all();
        foreach ($loser->matchRegistrations()->get() as $registration) {
            if (in_array($registration->match_id, $keeperRegMatchIds, true)) {
                $registration->delete();

                continue;
            }
            $registration->update(['user_id' => $keeper->id]);
            $keeperRegMatchIds[] = $registration->match_id;
        }

        // Reassign every remaining reference that would otherwise block the
        // delete (all of these FKs are ON DELETE RESTRICT). Moving them to the
        // keeper is correct — it's the same person.
        $this->reassign('shooter_logs', 'user_id', $loser->id, $keeper->id);
        $this->reassign('payments', 'user_id', $loser->id, $keeper->id);
        $this->reassign('matches', 'created_by', $loser->id, $keeper->id);
        $this->reassign('qualification_rules', 'created_by', $loser->id, $keeper->id);
        $this->reassign('score_imports', 'uploaded_by', $loser->id, $keeper->id);

        // Standings are fully rebuilt by scores:reevaluate, so the loser's rows
        // can simply be dropped (they'd otherwise block the delete via RESTRICT).
        \App\Models\Standing::where('user_id', $loser->id)->delete();

        // Backfill province/division onto the keeper only if it's missing.
        $backfill = [];
        if (! $keeper->province_id && $loser->province_id) {
            $backfill['province_id'] = $loser->province_id;
        }
        if (! $keeper->division_id && $loser->division_id) {
            $backfill['division_id'] = $loser->division_id;
        }
        if ($backfill !== []) {
            $keeper->update($backfill);
        }

        // Discard the loser's personal + membership records, then remove it.
        $loser->rifleConfigurations()->each(function ($rifle) {
            $rifle->ammoLoads()->delete();
            $rifle->forceDelete();
        });
        $loser->ammoLoads()->delete();
        $loser->committeePositions()->delete();
        $loser->membership()?->delete();
        $loser->forceDelete();
    }

    /**
     * Point a foreign-key column at the keeper, guarding against missing
     * tables/columns so the command stays resilient across schema changes.
     */
    private function reassign(string $table, string $column, int $fromId, int $toId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $fromId)->update([$column => $toId]);
    }
}
