<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

/**
 * End-to-end coverage for `prs:import-scraped` using fixture CSVs. Verifies the
 * additive import (never wipes), reuse-of-existing-shooters by name, upcoming
 * match creation, idempotency, and correct PRS annual-log scoring.
 */

function prsFixtureDir(): array
{
    $rel = 'storage/app/prs_test_'.\Illuminate\Support\Str::random(8);
    $abs = base_path($rel);
    File::ensureDirectoryExists($abs.'/national');
    File::ensureDirectoryExists($abs.'/provincial');

    return [$rel, $abs];
}

/** @param array<int, array{0:string,1:string,2:int|float,3:int}> $rows */
function writeScoreCsv(string $absDir, string $rel, array $rows): string
{
    $path = $absDir.'/'.$rel;
    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['shooter_name', 'division', 'raw_score', 'placement']);
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);

    return $path;
}

beforeEach(function () {
    Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('developer', 'web');
    $this->creator = User::factory()->create(['name' => 'Dev Owner']);
    $this->creator->assignRole('developer');

    QualificationRule::create([
        'series' => 'PRS', 'season' => '2026', 'scoring_mode' => 'best_n_plus_champs',
        'min_out_of_province_matches' => 0, 'best_of_count' => 3, 'total_qualifying_matches' => 4,
        'created_by' => $this->creator->id,
    ]);

    [$this->rel, $this->abs] = prsFixtureDir();

    // Four regular nationals + a champs. In each, "Bob" is a 100-point pace
    // setter; "Alice" trails. N4 is Alice's 4th-best regular (should drop).
    writeScoreCsv($this->abs, 'national/n1.csv', [['Bob', 'Open', 100, 1], ['Alice', 'Open', 100, 1]]);
    writeScoreCsv($this->abs, 'national/n2.csv', [['Bob', 'Open', 100, 1], ['Alice', 'Open', 100, 1]]);
    writeScoreCsv($this->abs, 'national/n3.csv', [['Bob', 'Open', 100, 1], ['Alice', 'Open', 100, 1]]);
    writeScoreCsv($this->abs, 'national/n4.csv', [['Bob', 'Open', 100, 1], ['Alice', 'Open', 50, 2]]);
    writeScoreCsv($this->abs, 'national/champs.csv', [['Bob', 'Open', 100, 1], ['Alice', 'Open', 80, 2]]);

    $matchesCsv = $this->abs.'/matches.csv';
    $mh = fopen($matchesCsv, 'w');
    fwrite($mh, "\xEF\xBB\xBF");
    fputcsv($mh, ['source_id', 'name', 'match_type', 'series', 'season', 'series_level', 'match_date', 'match_end_date', 'province', 'venue_name', 'match_director', 'contact', 'also_counts_for_provincial', 'shooter_count', 'source_url', 'scores_csv']);
    $mk = function (int $id, string $name, string $level, string $date, string $csv) {
        return [$id, $name, 'PRS', 'PRS', '2026', $level, $date, '', 'Gauteng', 'Venue', 'MD', 'c', 0, 2, 'http://x/'.$id, $this->rel.'/'.$csv];
    };
    fputcsv($mh, $mk(1, 'PRS Nat 1', 'national', '2026-02-01', 'national/n1.csv'));
    fputcsv($mh, $mk(2, 'PRS Nat 2', 'national', '2026-03-01', 'national/n2.csv'));
    fputcsv($mh, $mk(3, 'PRS Nat 3', 'national', '2026-04-01', 'national/n3.csv'));
    fputcsv($mh, $mk(4, 'PRS Nat 4', 'national', '2026-05-01', 'national/n4.csv'));
    fputcsv($mh, $mk(5, 'PRS Champs', 'final', '2026-11-01', 'national/champs.csv'));
    fclose($mh);

    $upcomingCsv = $this->abs.'/upcoming.csv';
    $uh = fopen($upcomingCsv, 'w');
    fwrite($uh, "\xEF\xBB\xBF");
    fputcsv($uh, ['source_id', 'name', 'match_type', 'series', 'season', 'series_level', 'match_date', 'match_end_date', 'province', 'venue_name', 'match_director', 'contact', 'source_url']);
    fputcsv($uh, [6, 'PRS Future Nat', 'PRS', 'PRS', '2026', 'national', '2026-12-20', '2026-12-21', 'Gauteng', 'Venue', 'MD', 'c', 'http://x/6']);
    fclose($uh);
});

afterEach(function () {
    File::deleteDirectory($this->abs);
});

it('imports completed PRS matches, scores, shooters and the annual log', function () {
    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    expect(MatchEvent::where('match_type', 'PRS')->where('status', 'completed')->count())->toBe(5);
    expect(Score::whereHas('match', fn ($q) => $q->where('match_type', 'PRS'))->count())->toBe(10);

    // New shooters got stub emails + active waived memberships.
    $alice = User::whereRaw('LOWER(name) = ?', ['alice'])->first();
    expect($alice)->not->toBeNull();
    expect($alice->email)->toContain('@import.saprf.local');
    expect($alice->membership)->not->toBeNull();
    expect($alice->membership->status)->toBe('active');
    expect($alice->membership->payment_status)->toBe('waived');

    // Annual log (Open division): Bob = 3x100 + champs 100 = 400 (rank 1);
    // Alice = best 3 (100+100+100, the 50 dropped) + champs 80 = 380 (rank 2).
    $bob = User::whereRaw('LOWER(name) = ?', ['bob'])->first();
    $openId = $this->open->id;

    $bobStanding = Standing::where('series', 'PRS')->where('user_id', $bob->id)->where('division_id', $openId)->first();
    $aliceStanding = Standing::where('series', 'PRS')->where('user_id', $alice->id)->where('division_id', $openId)->first();

    expect((float) $bobStanding->points)->toBe(400.00);
    expect($bobStanding->rank)->toBe(1);
    expect((float) $aliceStanding->points)->toBe(380.00);
    expect($aliceStanding->rank)->toBe(2);

    // Alice's dropped 4th regular (50) is not among her counted regulars.
    $countedPcts = collect($aliceStanding->pool_breakdown['regular'])->pluck('pct')->map(fn ($v) => (float) $v);
    expect($countedPcts->contains(50.0))->toBeFalse();
    expect((float) $aliceStanding->pool_breakdown['champs_pct'])->toBe(80.00);
});

it('sets the match director from the scrape and exposes it via director_name', function () {
    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    $m = MatchEvent::where('name', 'PRS Nat 1')->first();
    expect($m->match_director)->toBe('MD')
        ->and($m->match_director_contact)->toBe('c')
        ->and($m->director_name)->toBe('MD');
});

it('backfills the match director onto a pre-existing PRS match without touching scores', function () {
    // A match imported before the match_director field existed (creator only).
    $pre = MatchEvent::create([
        'name' => 'PRS Nat 1', 'match_type' => 'PRS', 'series' => 'PRS', 'series_level' => 'national',
        'season' => '2026', 'match_date' => '2026-02-01', 'status' => 'completed',
        'created_by' => $this->creator->id, 'published' => true, 'match_director' => null,
    ]);
    expect($pre->director_name)->toBe('Dev Owner'); // falls back to the owning account

    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    $pre->refresh();
    expect($pre->match_director)->toBe('MD')
        ->and($pre->director_name)->toBe('MD')
        ->and($pre->scores()->count())->toBe(0); // existing match: scores left alone
});

it('creates upcoming matches as published, score-less events', function () {
    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    $future = MatchEvent::where('name', 'PRS Future Nat')->first();
    expect($future)->not->toBeNull();
    expect($future->status)->toBe('open');
    expect($future->published)->toBeTrue();
    expect($future->scores()->count())->toBe(0);
});

it('reuses an existing shooter by name instead of duplicating', function () {
    // Pre-existing "Bob" with NO membership — the import must reuse this row
    // and simply attach a membership, not create a second Bob.
    $existingBob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@real.example']);

    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    expect(User::whereRaw('LOWER(name) = ?', ['bob'])->count())->toBe(1);
    $bob = $existingBob->fresh();
    expect($bob->email)->toBe('bob@real.example'); // untouched
    expect($bob->membership)->not->toBeNull();      // membership added
    expect($bob->division_id)->toBe($this->open->id); // division backfilled
});

it('is additive — leaves unrelated existing data intact', function () {
    $pr22 = MatchEvent::create([
        'name' => 'PR22 Keeper', 'match_type' => 'PR22', 'series' => 'PR22', 'series_level' => 'national',
        'season' => '2026', 'match_date' => '2026-02-01', 'status' => 'completed', 'created_by' => $this->creator->id,
        'published' => true,
    ]);

    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    expect(MatchEvent::whereKey($pr22->id)->exists())->toBeTrue();
    expect(MatchEvent::where('match_type', 'PR22')->count())->toBe(1);
});

it('is idempotent — a second run does not duplicate matches or scores', function () {
    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();
    $matchesAfterFirst = MatchEvent::where('match_type', 'PRS')->count();
    $scoresAfterFirst = Score::whereHas('match', fn ($q) => $q->where('match_type', 'PRS'))->count();

    $this->artisan('prs:import-scraped', ['--dir' => $this->rel])->assertOk();

    expect(MatchEvent::where('match_type', 'PRS')->count())->toBe($matchesAfterFirst);
    expect(Score::whereHas('match', fn ($q) => $q->where('match_type', 'PRS'))->count())->toBe($scoresAfterFirst);
});

it('supports --dry-run without writing anything', function () {
    $this->artisan('prs:import-scraped', ['--dir' => $this->rel, '--dry-run' => true])->assertOk();

    expect(MatchEvent::where('match_type', 'PRS')->count())->toBe(0);
    expect(User::whereRaw('LOWER(name) = ?', ['alice'])->count())->toBe(0);
});
