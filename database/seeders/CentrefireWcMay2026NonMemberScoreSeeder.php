<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use App\Services\StandingsSnapshotRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Add the four shooters from the legacy Scores.xlsx sheet who were missing
 * on Centrefire WC 2-Day National (16 May 2026, old-site event #219).
 *
 * They are stored as non-members: visible on the match, included in the
 * overall high score that match percentages are divided by, and excluded
 * from the season log. Existing member raw scores are left unchanged.
 * Francois Marais at 116 becomes the match high, so every match percentage
 * is recalculated against 116.
 *
 * After the rows are written, every completed match is re-ranked and every
 * series/season standings table (national and each province) is rebuilt.
 *
 * Dirk Sauber, Derick Louw and Marius j have no division on that sheet.
 * They affect the overall result only. Francois Marais is Open.
 *
 * Not called from DatabaseSeeder. Run once on the server:
 *   php artisan db:seed --class=CentrefireWcMay2026NonMemberScoreSeeder --force
 */
class CentrefireWcMay2026NonMemberScoreSeeder extends Seeder
{
    /**
     * @var list<array{name: string, raw: float, division: ?string, country?: string}>
     */
    private const ROWS = [
        ['name' => 'Francois Marais', 'raw' => 116, 'division' => 'open', 'country' => 'NA'],
        ['name' => 'Dirk Sauber', 'raw' => 106, 'division' => null],
        ['name' => 'Derick Louw', 'raw' => 65, 'division' => null],
        ['name' => 'Marius j', 'raw' => 12, 'division' => null],
    ];

    public function run(): void
    {
        $match = $this->match();
        $this->assertMemberBaseline($match);

        $validation = app(ScoreValidationService::class);

        DB::transaction(function () use ($match, $validation): void {
            foreach (self::ROWS as $row) {
                $this->upsertNonMemberScore($match, $row, $validation);
            }
        });

        $standings = app(StandingsCalculationService::class);
        $this->rerankCompletedMatches($standings);
        $this->rebuildAllStandings($standings);
        app(StandingsSnapshotRecorder::class)->recordForMatch($match->fresh());

        $top = (float) Score::query()->where('match_id', $match->id)->max('raw_score');
        $this->command?->info("Top raw after rebuild: {$top}");

        Score::query()
            ->where('match_id', $match->id)
            ->orderByDesc('raw_score')
            ->limit(4)
            ->get()
            ->each(function (Score $score) use ($top): void {
                $pct = $top > 0 ? round(((float) $score->raw_score) / $top * 100, 2) : 0;
                $this->command?->line("{$score->overall_rank}. {$score->shooter_name} {$score->raw_score} {$pct}% [{$score->status}]");
            });
    }

    private function rerankCompletedMatches(StandingsCalculationService $standings): void
    {
        $matches = MatchEvent::query()
            ->where('status', 'completed')
            ->orderBy('match_date')
            ->get();

        $this->command?->info('Re-ranking '.$matches->count().' completed match(es)...');

        foreach ($matches as $rankable) {
            $standings->calculateMatchRankings($rankable);
        }
    }

    private function rebuildAllStandings(StandingsCalculationService $standings): void
    {
        $combos = MatchEvent::query()
            ->whereNotNull('series')
            ->whereNotNull('season')
            ->select('series', 'season')
            ->distinct()
            ->get();

        $provinceIds = Province::query()->pluck('id');

        $this->command?->info('Recalculating standings for '.$combos->count().' series/season table(s)...');

        foreach ($combos as $combo) {
            $standings->recalculateSeasonStandings($combo->series, $combo->season, null);
            foreach ($provinceIds as $provinceId) {
                $standings->recalculateSeasonStandings($combo->series, $combo->season, (int) $provinceId);
            }
            $this->command?->line("Recalculated {$combo->series} {$combo->season} (national + {$provinceIds->count()} province table(s)).");
        }
    }

    private function match(): MatchEvent
    {
        $matches = MatchEvent::query()
            ->where('match_type', 'PRS')
            ->where('series_level', 'national')
            ->whereDate('match_date', '2026-05-16')
            ->where('name', 'like', 'Centrefire WC 2-Day%')
            ->get();

        if ($matches->count() !== 1) {
            throw new RuntimeException(
                'Expected exactly one PRS national Centrefire WC 2-Day on 2026-05-16, found '.$matches->count().'.'
            );
        }

        return $matches->first();
    }

    private function assertMemberBaseline(MatchEvent $match): void
    {
        $dirk = Score::query()
            ->where('match_id', $match->id)
            ->whereRaw('LOWER(shooter_name) = ?', ['dirk van staden'])
            ->first();

        if (! $dirk || (float) $dirk->raw_score !== 112.0) {
            throw new RuntimeException('Dirk Van Staden is not on 112. Aborting so no existing score is changed.');
        }
    }

    /**
     * @param  array{name: string, raw: float, division: ?string, country?: string}  $row
     */
    private function upsertNonMemberScore(MatchEvent $match, array $row, ScoreValidationService $validation): void
    {
        $name = $row['name'];
        $divisionId = $this->divisionId($row['division']);

        $score = Score::query()
            ->where('match_id', $match->id)
            ->whereRaw('LOWER(shooter_name) = ?', [strtolower($name)])
            ->first();

        $user = $score?->user_id
            ? User::query()->find($score->user_id)
            : User::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if (! $user) {
            $user = $this->createNonMemberStub($name, $divisionId);
            $this->command?->line("Created non-member {$name}");
        } elseif ($this->isImportStub($user) && $user->membership) {
            $number = $user->membership->saprf_number;
            $user->membership()->delete();
            $user->unsetRelation('membership');
            $this->command?->line("Removed import membership {$number} from {$name}");
        } elseif ($user->membership) {
            $this->command?->warn("{$name} has a real membership. This match score is still stored as a non-member.");
        }

        $this->applyCountry($user, $row['country'] ?? null);

        if (! $score) {
            $score = new Score([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'shooter_name' => $name,
                'division_id' => $divisionId,
                'raw_score' => $row['raw'],
                'match_date' => $match->match_date,
                'counts_for_log' => true,
                'counts_for_season' => true,
                'raw_meta' => [
                    'source' => 'legacy Scores.xlsx',
                    'imported' => now()->toIso8601String(),
                ],
            ]);
            $score->save();
            $this->command?->line("Inserted {$name} at {$row['raw']}");
        } else {
            $score->user_id = $user->id;
            $score->shooter_name = $name;
            $score->raw_score = $row['raw'];
            if ($divisionId !== null) {
                $score->division_id = $divisionId;
            }
            $score->save();
            $this->command?->line("Updated {$name} to {$row['raw']}");
        }

        $validation->evaluateScoreStatus($score->fresh());

        $score->refresh();
        if ($score->status !== 'non_member' || $score->is_member) {
            $score->status = 'non_member';
            $score->is_member = false;
            $score->counts_for_season = false;
            $score->validation_reason = 'Shot as a non-member — score visible in match, excluded from season log.';
            $score->save();
            $validation->syncOfficialLog($score);
        }
    }

    private function applyCountry(User $user, ?string $country): void
    {
        if ($country === null || ! $this->isImportStub($user)) {
            return;
        }

        if (! array_key_exists($country, User::COUNTRY_OPTIONS)) {
            throw new RuntimeException("Unknown country [{$country}].");
        }

        $user->forceFill([
            'country_of_residence' => $country,
            'sa_citizen' => $country === 'ZA',
            'province_id' => $country === 'ZA' ? $user->province_id : null,
        ])->save();
    }

    private function isImportStub(User $user): bool
    {
        $email = strtolower((string) $user->email);

        return str_ends_with($email, '@import.saprf.local')
            || str_ends_with($email, '@saprf.walkin');
    }

    private function divisionId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        $id = Division::query()->where('slug', $slug)->value('id');
        if (! $id) {
            throw new RuntimeException("Division [{$slug}] is not seeded.");
        }

        return (int) $id;
    }

    private function createNonMemberStub(string $name, ?int $divisionId): User
    {
        $slug = Str::slug($name, '.') ?: 'shooter';
        $email = 'legacy.'.$slug.'@saprf.walkin';
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Str::random(40),
            'division_id' => $divisionId,
            'is_active' => true,
            'email_verified_at' => null,
            'must_change_password' => false,
        ]);
        $user->assignRole('member');

        return $user;
    }
}
