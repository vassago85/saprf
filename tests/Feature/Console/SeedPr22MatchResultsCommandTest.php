<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

/**
 * End-to-end coverage for `pr22:seed-match-results`. Verifies:
 * - Practiscore CSV columns (Rank, Competitor, Impacts, Division, ...) map to
 *   the right Score fields.
 * - Existing users are matched by name (case-insensitive).
 * - Unmatched shooters are skipped by default and stubbed with --create-stubs.
 * - --force-replace wipes existing scores before re-importing.
 * - Refuses to run when scores already exist without --force-replace.
 * - StandingsCalculationService populates normalized_score / placements after.
 */

beforeEach(function () {
    Province::firstOrCreate(['abbreviation' => 'LP'], ['name' => 'Limpopo']);
    Division::firstOrCreate(['slug' => 'open'],    ['name' => 'Open',    'display_order' => 1]);
    Division::firstOrCreate(['slug' => 'factory'], ['name' => 'Factory', 'display_order' => 2]);
    Division::firstOrCreate(['slug' => 'ladies'],  ['name' => 'Ladies',  'display_order' => 5]);
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('developer', 'web');
});

function makePr22NationalMatch(): MatchEvent
{
    $lp = Province::where('abbreviation', 'LP')->firstOrFail();
    $creator = User::factory()->create();

    return MatchEvent::create([
        'name' => 'Limpopo PR22 2-Day National',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $lp->id,
        'match_date' => '2026-08-08',
        'status' => 'completed',
        'created_by' => $creator->id,
        'published' => true,
    ]);
}

function writePracticalScoresCsv(array $rows): string
{
    $dir = base_path('storage/app/pr22_seed_test_'.\Illuminate\Support\Str::random(6));
    File::ensureDirectoryExists($dir);
    $path = $dir.'/results.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, ['Rank', 'Competitor', 'Username', 'Impacts', 'Success Rate', 'Dropped', 'Score', 'Division', 'Stages Completed', 'Shots Taken']);
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);

    return $path;
}

afterEach(function () {
    foreach (glob(base_path('storage/app/pr22_seed_test_*')) ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('seeds scores by matching CSV competitors to existing users by name', function () {
    $match = makePr22NationalMatch();
    $johan = User::factory()->create(['name' => 'Johan Nel']);
    $marcel = User::factory()->create(['name' => 'Marcel Steyn']);
    $russell = User::factory()->create(['name' => 'Russell Ferreira']);

    $csv = writePracticalScoresCsv([
        [1, 'Johan Nel',        'JohanNel', 120, '89.55%', -14, '100.00%', 'Open',    '13 / 13', '133 / 134'],
        [2, 'Marcel Steyn',     'SellyBRA', 118, '88.06%', -16,  '98.33%', 'Open',    '13 / 13', '133 / 134'],
        [3, 'Russell Ferreira', 'Russmann', 108, '80.60%', -26,  '90.00%', 'Factory', '13 / 13', '130 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $match->id, 'csv' => $csv])
        ->assertSuccessful();

    expect(Score::where('match_id', $match->id)->count())->toBe(3);

    $johanScore = Score::where('match_id', $match->id)->where('user_id', $johan->id)->firstOrFail();
    expect((float) $johanScore->raw_score)->toBe(120.0);
    expect((float) $johanScore->day1_raw_score)->toBe(120.0);
    expect($johanScore->placement)->toBe(1);
    expect($johanScore->division_id)->toBe(Division::where('slug', 'open')->value('id'));
    expect($johanScore->is_member)->toBeTrue();
    expect($johanScore->status)->toBe('valid');
    expect($johanScore->raw_meta['source'])->toBe('practiscore_csv');
    expect($johanScore->raw_meta['username'])->toBe('JohanNel');

    // normalized_score is populated by StandingsCalculationService::recalculateForMatch,
    // which the command invokes after the raw scores are written.
    expect($johanScore->fresh()->normalized_score)->not->toBeNull();
    expect((float) $johanScore->fresh()->normalized_score)->toBe(100.0);

    $russellScore = Score::where('match_id', $match->id)->where('user_id', $russell->id)->firstOrFail();
    expect($russellScore->division_id)->toBe(Division::where('slug', 'factory')->value('id'));
    expect($russellScore->placement)->toBe(3);
});

it('warns-and-skips unmatched competitors by default', function () {
    $match = makePr22NationalMatch();
    User::factory()->create(['name' => 'Johan Nel']);

    $csv = writePracticalScoresCsv([
        [1, 'Johan Nel',   'JohanNel', 120, '89.55%', -14, '100.00%', 'Open', '13 / 13', '133 / 134'],
        [2, 'Ghost Rider', 'ghost',    100, '74.63%', -34,  '83.33%', 'Open', '13 / 13', '130 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $match->id, 'csv' => $csv])
        ->assertSuccessful();

    expect(Score::where('match_id', $match->id)->count())->toBe(1);
    expect(User::where('name', 'Ghost Rider')->exists())->toBeFalse();
});

it('creates stub users + waived memberships with --create-stubs', function () {
    $match = makePr22NationalMatch();
    $csv = writePracticalScoresCsv([
        [1, 'New Shooter One', 'ns1', 100, '74.63%', -34, '83.33%', 'Open',    '13 / 13', '130 / 134'],
        [2, 'New Shooter Two', 'ns2',  90, '67.16%', -44, '75.00%', 'Factory', '13 / 13', '128 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', [
        'match' => $match->id,
        'csv' => $csv,
        '--create-stubs' => true,
    ])->assertSuccessful();

    $stub1 = User::where('name', 'New Shooter One')->firstOrFail();
    $stub2 = User::where('name', 'New Shooter Two')->firstOrFail();

    expect($stub1->email)->toEndWith('@import.saprf.local');
    expect($stub1->membership)->not->toBeNull();
    expect($stub1->membership->payment_status)->toBe('waived');

    expect(Score::where('match_id', $match->id)->count())->toBe(2);
    expect(Score::where('user_id', $stub2->id)->value('placement'))->toBe(2);
});

it('refuses to run when scores already exist without --force-replace', function () {
    $match = makePr22NationalMatch();
    Score::create([
        'match_id' => $match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Pre-existing',
        'raw_score' => 50,
        'status' => 'valid',
        'match_date' => $match->match_date,
    ]);

    $csv = writePracticalScoresCsv([
        [1, 'Whoever', 'w', 100, '74.63%', -34, '83.33%', 'Open', '13 / 13', '130 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $match->id, 'csv' => $csv])
        ->assertFailed();

    expect(Score::where('match_id', $match->id)->count())->toBe(1);
});

it('wipes and re-imports when --force-replace is passed', function () {
    $match = makePr22NationalMatch();
    $johan = User::factory()->create(['name' => 'Johan Nel']);
    Score::create([
        'match_id' => $match->id,
        'user_id' => $johan->id,
        'shooter_name' => 'Johan Nel',
        'raw_score' => 5,
        'status' => 'valid',
        'match_date' => $match->match_date,
    ]);

    $csv = writePracticalScoresCsv([
        [1, 'Johan Nel', 'JohanNel', 120, '89.55%', -14, '100.00%', 'Open', '13 / 13', '133 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', [
        'match' => $match->id,
        'csv' => $csv,
        '--force-replace' => true,
    ])->assertSuccessful();

    $scores = Score::where('match_id', $match->id)->get();
    expect($scores)->toHaveCount(1);
    expect((float) $scores->first()->raw_score)->toBe(120.0);
});

it('accepts a PRS match — the Practiscore CSV format is shared with PR22', function () {
    $lp = Province::where('abbreviation', 'LP')->firstOrFail();
    $prsMatch = MatchEvent::create([
        'name' => 'Limpopo PRS 2-Day National',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $lp->id,
        'match_date' => '2026-08-08',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'published' => true,
    ]);
    $shooter = User::factory()->create(['name' => 'Piet Prs']);
    $csv = writePracticalScoresCsv([
        [1, 'Piet Prs', 'piet', 100, '74.63%', -34, '83.33%', 'Open', '13 / 13', '130 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $prsMatch->id, 'csv' => $csv])
        ->assertSuccessful();

    expect(Score::where('match_id', $prsMatch->id)->count())->toBe(1);
    expect(Score::where('user_id', $shooter->id)->value('raw_score'))->toEqual(100);
});

it('rejects a match whose series is neither PR22 nor PRS', function () {
    $lp = Province::where('abbreviation', 'LP')->firstOrFail();
    $other = MatchEvent::create([
        'name' => 'Some IPSC event',
        'match_type' => 'IPSC',
        'series' => 'IPSC',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $lp->id,
        'match_date' => '2026-08-08',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'published' => true,
    ]);
    $csv = writePracticalScoresCsv([
        [1, 'Someone', 'x', 100, '74.63%', -34, '83.33%', 'Open', '13 / 13', '130 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $other->id, 'csv' => $csv])
        ->assertFailed();
});

it('normalizes plural division names (Seniors/Juniors) to their singular DB slugs', function () {
    Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 7]);
    Division::firstOrCreate(['slug' => 'junior'], ['name' => 'Junior', 'display_order' => 6]);

    $match = makePr22NationalMatch();
    $senior = User::factory()->create(['name' => 'Trevor Graham']);
    $junior = User::factory()->create(['name' => 'MC van Tonder']);

    // Practiscore exports often say 'Seniors'/'Juniors' (plural) while the DB
    // uses 'senior'/'junior' — the command must resolve both automatically.
    $csv = writePracticalScoresCsv([
        [1, 'Trevor Graham', 'TrevorG',  78, '81.25%', -18, '93.98%', 'Seniors', '8 / 8', '94 / 96'],
        [2, 'MC van Tonder', 'Tjoppies', 59, '61.46%', -37, '71.08%', 'Juniors', '8 / 8', '96 / 96'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $match->id, 'csv' => $csv])
        ->assertSuccessful();

    expect(Score::where('user_id', $senior->id)->value('division_id'))
        ->toBe(Division::where('slug', 'senior')->value('id'));
    expect(Score::where('user_id', $junior->id)->value('division_id'))
        ->toBe(Division::where('slug', 'junior')->value('id'));
});

it('transitions the match to status=completed by default so the events list card renders the results layout', function () {
    $match = makePr22NationalMatch();
    $match->status = 'open';
    $match->save();

    User::factory()->create(['name' => 'Johan Nel']);
    $csv = writePracticalScoresCsv([
        [1, 'Johan Nel', 'JohanNel', 120, '89.55%', -14, '100.00%', 'Open', '13 / 13', '133 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', ['match' => $match->id, 'csv' => $csv])
        ->assertSuccessful();

    expect($match->fresh()->status)->toBe('completed');
});

it('leaves the match status alone when --keep-status is passed', function () {
    $match = makePr22NationalMatch();
    $match->status = 'open';
    $match->save();

    User::factory()->create(['name' => 'Johan Nel']);
    $csv = writePracticalScoresCsv([
        [1, 'Johan Nel', 'JohanNel', 120, '89.55%', -14, '100.00%', 'Open', '13 / 13', '133 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', [
        'match' => $match->id,
        'csv' => $csv,
        '--keep-status' => true,
    ])->assertSuccessful();

    expect($match->fresh()->status)->toBe('open');
});

it('supports --dry-run without writing anything', function () {
    $match = makePr22NationalMatch();
    User::factory()->create(['name' => 'Johan Nel']);
    $csv = writePracticalScoresCsv([
        [1, 'Johan Nel', 'JohanNel', 120, '89.55%', -14, '100.00%', 'Open', '13 / 13', '133 / 134'],
    ]);

    $this->artisan('pr22:seed-match-results', [
        'match' => $match->id,
        'csv' => $csv,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(Score::where('match_id', $match->id)->count())->toBe(0);
});
