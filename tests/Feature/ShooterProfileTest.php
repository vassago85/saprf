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
