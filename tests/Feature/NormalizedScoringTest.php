<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;

it('calculates normalized scores correctly', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $division = Division::create(['slug' => 'open', 'name' => 'Open']);
    $user = User::factory()->create(['province_id' => $province->id]);
    Membership::create([
        'user_id' => $user->id, 'saprf_number' => 'TEST-001', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $match = MatchEvent::create([
        'name' => 'Test Match', 'match_type' => 'PRS', 'series_level' => 'national',
        'series' => 'PRS', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-03-15', 'status' => 'completed', 'created_by' => $user->id,
        'active_member_fee' => 500, 'published' => true,
    ]);

    $user2 = User::factory()->create(['province_id' => $province->id]);
    Membership::create([
        'user_id' => $user2->id, 'saprf_number' => 'TEST-002', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    Score::create([
        'match_id' => $match->id, 'shooter_name' => 'Top', 'user_id' => $user->id,
        'raw_score' => 49.0, 'placement' => 1, 'division_id' => $division->id,
        'status' => 'valid', 'match_date' => '2026-03-15',
    ]);

    Score::create([
        'match_id' => $match->id, 'shooter_name' => 'Second', 'user_id' => $user2->id,
        'raw_score' => 42.0, 'placement' => 2, 'division_id' => $division->id,
        'status' => 'valid', 'match_date' => '2026-03-15',
    ]);

    $service = app(StandingsCalculationService::class);
    $service->calculateMatchRankings($match);

    $topScore = Score::where('user_id', $user->id)->first();
    $secondScore = Score::where('user_id', $user2->id)->first();

    expect(round($topScore->normalized_score, 4))->toBe(100.0000);
    expect(round($secondScore->normalized_score, 4))->toBe(85.7143);
    expect($topScore->overall_rank)->toBe(1);
    expect($secondScore->overall_rank)->toBe(2);
});

it('does not change normalized score based on division or category', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $openDiv = Division::create(['slug' => 'open', 'name' => 'Open']);
    $prodDiv = Division::create(['slug' => 'production', 'name' => 'Production']);

    $match = MatchEvent::create([
        'name' => 'Test Match 2', 'match_type' => 'PRS', 'series_level' => 'national',
        'series' => 'PRS', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-04-15', 'status' => 'completed', 'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500, 'published' => true,
    ]);

    $openShooter = Score::create([
        'match_id' => $match->id, 'shooter_name' => 'Open Guy', 'user_id' => User::factory()->create()->id,
        'raw_score' => 45.0, 'division_id' => $openDiv->id, 'status' => 'valid', 'match_date' => '2026-04-15',
    ]);

    $prodShooter = Score::create([
        'match_id' => $match->id, 'shooter_name' => 'Prod Guy', 'user_id' => User::factory()->create()->id,
        'raw_score' => 45.0, 'division_id' => $prodDiv->id, 'status' => 'valid', 'match_date' => '2026-04-15',
    ]);

    $topScore = Score::create([
        'match_id' => $match->id, 'shooter_name' => 'Top', 'user_id' => User::factory()->create()->id,
        'raw_score' => 50.0, 'division_id' => $openDiv->id, 'status' => 'valid', 'match_date' => '2026-04-15',
    ]);

    app(StandingsCalculationService::class)->calculateMatchRankings($match);

    $openShooter->refresh();
    $prodShooter->refresh();

    // Same raw score = same normalized score regardless of division
    expect($openShooter->normalized_score)->toBe($prodShooter->normalized_score);
    expect(round($openShooter->normalized_score, 2))->toBe(90.00);
});

it('calculates division ranks and division-specific normalized scores', function () {
    $province = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $openDiv = Division::create(['slug' => 'open', 'name' => 'Open']);
    $prodDiv = Division::create(['slug' => 'production', 'name' => 'Production']);

    $match = MatchEvent::create([
        'name' => 'Rank Test', 'match_type' => 'PRS', 'series_level' => 'national',
        'series' => 'PRS', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-05-15', 'status' => 'completed', 'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500, 'published' => true,
    ]);

    // Open: 50, 40; Production: 45
    Score::create(['match_id' => $match->id, 'shooter_name' => 'A', 'user_id' => User::factory()->create()->id, 'raw_score' => 50, 'division_id' => $openDiv->id, 'status' => 'valid', 'match_date' => '2026-05-15']);
    Score::create(['match_id' => $match->id, 'shooter_name' => 'B', 'user_id' => User::factory()->create()->id, 'raw_score' => 45, 'division_id' => $prodDiv->id, 'status' => 'valid', 'match_date' => '2026-05-15']);
    Score::create(['match_id' => $match->id, 'shooter_name' => 'C', 'user_id' => User::factory()->create()->id, 'raw_score' => 40, 'division_id' => $openDiv->id, 'status' => 'valid', 'match_date' => '2026-05-15']);

    app(StandingsCalculationService::class)->calculateMatchRankings($match);

    $a = Score::where('shooter_name', 'A')->first();
    $b = Score::where('shooter_name', 'B')->first();
    $c = Score::where('shooter_name', 'C')->first();

    // Overall: A=1, B=2, C=3
    expect($a->overall_rank)->toBe(1);
    expect($b->overall_rank)->toBe(2);
    expect($c->overall_rank)->toBe(3);

    // Division ranks: A=1 in Open, B=1 in Production, C=2 in Open
    expect($a->division_rank)->toBe(1);
    expect($b->division_rank)->toBe(1);
    expect($c->division_rank)->toBe(2);

    // Overall normalized: relative to top overall (50)
    expect(round($a->normalized_score, 2))->toBe(100.00);
    expect(round($b->normalized_score, 2))->toBe(90.00);
    expect(round($c->normalized_score, 2))->toBe(80.00);

    // Division normalized: relative to the match-wide top raw (highest score
    // of the day), same baseline as normalized_score. Divisions no longer
    // renormalize against their own top — division_rank still ranks within
    // the division, but the percentage always mirrors the overall one.
    // A: 50/50=100, B: 45/50=90, C: 40/50=80.
    expect(round($a->division_normalized_score, 2))->toBe(100.00);
    expect(round($b->division_normalized_score, 2))->toBe(90.00);
    expect(round($c->division_normalized_score, 2))->toBe(80.00);
});

it('calculates division-specific normalized scores for demographic divisions', function () {
    // Under the flat-division model, Ladies is its own division. Confirm that
    // per-division normalization treats Ladies exactly like any other division.
    $province = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open']);
    $ladies = Division::create(['slug' => 'ladies', 'name' => 'Ladies']);

    $match = MatchEvent::create([
        'name' => 'Div Test', 'match_type' => 'PRS', 'series_level' => 'national',
        'series' => 'PRS', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-06-15', 'status' => 'completed', 'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500, 'published' => true,
    ]);

    // 40 (Ladies), 30 (Ladies), 50 (Open) — top overall is 50.
    $scoreA = Score::create(['match_id' => $match->id, 'shooter_name' => 'Lady A', 'user_id' => User::factory()->create()->id, 'raw_score' => 40, 'division_id' => $ladies->id, 'status' => 'valid', 'match_date' => '2026-06-15']);
    $scoreB = Score::create(['match_id' => $match->id, 'shooter_name' => 'Lady B', 'user_id' => User::factory()->create()->id, 'raw_score' => 30, 'division_id' => $ladies->id, 'status' => 'valid', 'match_date' => '2026-06-15']);
    Score::create(['match_id' => $match->id, 'shooter_name' => 'Man A', 'user_id' => User::factory()->create()->id, 'raw_score' => 50, 'division_id' => $open->id, 'status' => 'valid', 'match_date' => '2026-06-15']);

    app(StandingsCalculationService::class)->calculateMatchRankings($match);

    $scoreA->refresh();
    $scoreB->refresh();

    expect(round($scoreA->normalized_score, 2))->toBe(80.00);   // 40/50
    expect(round($scoreB->normalized_score, 2))->toBe(60.00);   // 30/50

    // Division normalized mirrors overall (highest score of the day = 50).
    // division_rank still orders shooters within Ladies.
    expect(round($scoreA->division_normalized_score, 2))->toBe(80.00);  // 40/50
    expect(round($scoreB->division_normalized_score, 2))->toBe(60.00);  // 30/50
    expect($scoreA->division_rank)->toBe(1);
    expect($scoreB->division_rank)->toBe(2);
});
