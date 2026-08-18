<?php

/**
 * Coverage for the `scores:apply-stage-pivot` command.
 *
 * Focus: the CSV parser correctly splits Day 1 vs Day 2 stage columns,
 * matches shooters (case-insensitive + ASCII-fold fallback for the
 * mis-encoded "LinÃ©" case), updates existing Score rows, warns on missing
 * users, skips rows without a Score unless --create-missing is set, and
 * writes a single AuditLog batch entry.
 */

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->openDivision = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
    $this->juniorDivision = Division::firstOrCreate(['slug' => 'junior'], ['name' => 'Junior', 'display_order' => 6]);
    $this->ladiesDivision = Division::firstOrCreate(['slug' => 'ladies'], ['name' => 'Ladies', 'display_order' => 5]);

    $this->match = MatchEvent::create([
        'name' => 'Clash Of The Legends',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subDay(),
        'status' => 'completed',
        'active_member_fee' => 1100,
        'non_member_fee' => 1300,
        'created_by' => User::factory()->create()->id,
    ]);
});

function makeShooterWithScore(MatchEvent $match, Division $division, string $name, float $day1 = 0, float $day2 = 0): array
{
    $user = User::factory()->create(['name' => $name]);
    $score = Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $name,
        'division_id' => $division->id,
        'day1_raw_score' => $day1,
        'day2_raw_score' => $day2,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $match->match_date,
        'counts_for_log' => true,
        'counts_for_season' => true,
    ]);

    return [$user, $score];
}

/**
 * Create a MatchRegistration row for the given user in the given division.
 * Fills the non-null columns the schema demands (shooter_name, fee category,
 * fee amount) so tests don't have to repeat the boilerplate.
 */
function seedRegistration(MatchEvent $match, User $user, Division $division, string $status = 'confirmed'): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name ?? 'Test Shooter',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0,
        'division_id' => $division->id,
        'registration_status' => $status,
    ]);
}

function writePivotCsv(string $path, array $rows, int $day1Stages = 3, int $day2Stages = 1): void
{
    $headers = ['Row Labels'];
    for ($i = 1; $i <= $day1Stages; $i++) {
        $headers[] = "Day 1 Stage {$i}";
    }
    for ($i = 1; $i <= $day2Stages; $i++) {
        $headers[] = "Day 2 Stage {$i}";
    }
    $headers[] = 'Grand Total';

    $lines = [
        'Sum of Impacts,Column Labels'.str_repeat(',', count($headers) - 1),
        implode(',', $headers),
    ];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }
    file_put_contents($path, implode("\n", $lines)."\n");
}

it('updates existing scores from a per-stage pivot CSV', function () {
    [$user, $score] = makeShooterWithScore($this->match, $this->openDivision, 'Andries Lategan', 40, 5);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    // 4 Day 1 stages + 1 Day 2 stage. Row: 8+12+8+9=37 day1, 9 day2, 46 total.
    writePivotCsv($path, [
        ['Andries Lategan', 8, 12, 8, 9, 9, 46],
    ], day1Stages: 4, day2Stages: 1);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
    ])->assertSuccessful();

    $score->refresh();
    expect((float) $score->day1_raw_score)->toBe(37.0)
        ->and((float) $score->day2_raw_score)->toBe(9.0)
        ->and((float) $score->raw_score)->toBe(46.0)
        ->and($score->raw_meta['stage_pivot_correction'] ?? null)->not->toBeNull();
});

it('is a no-op in dry-run mode', function () {
    [$user, $score] = makeShooterWithScore($this->match, $this->openDivision, 'Andries Lategan', 40, 5);
    $originalDay1 = (float) $score->day1_raw_score;

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [['Andries Lategan', 8, 12, 8, 9, 9, 46]], day1Stages: 4, day2Stages: 1);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('[dry-run]')
        ->assertSuccessful();

    $score->refresh();
    expect((float) $score->day1_raw_score)->toBe($originalDay1);
});

it('matches ASCII-folded names when the CSV has a mis-encoded diacritic', function () {
    [$user, $score] = makeShooterWithScore($this->match, $this->openDivision, 'Liné de Witt', 10, 2);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    // 3 Day 1 stages (1+2+3=6) + 1 Day 2 stage (4). Total 10.
    writePivotCsv($path, [
        ['LinÃ© de Witt', 1, 2, 3, 4, 10],
    ], day1Stages: 3, day2Stages: 1);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
    ])->assertSuccessful();

    $score->refresh();
    expect((float) $score->day1_raw_score)->toBe(6.0)
        ->and((float) $score->day2_raw_score)->toBe(4.0);
});

it('warns and skips a name that does not match any user', function () {
    [$existing, $score] = makeShooterWithScore($this->match, $this->openDivision, 'Andries Lategan', 40, 5);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [
        ['Andries Lategan', 8, 12, 8, 9, 9, 46],
        ['Nonexistent Person', 1, 2, 3, 4, 0, 10],
    ], day1Stages: 4, day2Stages: 1);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
    ])
        ->expectsOutputToContain('Nonexistent Person')
        ->assertSuccessful();

    $score->refresh();
    expect((float) $score->raw_score)->toBe(46.0);
});

it('skips users with no existing score unless --create-missing is set', function () {
    $userWithoutScore = User::factory()->create(['name' => 'No Score Yet']);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [
        ['No Score Yet', 5, 5, 5, 5, 20],
    ]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
    ])->assertSuccessful();

    expect(Score::where('user_id', $userWithoutScore->id)->exists())->toBeFalse();

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--create-missing' => true,
        '--division' => 'open',
    ])->assertSuccessful();

    $created = Score::where('user_id', $userWithoutScore->id)->first();
    expect($created)->not->toBeNull()
        ->and((float) $created->day1_raw_score)->toBe(15.0)
        ->and((float) $created->day2_raw_score)->toBe(5.0)
        ->and($created->division_id)->toBe($this->openDivision->id);
});

it('uses the shooter\'s MatchRegistration division when creating a missing Score row', function () {
    // The shooter has a real registration for this match in Junior — no
    // existing Score row yet, and the operator passes NO --division. The
    // new row must land in the shooter's registered division, not fall
    // back or fail.
    $junior = User::factory()->create(['name' => 'Erich Van der Merwe']);
    seedRegistration($this->match, $junior, $this->juniorDivision);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [
        ['Erich Van der Merwe', 5, 5, 5, 5, 20],
    ]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--create-missing' => true,
        '--skip-standings' => true,
    ])->assertSuccessful();

    $created = Score::where('user_id', $junior->id)->first();
    expect($created)->not->toBeNull()
        ->and($created->division_id)->toBe($this->juniorDivision->id)
        ->and($created->raw_meta['division_source'] ?? null)->toBe('registration');
});

it('falls back to --division when the missing shooter has no MatchRegistration', function () {
    $userNoReg = User::factory()->create(['name' => 'Walk In Wilma']);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [
        ['Walk In Wilma', 5, 5, 5, 5, 20],
    ]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--create-missing' => true,
        '--division' => 'ladies',
        '--skip-standings' => true,
    ])->assertSuccessful();

    $created = Score::where('user_id', $userNoReg->id)->first();
    expect($created)->not->toBeNull()
        ->and($created->division_id)->toBe($this->ladiesDivision->id)
        ->and($created->raw_meta['division_source'] ?? null)->toBe('fallback');
});

it('skips a missing shooter with no registration and no --division fallback', function () {
    $userNoReg = User::factory()->create(['name' => 'Ghost Shooter']);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [
        ['Ghost Shooter', 5, 5, 5, 5, 20],
    ]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--create-missing' => true,
        '--skip-standings' => true,
    ])
        ->expectsOutputToContain('Ghost Shooter')
        ->assertSuccessful();

    expect(Score::where('user_id', $userNoReg->id)->exists())->toBeFalse();
});

it('ignores a cancelled MatchRegistration and uses the fallback instead', function () {
    // If the shooter cancelled their entry we don't want to resurrect that
    // division — treat them like an unregistered walk-in.
    $user = User::factory()->create(['name' => 'Cancelled Cathy']);
    seedRegistration($this->match, $user, $this->juniorDivision, status: 'cancelled');

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [
        ['Cancelled Cathy', 5, 5, 5, 5, 20],
    ]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--create-missing' => true,
        '--division' => 'ladies',
        '--skip-standings' => true,
    ])->assertSuccessful();

    $created = Score::where('user_id', $user->id)->first();
    expect($created)->not->toBeNull()
        ->and($created->division_id)->toBe($this->ladiesDivision->id)
        ->and($created->raw_meta['division_source'] ?? null)->toBe('fallback');
});

it('writes a single AuditLog batch entry summarising the correction', function () {
    [$user, $score] = makeShooterWithScore($this->match, $this->openDivision, 'Andries Lategan', 40, 5);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    // Sum: 8+12+8 = 28 day1, 9 day2, total 37.
    writePivotCsv($path, [['Andries Lategan', 8, 12, 8, 9, 37]]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--skip-standings' => true,
    ])->assertSuccessful();

    $audit = AuditLog::where('action_type', 'score_correction_batch')
        ->where('entity_type', 'MatchEvent')
        ->where('entity_id', $this->match->id)
        ->latest()
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_value['updated'])->toBe(1)
        ->and($audit->new_value['source'])->toBe('stage_pivot_csv');
});

it('warns when the per-stage sum does not equal the CSV Grand Total', function () {
    [$user, $score] = makeShooterWithScore($this->match, $this->openDivision, 'Andries Lategan', 0, 0);

    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    // Real sum: 1+2+3+4 = 10, but Grand Total claims 99 (typo).
    writePivotCsv($path, [['Andries Lategan', 1, 2, 3, 4, 99]]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
        '--skip-standings' => true,
    ])
        ->expectsOutputToContain('does not equal Grand Total')
        ->assertSuccessful();
});

it('fails cleanly when the CSV has no Day/Stage columns', function () {
    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    file_put_contents($path, "Row Labels,Something Else,Grand Total\nAndries Lategan,10,10\n");

    $this->artisan('scores:apply-stage-pivot', [
        'match' => $this->match->id,
        'csv' => $path,
    ])
        ->expectsOutputToContain('no columns matching')
        ->assertFailed();
});

it('fails cleanly when the match does not exist', function () {
    $path = tempnam(sys_get_temp_dir(), 'pivot').'.csv';
    writePivotCsv($path, [['Andries Lategan', 8, 12, 8, 9, 46]]);

    $this->artisan('scores:apply-stage-pivot', [
        'match' => 999999,
        'csv' => $path,
    ])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});
