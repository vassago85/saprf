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
    $user->update(['division_id' => null]); // no default division either
    backfillTestScore($this->match, $user, null);
    // No registration AND no user default — the leftover "no source" case.

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])
        ->expectsOutputToContain('no MatchRegistration')
        ->assertSuccessful();
});

// ──────────────────────────────────────────────────────────────────────
// --fall-back-to-user-division: second-tier fallback for scraped imports
// ──────────────────────────────────────────────────────────────────────

it('does not touch null-division scores with no registration when the flag is off (default)', function () {
    // Scraped import: shooter has no MatchRegistration on this match, but
    // does have a User.division_id. Without the opt-in flag the command
    // preserves the historic behaviour and leaves the Score alone.
    $user = backfillTestUser('Larry Lima');
    $user->update(['division_id' => $this->open->id]);
    $score = backfillTestScore($this->match, $user, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBeNull();
});

it('fills from User.division_id when the flag is set and no registration exists', function () {
    // Scraped-import case: Dalton has no MatchRegistration on the Day-1
    // provincial but is registered as an Open shooter. With the flag on,
    // the score picks up Open and feeds the Open division standing.
    $user = backfillTestUser('Mike Mike');
    $user->update(['division_id' => $this->open->id]);
    $score = backfillTestScore($this->match, $user, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->open->id);
});

it('prefers MatchRegistration over User.division_id when both are present', function () {
    // The flag is a fallback, not a replacement. When the shooter has a
    // registration, the registration wins even if their default is a
    // different division.
    $user = backfillTestUser('Nina November');
    $user->update(['division_id' => $this->open->id]);
    $score = backfillTestScore($this->match, $user, null);
    backfillTestRegistration($this->match, $user, $this->junior);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->junior->id);
});

it('fills from User.division_id when a cancelled registration is the only one on file', function () {
    // Cancelled registrations are ignored (same rule as the registration-only
    // path), and the user-default fallback kicks in.
    $user = backfillTestUser('Oscar Oscar');
    $user->update(['division_id' => $this->ladies->id]);
    $score = backfillTestScore($this->match, $user, null);
    backfillTestRegistration($this->match, $user, $this->open, 'cancelled');

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->ladies->id);
});

it('never overwrites an existing non-null Score.division_id via the user-default fallback', function () {
    // Even with --overwrite-mismatches, the user-default source only ever
    // fills nulls. Overwriting a hand-corrected division from a user default
    // would be far too aggressive.
    $user = backfillTestUser('Peter Papa');
    $user->update(['division_id' => $this->junior->id]);
    $score = backfillTestScore($this->match, $user, $this->open->id);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
        '--overwrite-mismatches' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->open->id);
});

it('falls through to shooter history when the user has no default division', function () {
    // Dalton's real case: scraped-import score, no MatchRegistration, no
    // User.division_id, but every one of his OTHER scores is Open — so the
    // modal-across-history fallback attributes this score to Open too.
    $user = backfillTestUser('Simon Sierra');
    $user->update(['division_id' => null]);

    // A second match where the same shooter already has Open scored.
    $otherMatch = MatchEvent::create([
        'name' => 'Prior Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subMonths(2),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'created_by' => $user->id,
    ]);
    backfillTestScore($otherMatch, $user, $this->open->id);

    $score = backfillTestScore($this->match, $user, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->open->id);
});

it('prefers User.division_id over shooter history when both are available', function () {
    // History and user default disagree: user default wins because it's the
    // shooter's declared home division, not inferred from behaviour.
    $user = backfillTestUser('Tina Tango');
    $user->update(['division_id' => $this->ladies->id]);

    $otherMatch = MatchEvent::create([
        'name' => 'Prior Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subMonths(2),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'created_by' => $user->id,
    ]);
    backfillTestScore($otherMatch, $user, $this->open->id);

    $score = backfillTestScore($this->match, $user, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->ladies->id);
});

it('picks the modal division when the shooter has more than one in their history', function () {
    // Two Open, one Junior — Open wins because it's the mode.
    $user = backfillTestUser('Uri Uniform');
    $user->update(['division_id' => null]);

    foreach ([$this->open, $this->open, $this->junior] as $i => $division) {
        $otherMatch = MatchEvent::create([
            'name' => 'Prior '.$i,
            'match_type' => 'PR22',
            'series_level' => 'provincial',
            'series' => 'PR22',
            'season' => '2026',
            'province_id' => $this->province->id,
            'match_date' => Carbon::today()->subMonths(3 + $i),
            'status' => 'completed',
            'active_member_fee' => 500,
            'non_member_fee' => 700,
            'created_by' => $user->id,
        ]);
        backfillTestScore($otherMatch, $user, $division->id);
    }

    $score = backfillTestScore($this->match, $user, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBe($this->open->id);
});

it('does nothing from shooter history when the fallback flag is off', function () {
    // Same setup as the shooter-history test, but without the opt-in flag.
    // Historic behaviour must not change for callers not passing the flag.
    $user = backfillTestUser('Victor Victor');
    $user->update(['division_id' => null]);

    $otherMatch = MatchEvent::create([
        'name' => 'Prior Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subMonths(2),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'created_by' => $user->id,
    ]);
    backfillTestScore($otherMatch, $user, $this->open->id);

    $score = backfillTestScore($this->match, $user, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    expect($score->fresh()->division_id)->toBeNull();
});

it('records user-default fills in the AuditLog separately from registration fills', function () {
    $regFilled = backfillTestUser('Quinn Quebec');
    $regFilledScore = backfillTestScore($this->match, $regFilled, null);
    backfillTestRegistration($this->match, $regFilled, $this->open);

    $userFilled = backfillTestUser('Rita Romeo');
    $userFilled->update(['division_id' => $this->ladies->id]);
    $userFilledScore = backfillTestScore($this->match, $userFilled, null);

    $this->artisan('scores:backfill-division-from-registration', [
        'match' => $this->match->id,
        '--fall-back-to-user-division' => true,
    ])->assertSuccessful();

    expect($regFilledScore->fresh()->division_id)->toBe($this->open->id)
        ->and($userFilledScore->fresh()->division_id)->toBe($this->ladies->id);

    $log = AuditLog::where('action_type', 'score_division_backfill')
        ->where('entity_id', $this->match->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_value['source'])->toBe('registration_and_user_default')
        ->and($log->new_value['filled'])->toBe(1)
        ->and($log->new_value['filled_from_user_default'])->toBe(1);
});
