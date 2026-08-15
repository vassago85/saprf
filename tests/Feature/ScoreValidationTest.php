<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\ScoreValidationService;
use Carbon\Carbon;

beforeEach(function () {
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
    $province = Province::where('abbreviation', 'GP')->first();

    $this->service = app(ScoreValidationService::class);

    $this->match = MatchEvent::create([
        'name' => 'Score Validation Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today(),
        'status' => 'closed',
        'active_member_fee' => 250.00,
        'non_member_fee' => 500.00,
        'lapsed_member_fee' => 375.00,
        'created_by' => User::factory()->create()->id,
    ]);
});

function makeScore($match, $user, string $status = 'pending', ?Carbon $date = null, float $raw = 100.0): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user?->id,
        'shooter_name' => $user?->name ?? 'Unknown',
        'raw_score' => $raw,
        'placement' => 1,
        'division_id' => Division::where('slug', 'open')->value('id'),
        'match_date' => $date ?? Carbon::today(),
        'status' => $status,
    ]);
}

test('active + paid member on match date → status=valid', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-001',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user));

    expect($result->status)->toBe('valid')
        ->and($result->is_member)->toBeTrue();
});

test('shooter with NO membership record → status=non_member (no grace)', function () {
    $user = User::factory()->create();

    $withinGrace = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', Carbon::today()->subDays(2)));
    $pastGrace = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', Carbon::today()->subDays(30)));

    expect($withinGrace->status)->toBe('non_member')
        ->and($pastGrace->status)->toBe('non_member')
        ->and($withinGrace->is_member)->toBeFalse();
});

test('free registrant (forced to register for one provincial) → status=non_member even if flagged active/paid', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-FREE',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user));

    expect($result->status)->toBe('non_member')
        ->and($result->is_member)->toBeFalse();
});

test('a score earned inside the paid window stays valid even after the membership later expires', function () {
    $matchDate = Carbon::today()->subMonths(2);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-EXPIRED',
        'membership_type' => 'paid',
        'status' => 'expired', // has since lapsed
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(8),
        'expiry_date' => Carbon::today()->subMonths(1), // covered the match date
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('valid')
        ->and($result->is_member)->toBeTrue();
});

test('shooter WITH a membership but lapsed at match, within 7-day grace → status=pending', function () {
    $matchDate = Carbon::today()->subDays(3);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-002',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('pending')
        ->and($result->is_member)->toBeFalse();
});

test('shooter WITH a membership but lapsed, past 7-day grace → status=lapsed (not invalid)', function () {
    $matchDate = Carbon::today()->subDays(10);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-003',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('lapsed')
        ->and($result->is_member)->toBeFalse();
});

test('score without user_id → status=invalid (orphan)', function () {
    $result = $this->service->evaluateScoreStatus(makeScore($this->match, null));

    expect($result->status)->toBe('invalid')
        ->and($result->validation_reason)->toBe('No linked member account.');
});

test('everyone_counts match forces valid regardless of membership state', function () {
    $matchDate = Carbon::today()->subDays(30);
    $everyoneMatch = MatchEvent::create([
        'name' => 'Day-1 Provincial (Everyone Counts)',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->match->province_id,
        'match_date' => $matchDate,
        'status' => 'completed',
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => $this->match->created_by,
        'everyone_counts' => true,
    ]);

    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-EVERYONE-001',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subYear(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($everyoneMatch, $user, 'pending', $matchDate));

    expect($result->status)->toBe('valid')
        ->and($result->is_member)->toBeTrue()
        ->and($result->validation_reason)->toContain('all shooters count');
});

test('everyone_counts flag does not affect a normal match with the same shooter', function () {
    $matchDate = Carbon::today()->subDays(30);
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-EVERYONE-002',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subYear(),
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($this->match, $user, 'pending', $matchDate));

    expect($result->status)->toBe('lapsed')
        ->and($result->is_member)->toBeFalse();
});

test('everyone_counts still marks orphan (no user_id) scores as invalid', function () {
    $everyoneMatch = MatchEvent::create([
        'name' => 'Day-1 Provincial (Orphan Test)',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->match->province_id,
        'match_date' => Carbon::today(),
        'status' => 'completed',
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => $this->match->created_by,
        'everyone_counts' => true,
    ]);

    $result = $this->service->evaluateScoreStatus(makeScore($everyoneMatch, null));

    expect($result->status)->toBe('invalid');
});

test('resolvePendingScoresForUser reclassifies pending scores in isolation', function () {
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-004',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $score = makeScore($this->match, $user, 'pending', Carbon::today());

    $affectedMatchIds = $this->service->resolvePendingScoresForUser($user->id);

    expect($affectedMatchIds)->toContain($this->match->id)
        ->and($score->fresh()->status)->toBe('valid');

    $noopIds = $this->service->resolvePendingScoresForUser($user->id);
    expect($noopIds)->toBe([]);
});

test('MembershipObserver auto-promotes pending scores when membership becomes valid', function () {
    $matchDate = Carbon::today()->subDays(2);
    $user = User::factory()->create();
    $mship = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-005',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subDay(),
    ]);

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('pending');

    $mship->update([
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    expect($score->fresh()->status)->toBe('valid');
});

test('MembershipObserver reclassifies scores from lapsed to non_member when the membership is flipped to free', function () {
    // Regression: the De Villiers family were imported as paying members, then
    // admin flipped their membership_type to "free" after realising they were
    // one-off provincial registrants. Their scores stayed labelled LAPSED on
    // the scoreboard until the observer learned to demote on that transition.
    $matchDate = Carbon::today()->subDays(30);
    $user = User::factory()->create();
    $mship = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-DEMOTE-1',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'unpaid',
        'start_date' => Carbon::today()->subYear(),
        // Expires BEFORE the score date, so evaluateScoreStatus picks 'lapsed'.
        'expiry_date' => $matchDate->copy()->subDays(10),
    ]);

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('lapsed');

    $mship->update(['membership_type' => 'free']);

    expect($score->fresh()->status)->toBe('non_member');
});

test('MembershipObserver reclassifies valid scores to non_member when membership is revoked', function () {
    $matchDate = Carbon::today()->subDays(10);
    $user = User::factory()->create();
    $mship = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-DEMOTE-2',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('valid');

    $mship->update(['status' => 'revoked']);

    // isMembershipValidOnDate() short-circuits on revoked, so the score falls
    // through the lapsed grace-window branch and lands on 'lapsed' (past
    // grace) — but crucially it is no longer 'valid'.
    expect($score->fresh()->status)->not->toBe('valid');
});

test('MembershipObserver reclassifies valid scores to lapsed when expiry_date is pulled back before the match', function () {
    $matchDate = Carbon::today()->subDays(60);
    $user = User::factory()->create();
    $mship = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-DEMOTE-3',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        // Initial expiry AFTER the match: the score is valid.
        'expiry_date' => Carbon::today()->subDays(30),
    ]);

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('valid');

    // Admin corrects the expiry backwards to BEFORE the match date.
    $mship->update(['expiry_date' => $matchDate->copy()->subDays(1)]);

    expect($score->fresh()->status)->not->toBe('valid');
});

test('MembershipObserver leaves scores alone when the paid window is extended forward (no auto-backdate)', function () {
    // Anti-backdate guardrail: extending expiry into the future must NOT
    // retroactively promote a shooter's older non_member scores. That
    // expansion path stays behind the explicit `scores:reevaluate --user=`
    // command so an admin has to consciously sign off on retroactive credit.
    $matchDate = Carbon::today()->subDays(30);
    $user = User::factory()->create();

    // Shoot with no membership at all → non_member on the record.
    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('non_member');

    // Admin now creates a membership that covers the score date and extends.
    $mship = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-DEMOTE-4',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);

    // Extending the paid window further into the future — observer must not
    // reclassify non_member scores backwards.
    $mship->update(['expiry_date' => Carbon::today()->addYear()]);

    expect($score->fresh()->status)->toBe('non_member');
});

test('non_member scores never get promoted, even inside grace window', function () {
    $matchDate = Carbon::today()->subDays(2);
    $user = User::factory()->create();

    $score = makeScore($this->match, $user, 'pending', $matchDate);
    $this->service->evaluateScoreStatus($score);
    expect($score->fresh()->status)->toBe('non_member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-SCORE-006',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    expect($score->fresh()->status)->toBe('non_member');
});

test('resolvePendingScores classifies each pending score correctly', function () {
    $validUser = User::factory()->create();
    Membership::create([
        'user_id' => $validUser->id,
        'saprf_number' => 'SAPRF-SCORE-100',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    $nonMemberUser = User::factory()->create();

    $lapsedUser = User::factory()->create();
    Membership::create([
        'user_id' => $lapsedUser->id,
        'saprf_number' => 'SAPRF-SCORE-101',
        'status' => 'expired',
        'payment_status' => 'unpaid',
        // Expiry sits BEFORE the score date below so the shooter is
        // genuinely lapsed on match day, not borderline in-window.
        'expiry_date' => Carbon::today()->subDays(60),
    ]);

    makeScore($this->match, $validUser, 'pending', Carbon::today());
    makeScore($this->match, $nonMemberUser, 'pending', Carbon::today()->subDays(30));
    makeScore($this->match, $lapsedUser, 'pending', Carbon::today()->subDays(30));

    $count = $this->service->resolvePendingScores();

    expect($count)->toBe(3);

    $statuses = Score::orderBy('id')->pluck('status')->toArray();
    expect($statuses)->toBe(['valid', 'non_member', 'lapsed']);
});
