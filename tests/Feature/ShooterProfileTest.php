<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;

/**
 * The public shooter profile must show EVERY match the shooter attended across
 * both PRS and PR22 — including matches shot as a non-member (which are visible
 * but do not count toward the season log).
 */

function makeMatch(string $name, string $type, string $level, string $date): MatchEvent
{
    return MatchEvent::create([
        'name' => $name, 'match_type' => $type, 'series' => $type, 'series_level' => $level,
        'season' => '2026', 'match_date' => $date, 'status' => 'completed',
        'created_by' => User::factory()->create()->id, 'published' => true,
    ]);
}

it('shows matches from both PRS and PR22, including non-member matches', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    $shooter = User::factory()->create(['name' => 'Jane Marksman', 'province_id' => $province->id]);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'T-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $prs = makeMatch('PRS GP National', 'PRS', 'national', '2026-02-10');
    $pr22 = makeMatch('PR22 WC Provincial', 'PR22', 'provincial', '2026-03-10');

    // PRS score counts (valid). PR22 score shot as a non-member (visible, excluded).
    Score::create([
        'match_id' => $prs->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
        'division_id' => $open->id, 'raw_score' => 90, 'normalized_score' => 100, 'overall_rank' => 1,
        'status' => 'valid', 'is_member' => true, 'match_date' => $prs->match_date,
    ]);
    Score::create([
        'match_id' => $pr22->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
        'division_id' => $open->id, 'raw_score' => 70, 'normalized_score' => 80, 'overall_rank' => 5,
        'status' => 'non_member', 'is_member' => false, 'match_date' => $pr22->match_date,
    ]);

    $response = $this->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    $response->assertSee('PRS GP National');
    $response->assertSee('PR22 WC Provincial');
    $response->assertSee('NON-MEMBER');   // badge on the PR22 row
    $response->assertSee('COUNTS');        // badge on the PRS row
});

it('renders even when the shooter attended only non-member matches (no ranking)', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    $shooter = User::factory()->create(['name' => 'No Rank Nick']);

    $pr22 = makeMatch('PR22 Casual', 'PR22', 'provincial', '2026-04-01');
    Score::create([
        'match_id' => $pr22->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
        'division_id' => $open->id, 'raw_score' => 40, 'normalized_score' => 55, 'overall_rank' => 9,
        'status' => 'non_member', 'is_member' => false, 'match_date' => $pr22->match_date,
    ]);

    $response = $this->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    $response->assertSee('PR22 Casual');
    $response->assertSee('not ranked');
});

it('marks PRS counted matches with contributed points and shows others as dropped', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    \App\Models\QualificationRule::create([
        'series' => 'PRS', 'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3, 'total_qualifying_matches' => 4, 'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create(['name' => 'PRS Regular']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'T-100', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // Four PRS nationals: 100, 90, 80, 70. The 70 is valid but dropped
    // (best-3 keeps 100/90/80). Use unique dates so all four rows persist.
    $service = app(\App\Services\StandingsCalculationService::class);
    foreach ([['A', 100], ['B', 90], ['C', 80], ['D', 70]] as $i => [$suffix, $raw]) {
        $match = makeMatch('PRS Regular '.$suffix, 'PRS', 'national', '2026-0'.($i + 2).'-01');
        $top = User::factory()->create();
        Score::create([
            'match_id' => $match->id, 'user_id' => $top->id, 'shooter_name' => $top->name,
            'division_id' => $open->id, 'raw_score' => 100,
            'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
        ]);
        Score::create([
            'match_id' => $match->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
            'division_id' => $open->id, 'raw_score' => (float) $raw,
            'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
        ]);
        $service->recalculateForMatch($match);
    }

    $response = $this->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    // The 4th-best national is valid but did NOT count — must render as DROPPED.
    $response->assertSee('DROPPED');
    // Counted matches render their contributed points prefixed with '+'.
    $response->assertSee('+100.00');
    $response->assertSee('+90.00');
    $response->assertSee('+80.00');
    // PRS uses the annual-log breakdown, not the weighted-pools ones. The
    // PR22-only breakdown labels must be absent so nothing implies this
    // shooter has a PR22 ranking mixed into their PRS ranking.
    $response->assertSee('Annual Log Breakdown', false);
    $response->assertDontSee('National Standing Breakdown', false);
    $response->assertDontSee('Provincial Standing Breakdown', false);
    $response->assertDontSee('Prov. Pts', false);
});

it('renders PR22 with separate National and Provincial columns and does not mix PRS into the PR22 card', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    \App\Models\QualificationRule::create([
        'series' => 'PR22', 'season' => '2026',
        'scoring_mode' => 'weighted_pools',
        'best_of_count' => 3, 'total_qualifying_matches' => 3, 'min_out_of_province_matches' => 0,
        'provincial_pool_best_of' => 3, 'provincial_pool_weight_pct' => 30,
        'national_pool_best_of' => 2, 'national_pool_weight_pct' => 40,
        'champs_pool_best_of' => 1, 'champs_pool_weight_pct' => 30,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create(['name' => 'PR22 Only', 'province_id' => $province->id]);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'T-200', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // One PR22 provincial match — feeds both the national (via provincial pool)
    // and the provincial standing.
    $service = app(\App\Services\StandingsCalculationService::class);
    $match = MatchEvent::create([
        'name' => 'PR22 GP Provincial', 'match_type' => 'PR22', 'series' => 'PR22',
        'series_level' => 'provincial', 'season' => '2026', 'province_id' => $province->id,
        'match_date' => '2026-03-15', 'status' => 'completed',
        'created_by' => User::factory()->create()->id, 'active_member_fee' => 500, 'published' => true,
    ]);
    $top = User::factory()->create();
    Score::create([
        'match_id' => $match->id, 'user_id' => $top->id, 'shooter_name' => $top->name,
        'division_id' => $open->id, 'raw_score' => 100,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);
    Score::create([
        'match_id' => $match->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
        'division_id' => $open->id, 'raw_score' => 90,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);
    $service->recalculateForMatch($match);

    $response = $this->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    // PR22 card must explicitly separate national and provincial standings.
    $response->assertSee('National Standing Breakdown', false);
    $response->assertSee('Provincial Standing Breakdown', false);
    // Both contribution columns must be present in the PR22 match table.
    $response->assertSee('Nat. Pts', false);
    $response->assertSee('Prov. Pts', false);
    // The PRS-specific breakdown label must NOT appear on a PR22-only page
    // (a bare assertDontSee('PRS') would collide with the shared public
    // footer copy that mentions both series).
    $response->assertDontSee('Annual Log Breakdown', false);
});
