<?php

/**
 * Coverage for `scores:backfill-division-from-registration`.
 *
 * Focus: fills Score.division_id from a shooter's MatchRegistration only
 * when the score currently has no division; leaves conflicting non-null
 * divisions alone unless --overwrite-mismatches is passed; ignores
 * cancelled registrations; writes an AuditLog per touched match.
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
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
    $this->junior = Division::firstOrCreate(['slug' => 'junior'], ['name' => 'Junior', 'display_order' => 2]);
    $this->ladies = Division::firstOrCreate(['slug' => 'ladies'], ['name' => 'Ladies', 'display_order' => 3]);

    $this->match = MatchEvent::create([
        'name' => 'Backfill Test Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subDay(),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'created_by' => User::factory()->create()->id,
    ]);
});

/**
 * Local helpers — do not collide with global helpers defined in other
 * feature suites (Pest test functions live in the global namespace and
 * a duplicate name across two files would fatal-error the whole runner).
 */
function backfillTestUser(string $name): User
{
    return User::factory()->create(['name' => $name]);
}

function backfillTestScore(MatchEvent $match, User $user, ?int $divisionId): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'division_id' => $divisionId,
        'raw_score' => 80,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $match->match_date,
        'counts_for_log' => true,
        'counts_for_season' => true,
    ]);
}

function backfillTestRegistration(MatchEvent $match, User $user, Division $division, string $status = 'confirmed'): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0,
        'division_id' => $division->id,
        'registration_status' => $status,
    ]);
}

it('fills division_id when Score.division_id is NULL and a registration exists', function () {
    $user = backfillTestUser('Alice Zulu');
    $score = backfillTestScore($this->match, $user, null);
    backfillTestRegistration($this->match, $user, $this->junior);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->junior->id);
});

it('leaves an already-populated Score.division_id alone when it agrees with the registration', function () {
    $user = backfillTestUser('Bob Alpha');
    $score = backfillTestScore($this->match, $user, $this->open->id);
    backfillTestRegistration($this->match, $user, $this->open);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->open->id);
});

it('warns and skips when the Score division disagrees with the registration by default', function () {
    $user = backfillTestUser('Cara Mid');
    $score = backfillTestScore($this->match, $user, $this->open->id);
    backfillTestRegistration($this->match, $user, $this->ladies);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])
        ->expectsOutputToContain('disagree with their registration')
        ->assertSuccessful();

    // Kept as-is
    expect($score->fresh()->division_id)->toBe($this->open->id);
});

it('overwrites disagreeing divisions when --overwrite-mismatches is passed', function () {
    $user = backfillTestUser('Dumi Delta');
    $score = backfillTestScore($this->match, $user, $this->open->id);
    backfillTestRegistration($this->match, $user, $this->ladies);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--overwrite-mismatches' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->ladies->id);
});

it('ignores cancelled registrations even when they would otherwise fill a null', function () {
    $user = backfillTestUser('Eve Echo');
    $score = backfillTestScore($this->match, $user, null);
    backfillTestRegistration($this->match, $user, $this->open, 'cancelled');

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBeNull();
});

it('prefers the most recent non-cancelled registration when a shooter re-entered', function () {
    $user = backfillTestUser('Frank Foxtrot');
    $score = backfillTestScore($this->match, $user, null);
    // First registration cancelled, second (later id) is active — junior wins.
    backfillTestRegistration($this->match, $user, $this->open, 'cancelled');
    backfillTestRegistration($this->match, $user, $this->junior, 'confirmed');

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->junior->id);
});

it('writes an AuditLog summarising the changes it made', function () {
    $filled = backfillTestUser('Gina Golf');
    $filledScore = backfillTestScore($this->match, $filled, null);
    backfillTestRegistration($this->match, $filled, $this->junior);

    $skipped = backfillTestUser('Henry Hotel');
    backfillTestScore($this->match, $skipped, $this->open->id);
    backfillTestRegistration($this->match, $skipped, $this->ladies);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    $log = AuditLog::where('action_type', 'score_division_backfill')
        ->where('entity_id', $this->match->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_value['filled'])->toBe(1)
        ->and($log->new_value['overwritten_mismatch'])->toBe(0)
        ->and($log->new_value['skipped_mismatch'])->toBe(1)
        ->and($log->new_value['overwrite_mode'])->toBeFalse()
        ->and(collect($log->new_value['mismatch_details'])->pluck('shooter_name')->all())->toBe(['Henry Hotel']);
});

it('writes nothing in dry-run mode', function () {
    $user = backfillTestUser('Ivan India');
    $score = backfillTestScore($this->match, $user, null);
    backfillTestRegistration($this->match, $user, $this->junior);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBeNull()
        ->and(AuditLog::where('action_type', 'score_division_backfill')->count())->toBe(0);
});

it('exits cleanly when no scores need backfilling', function () {
    $user = backfillTestUser('Judy Juliet');
    backfillTestScore($this->match, $user, $this->open->id);
    backfillTestRegistration($this->match, $user, $this->open);

    $this->artisan('scores:backfill-division-from-registration')
        ->expectsOutputToContain('No matches with missing Score.division_id')
        ->assertSuccessful();
});

it('errors when the specified match id does not exist', function () {
    $this->artisan('scores:backfill-division-from-registration', [
        'match' => 999999,
    ])
        ->expectsOutputToContain('does not exist')
        ->assertSuccessful();
});

it('reports scores with NULL division_id that have no registration at all', function () {
    $user = backfillTestUser('Kate Kilo');
    backfillTestScore($this->match, $user, null);
    // No registration — this is the walk-in case, not our problem.

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])
        ->expectsOutputToContain('no MatchRegistration')
        ->assertSuccessful();
});
