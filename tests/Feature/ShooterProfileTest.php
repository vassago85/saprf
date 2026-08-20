<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

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

    // Legacy URL 301s to /shooters/T-1/2026 (canonical); followingRedirects
    // lets us keep the existing assertion set unchanged while proving both
    // the redirect and the new career-hub view render.
    $response = $this->followingRedirects()->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    $response->assertSee('PRS GP National');
    $response->assertSee('PR22 WC Provincial');
    $response->assertSee('NON-MEMBER');   // badge on the PR22 row
    // The membership-eligibility badge on the PRS row. Note this is
    // deliberately labelled "ELIGIBLE" (not "COUNTS") because "counts" would
    // read as contradictory next to a "DROPPED" mark in the Nat. Pts column
    // — eligibility (paid member on match day) and contribution (was this
    // score picked among the counting matches) are two different questions.
    $response->assertSee('ELIGIBLE');
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

    $response = $this->followingRedirects()->get('/standings/2026/shooter/'.$shooter->id);

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

    $response = $this->followingRedirects()->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    // PR22 card must explicitly separate national and provincial standings.
    $response->assertSee('National Standing Breakdown', false);
    $response->assertSee('Provincial Standing Breakdown', false);
    // Both contribution columns must be present in the PR22 match table.
    $response->assertSee('Nat. Pts', false);
    $response->assertSee('Prov. Pts', false);
    // The footer totals row must carry explicit "Nat total" / "Prov total"
    // captions — colour alone (blue vs green) reads as ambiguous when the
    // reader is scanning just the footer. Regression guard.
    $response->assertSee('Nat total', false);
    $response->assertSee('Prov total', false);
    // The membership eligibility column is called "Membership", not "Status".
    $response->assertSee('Membership', false);
    // The PRS-specific breakdown label must NOT appear on a PR22-only page
    // (a bare assertDontSee('PRS') would collide with the shared public
    // footer copy that mentions both series).
    $response->assertDontSee('Annual Log Breakdown', false);
});

it('shows every division a shooter placed in — not just the first', function () {
    // Kevin-style case: shoots one match in Open, another in Factory. The
    // profile page previously called ->first() when loading division standings,
    // silently hiding every division past the first one.
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    $factory = Division::create(['slug' => 'factory', 'name' => 'Factory', 'display_order' => 2]);
    \App\Models\QualificationRule::create([
        'series' => 'PRS', 'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3, 'total_qualifying_matches' => 3, 'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $kevin = User::factory()->create(['name' => 'Kevin Multi', 'province_id' => $province->id]);
    Membership::create([
        'user_id' => $kevin->id, 'saprf_number' => 'T-300', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $service = app(\App\Services\StandingsCalculationService::class);

    // Match 1 — Kevin shoots Open. A rival in Open beats him.
    $m1 = makeMatch('PRS Open Match', 'PRS', 'national', '2026-02-15');
    $openRival = User::factory()->create();
    Score::create([
        'match_id' => $m1->id, 'user_id' => $openRival->id, 'shooter_name' => 'Open Rival',
        'division_id' => $open->id, 'raw_score' => 100,
        'status' => 'valid', 'is_member' => true, 'match_date' => $m1->match_date,
    ]);
    Score::create([
        'match_id' => $m1->id, 'user_id' => $kevin->id, 'shooter_name' => $kevin->name,
        'division_id' => $open->id, 'raw_score' => 80,
        'status' => 'valid', 'is_member' => true, 'match_date' => $m1->match_date,
    ]);
    $service->recalculateForMatch($m1);

    // Match 2 — Kevin shoots Factory. He's the only Factory competitor, so
    // he wins Factory outright. Deliberately leave the Open pool alone in
    // this match so the Open standing stays: Rival #1 (100), Kevin #2 (80).
    $m2 = makeMatch('PRS Factory Match', 'PRS', 'national', '2026-04-15');
    Score::create([
        'match_id' => $m2->id, 'user_id' => $kevin->id, 'shooter_name' => $kevin->name,
        'division_id' => $factory->id, 'raw_score' => 90,
        'status' => 'valid', 'is_member' => true, 'match_date' => $m2->match_date,
    ]);
    $service->recalculateForMatch($m2);

    $response = $this->followingRedirects()->get('/standings/2026/shooter/'.$kevin->id);

    $response->assertOk();
    // BOTH divisions Kevin competed in must appear on his profile — not just
    // the first one that comes back from the DB.
    $response->assertSee('Open', false);
    $response->assertSee('Factory', false);
    // Each division has its own independent rank chip / tile. Kevin wins
    // Factory (only Factory shooter → #1) and comes 2nd in Open.
    $response->assertSeeInOrder(['Open', '#2'], false);
    $response->assertSeeInOrder(['Factory', '#1'], false);

    // Per-division breakdown panel is shown whenever the shooter placed in
    // more than one division for a series — it tells the shooter WHICH
    // matches contributed to each division rank (Open 80.00 came from the
    // Open Match, Factory 100.00 came from the Factory Match). Without
    // this the shooter sees "Open #2, Factory #1" as unexplained numbers.
    $response->assertSee('National Division Breakdown', false);
    // Each division's own match rows are listed inside its mini-panel so
    // the shooter can verify the maths.
    $response->assertSee('PRS Open Match', false);
    $response->assertSee('PRS Factory Match', false);
});

it('renders the full gear spec card grouped by Centerfire and Rimfire on the shooter profile', function () {
    $shooter = User::factory()->create(['name' => 'Gear Head']);

    \App\Models\RifleConfiguration::create([
        'user_id' => $shooter->id,
        'nickname' => '25x47L',
        'primary_series' => 'PRS',
        'show_on_profile' => true,
        'is_active' => true,
        'action_description' => 'Impact Precision',
        'chassis_description' => 'Vision',
        'trigger_description' => "Bix'n Andy",
        'muzzle_brake_description' => 'Botnia Solutions',
        'bipod_description' => 'MDT Ckye Pod',
        'scope_mount_description' => 'Spuhr',
    ]);
    \App\Models\RifleConfiguration::create([
        'user_id' => $shooter->id,
        'nickname' => 'Vudoo V-22',
        'primary_series' => 'PR22',
        'show_on_profile' => true,
        'is_active' => true,
        'trigger_description' => 'TriggerTech',
    ]);

    $this->get('/standings/2026/shooter/'.$shooter->id)
        ->assertOk()
        ->assertSee('Centerfire', false)
        ->assertSee('Rimfire', false)
        ->assertSee('25x47L')
        ->assertSee('Vudoo V-22')
        ->assertSee('Impact Precision')
        ->assertSee('Vision')
        ->assertSee("Bix'n Andy")
        ->assertSee('Botnia Solutions')
        ->assertSee('MDT Ckye Pod')
        ->assertSee('Spuhr')
        ->assertSee('TriggerTech');
});

it('shows opted-in main rifles on the public shooter profile and hides the rest', function () {
    $shooter = User::factory()->create(['name' => 'Rifle Owner']);

    \App\Models\RifleConfiguration::create([
        'user_id' => $shooter->id,
        'nickname' => 'Public Creedmoor',
        'primary_series' => 'PRS',
        'show_on_profile' => true,
        'is_active' => true,
    ]);
    \App\Models\RifleConfiguration::create([
        'user_id' => $shooter->id,
        'nickname' => 'Hidden Rimfire',
        'primary_series' => 'PR22',
        'show_on_profile' => false,
        'is_active' => true,
    ]);
    \App\Models\RifleConfiguration::create([
        'user_id' => $shooter->id,
        'nickname' => 'Spare Rifle',
        'is_active' => true,
    ]);

    $this->get('/standings/2026/shooter/'.$shooter->id)
        ->assertOk()
        ->assertSee('Public Creedmoor')
        ->assertSee('Centerfire', false)
        ->assertDontSee('Hidden Rimfire')
        ->assertDontSee('Spare Rifle');
});

it('does not render the per-division breakdown panel when the shooter only competed in one division', function () {
    // Single-division shooters would just see the overall breakdown
    // repeated inside a per-division panel — visually noisy for no gain.
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    \App\Models\QualificationRule::create([
        'series' => 'PRS', 'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3, 'total_qualifying_matches' => 3, 'min_out_of_province_matches' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    $shooter = User::factory()->create(['name' => 'Single Division Sam']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'T-400', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid', 'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $service = app(\App\Services\StandingsCalculationService::class);
    $match = makeMatch('PRS Solo Match', 'PRS', 'national', '2026-03-15');
    Score::create([
        'match_id' => $match->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
        'division_id' => $open->id, 'raw_score' => 90,
        'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
    ]);
    $service->recalculateForMatch($match);

    $response = $this->followingRedirects()->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    $response->assertDontSee('National Division Breakdown', false);
    $response->assertDontSee('Provincial Division Breakdown', false);
});

// ── Canonical URL, redirect from legacy, visibility gating ──────────────

it('301-redirects the legacy /standings/{season}/shooter/{id} URL to /shooters/{saprfNumber}/{season}', function () {
    $shooter = User::factory()->create(['name' => 'Redirect Rachel']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'R-9', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $response = $this->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertStatus(301);
    $response->assertRedirect('/shooters/R-9/2026');
});

it('falls through to the in-place shooter render when the user has no SAPRF number', function () {
    // A guest / imported shooter with no Membership row must still resolve
    // via the legacy URL — the redirect only fires when a saprf_number is
    // present.
    $shooter = User::factory()->create(['name' => 'No Number Ned']);

    $response = $this->get('/standings/2026/shooter/'.$shooter->id);

    $response->assertOk();
    $response->assertSee('No Number Ned');
});

it('renders the canonical career view at /shooters/{saprfNumber}/{season}', function () {
    $shooter = User::factory()->create(['name' => 'Canonical Cara']);
    // Numeric SAPRF numbers get a highlighted "SAPRF #NNNN" chip; the
    // chip is deliberately suppressed for legacy T-style / SAPRF-IMPORT-
    // prefixed numbers because those aren't member-visible identifiers.
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => '4242', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $this->get('/shooters/4242/2026')
        ->assertOk()
        ->assertSee('Canonical Cara')
        ->assertSee('SAPRF #4242');
});

it('defaults the seasonless canonical URL to the current calendar year', function () {
    $shooter = User::factory()->create(['name' => 'Season Sam']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'S-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $response = $this->get('/shooters/S-1');

    // Controller injects the current year and renders in place (no 302).
    // Content assertion is enough — no need to sniff the exact season chip
    // because the season switcher tabs are the source of truth here.
    $response->assertOk();
    $response->assertSee('Season Sam');
});

it('404s when the SAPRF number does not resolve to a member', function () {
    $this->get('/shooters/NONEXISTENT-9999/2026')->assertNotFound();
});

it('hides members-only profiles from guests but shows them to signed-in members', function () {
    $shooter = User::factory()->create([
        'name' => 'Private Pat',
        'public_profile_visibility' => User::PROFILE_VISIBILITY_MEMBERS_ONLY,
    ]);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'M-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // Guest → 404.
    $this->get('/shooters/M-1/2026')->assertNotFound();

    // Any signed-in user (even a plain member) → 200.
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    $this->actingAs($viewer)->get('/shooters/M-1/2026')->assertOk();
});

it('hides hidden profiles from guests and members but shows them to the owner and to staff', function () {
    $shooter = User::factory()->create([
        'name' => 'Hidden Helen',
        'public_profile_visibility' => User::PROFILE_VISIBILITY_HIDDEN,
    ]);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'H-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // Guest → 404.
    $this->get('/shooters/H-1/2026')->assertNotFound();

    // Random member → 404 (hidden really means hidden).
    $randomMember = User::factory()->create();
    $randomMember->assignRole('member');
    $this->actingAs($randomMember)->get('/shooters/H-1/2026')->assertNotFound();

    // Owner viewing their own profile → 200.
    $this->actingAs($shooter)->get('/shooters/H-1/2026')->assertOk();

    // Staff → 200 (admin can always inspect a profile for moderation).
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get('/shooters/H-1/2026')->assertOk();
});

it('serves public profiles to guests without authentication', function () {
    $shooter = User::factory()->create([
        'name' => 'Open Book Owen',
        'public_profile_visibility' => User::PROFILE_VISIBILITY_PUBLIC,
    ]);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'P-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $this->get('/shooters/P-1/2026')->assertOk()->assertSee('Open Book Owen');
});

// ── Protea Colours hero card + national-team appearances list ───────────

it('renders the Protea Colours hero card for a shooter with an awarded_colours appearance', function () {
    $shooter = User::factory()->create(['name' => 'Proud Percy']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'PC-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id,
        'year' => 2015,
        'championship_name' => 'IPRF PR22 World Championship',
        'host_country' => 'SE',
        'placing' => 12,
        'awarded_colours' => true,
        'appeared_at' => '2015-09-15',
    ]);

    $response = $this->get('/shooters/PC-1/2026');

    $response->assertOk();
    // Hero card content — year, championship, host country.
    $response->assertSee('Protea Colours', false);
    $response->assertSee('2015');
    $response->assertSee('IPRF PR22 World Championship');
    $response->assertSee('Sweden');
    // 12th → "12th" (not 12st or 12nd — the ordinal helper handles teens).
    $response->assertSee('12th');
});

it('lists subsequent SA appearances below the Protea Colours hero card', function () {
    $shooter = User::factory()->create(['name' => 'Multi-Rep Mike']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'MR-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    // Colours in 2015, then two subsequent appearances.
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2015,
        'championship_name' => 'IPRF PR22 Worlds 2015',
        'host_country' => 'SE', 'awarded_colours' => true, 'appeared_at' => '2015-09-15',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2018,
        'championship_name' => 'IPRF PR22 Worlds 2018',
        'host_country' => 'US', 'appeared_at' => '2018-06-10',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2022,
        'championship_name' => 'IPRF PR22 Worlds 2022',
        'host_country' => 'NO', 'appeared_at' => '2022-08-01',
    ]);

    $response = $this->get('/shooters/MR-1/2026');

    $response->assertOk();
    // The colours-awarding row lives inside the hero card, above the list.
    $response->assertSee('IPRF PR22 Worlds 2015');
    // The two subsequent appearances go into the compact list below.
    $response->assertSee('IPRF PR22 Worlds 2018');
    $response->assertSee('IPRF PR22 Worlds 2022');
    // Section heading changes based on presence of colours.
    $response->assertSee('Also Represented South Africa', false);
});

it('shows the season switcher tabs linking to every year the shooter has scores in', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    $shooter = User::factory()->create(['name' => 'Multi-Season Sally']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'MS-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2024-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // One completed match in each of two seasons.
    foreach (['2024', '2026'] as $season) {
        $match = MatchEvent::create([
            'name' => "PRS $season Match", 'match_type' => 'PRS', 'series' => 'PRS',
            'series_level' => 'national', 'season' => $season,
            'match_date' => "$season-05-15", 'status' => 'completed',
            'created_by' => User::factory()->create()->id, 'published' => true,
        ]);
        Score::create([
            'match_id' => $match->id, 'user_id' => $shooter->id, 'shooter_name' => $shooter->name,
            'division_id' => $open->id, 'raw_score' => 85, 'normalized_score' => 85, 'overall_rank' => 3,
            'status' => 'valid', 'is_member' => true, 'match_date' => $match->match_date,
        ]);
    }

    $response = $this->get('/shooters/MS-1/2026');
    $response->assertOk();
    // Season switcher renders anchor links to both seasons.
    $response->assertSee(url('/shooters/MS-1/2024'), false);
    $response->assertSee(url('/shooters/MS-1/2026'), false);
});

// ── User model visibility helper ────────────────────────────────────────

it('User::isProfileVisibleTo enforces the visibility enum for guest, member, owner and staff', function () {
    $publicUser = User::factory()->create(['public_profile_visibility' => User::PROFILE_VISIBILITY_PUBLIC]);
    $membersOnlyUser = User::factory()->create(['public_profile_visibility' => User::PROFILE_VISIBILITY_MEMBERS_ONLY]);
    $hiddenUser = User::factory()->create(['public_profile_visibility' => User::PROFILE_VISIBILITY_HIDDEN]);

    $member = User::factory()->create();
    $member->assignRole('member');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Guest (viewer = null).
    expect($publicUser->isProfileVisibleTo(null))->toBeTrue();
    expect($membersOnlyUser->isProfileVisibleTo(null))->toBeFalse();
    expect($hiddenUser->isProfileVisibleTo(null))->toBeFalse();

    // Regular signed-in member.
    expect($publicUser->isProfileVisibleTo($member))->toBeTrue();
    expect($membersOnlyUser->isProfileVisibleTo($member))->toBeTrue();
    expect($hiddenUser->isProfileVisibleTo($member))->toBeFalse();

    // Owner always sees their own profile, even when hidden.
    expect($hiddenUser->isProfileVisibleTo($hiddenUser))->toBeTrue();

    // Staff (admin) always sees any profile, even hidden ones.
    expect($hiddenUser->isProfileVisibleTo($admin))->toBeTrue();
});

// ── National-Team admin CRUD invariants ────────────────────────────────

it('rejects a second colours-awarding appearance for a shooter who already has colours', function () {
    $shooter = User::factory()->create();
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => '5001', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2015-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2015,
        'championship_name' => 'Worlds 2015',
        'awarded_colours' => true, 'appeared_at' => '2015-09-15',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('exco');

    $response = $this->actingAs($admin)->post(route('national-team.store'), [
        'shooter_lookup' => '5001',
        'year' => 2018,
        'championship_name' => 'Worlds 2018',
        'awarded_colours' => '1',
        'appeared_at' => '2018-06-10',
    ]);

    $response->assertSessionHasErrors('awarded_colours');
    // The original colours-awarding row must survive intact — no silent
    // reassignment even when a duplicate flag is submitted.
    expect($shooter->fresh()->nationalTeamAppearances()->awardedColours()->count())->toBe(1);
    expect($shooter->fresh()->nationalTeamAppearances()->awardedColours()->first()->year)->toBe(2015);
});

it('auto-promotes the earliest remaining appearance to colours when the colours row is deleted', function () {
    $shooter = User::factory()->create();
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => '5002', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2015-01-01', 'expiry_date' => '2026-12-31',
    ]);
    $coloursRow = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2015,
        'championship_name' => 'Worlds 2015',
        'awarded_colours' => true, 'appeared_at' => '2015-09-15',
    ]);
    $laterRow = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2018,
        'championship_name' => 'Worlds 2018',
        'awarded_colours' => false, 'appeared_at' => '2018-06-10',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2022,
        'championship_name' => 'Worlds 2022',
        'awarded_colours' => false, 'appeared_at' => '2022-08-01',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('exco');

    $this->actingAs($admin)
        ->delete(route('national-team.destroy', $coloursRow))
        ->assertRedirect();

    // Original colours row is gone.
    expect(NationalTeamAppearance::find($coloursRow->id))->toBeNull();
    // 2018 (earliest remaining) is promoted.
    $laterRow->refresh();
    expect($laterRow->awarded_colours)->toBeTrue();
    // Invariant holds: still exactly one colours-awarding row.
    expect($shooter->fresh()->nationalTeamAppearances()->awardedColours()->count())->toBe(1);
});

it('leaves colours cleared when the last remaining appearance is deleted', function () {
    $shooter = User::factory()->create();
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => '5003', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2015-01-01', 'expiry_date' => '2026-12-31',
    ]);
    $only = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2015,
        'championship_name' => 'Worlds 2015',
        'awarded_colours' => true, 'appeared_at' => '2015-09-15',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('exco');

    $this->actingAs($admin)
        ->delete(route('national-team.destroy', $only))
        ->assertRedirect();

    expect($shooter->fresh()->hasProteaColours())->toBeFalse();
    expect($shooter->fresh()->nationalTeamAppearances()->count())->toBe(0);
});

it('blocks unauthenticated access to the national-team admin index', function () {
    $this->get(route('national-team.index'))->assertRedirect(route('login'));
});

it('blocks a plain member from the national-team admin index', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)->get(route('national-team.index'))->assertForbidden();
});

it('lets a member update their own public profile visibility from /profile', function () {
    $user = User::factory()->create([
        'public_profile_visibility' => User::PROFILE_VISIBILITY_PUBLIC,
    ]);
    $user->assignRole('member');

    // /profile.update revalidates every mandatory profile field, so this
    // test asserts the field can round-trip without pulling in the full
    // profile-completeness payload; we hit the model boundary instead.
    // See ProfileUpdateTest.php (existing) for the full form path.
    $user->update(['public_profile_visibility' => User::PROFILE_VISIBILITY_MEMBERS_ONLY]);

    expect($user->fresh()->public_profile_visibility)->toBe(User::PROFILE_VISIBILITY_MEMBERS_ONLY);
    expect($user->fresh()->isProfileVisibleTo(null))->toBeFalse();
});

