<?php

use App\Models\Division;
use App\Models\Membership;
use App\Models\NationalTeam;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

/**
 * The redesigned public /sa-team page. These tests exercise the big
 * behavioural changes from the hard-coded "list every appearance"
 * version: forced public layout, year deep links, correct stat counts,
 * officials excluded from division cards, and captain/manager tiles.
 */

it('/sa-team returns 200 for guests and renders the public shell (no sidebar / user menu)', function () {
    NationalTeam::create([
        'year' => 2026,
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'discipline' => 'centrefire',
    ]);

    $response = $this->get('/sa-team');

    $response->assertOk();
    $response->assertSee('The Proteas');
    // The public nav highlights the SA Team link …
    $response->assertSee('SA Team');
    // … but the authenticated app sidebar's "View as" switcher must NOT render.
    $response->assertDontSee('View as');
});

it('/sa-team renders the public shell for authed users (forcePublic bypasses the app layout)', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');

    $response = $this->actingAs($viewer)->get('/sa-team');

    $response->assertOk();
    $response->assertDontSee('View as');
    // The signed-in user's "Dashboard" link appears in the public nav.
    $response->assertSee('Dashboard');
});

it('deep-links to /sa-team/{year} render that year even when it is not the newest', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $u2024 = User::factory()->create(['name' => 'Veteran Valeria']);
    $u2026 = User::factory()->create(['name' => 'Rookie Rachel']);
    foreach ([['u' => $u2024, 'n' => 'VV1'], ['u' => $u2026, 'n' => 'RR1']] as $m) {
        Membership::create([
            'user_id' => $m['u']->id, 'saprf_number' => $m['n'], 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }
    NationalTeamAppearance::create([
        'user_id' => $u2024->id, 'year' => 2024,
        'division_id' => $open->id, 'division_label' => 'Open',
        'championship_name' => 'Worlds 2024',
        'awarded_colours' => true, 'appeared_at' => '2024-07-15',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $u2026->id, 'year' => 2026,
        'division_id' => $open->id, 'division_label' => 'Open',
        'championship_name' => 'Worlds 2026',
        'awarded_colours' => true, 'appeared_at' => '2026-09-23',
    ]);

    // Default — newest first. The year chip for 2026 is "active".
    $html2026 = $this->get('/sa-team')->assertOk()->getContent();
    expect($html2026)->toContain('Rookie Rachel');
    // The active chip for 2026 is the current URL (no trailing /year).
    // 2024's chip links to /sa-team/2024.
    expect($html2026)->toContain('href="'.url('/sa-team/2024').'"');

    // Deep link to 2024 — now Veteran Valeria appears in the roster block
    // and the active chip is 2024.
    $html2024 = $this->get('/sa-team/2024')->assertOk()->getContent();
    expect($html2024)->toContain('Veteran Valeria');
    expect($html2024)->toContain('href="'.url('/sa-team/2026').'"');
});

it('hero stat tiles count distinct Proteas, total appearances and colours separately', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    // 2 shooters, 3 appearances between them (one has two years), 2 colours.
    $warren = User::factory()->create(['name' => 'Warren']);
    $leon = User::factory()->create(['name' => 'Leon']);

    foreach ([$warren, $leon] as $u) {
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => (string) $u->id, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }

    // Warren: 2024 (colours) + 2026 (no new colours — already has them)
    NationalTeamAppearance::create([
        'user_id' => $warren->id, 'year' => 2024,
        'division_id' => $open->id,
        'championship_name' => 'Worlds 2024', 'awarded_colours' => true,
        'appeared_at' => '2024-07-15',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $warren->id, 'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => false,
        'appeared_at' => '2026-09-23',
    ]);
    // Leon: 2026 only, colours.
    NationalTeamAppearance::create([
        'user_id' => $leon->id, 'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->get('/sa-team');

    $response->assertOk();
    $html = $response->getContent();

    // Each tile has a tabular-nums number near the stat label — assert the
    // triple appears in order (2 Proteas, 3 Appearances, 2 Colours).
    expect($html)->toMatch('/>2<\/dt>\s*<dd[^>]*>\s*Proteas/is');
    expect($html)->toMatch('/>3<\/dt>\s*<dd[^>]*>\s*Appearances/is');
    expect($html)->toMatch('/>2<\/dt>\s*<dd[^>]*>\s*Colours/is');
});

it('officials (manager) are excluded from division cards and the squad size count', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $shooter = User::factory()->create(['name' => 'Shooter Steve']);
    $captain = User::factory()->create(['name' => 'Captain Kim']);
    $manager = User::factory()->create(['name' => 'Manager Max']);
    foreach ([$shooter, $captain, $manager] as $u) {
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => (string) $u->id, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }

    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $captain->id, 'year' => 2026,
        'division_id' => $open->id, 'role' => NationalTeamAppearance::ROLE_CAPTAIN,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $manager->id, 'year' => 2026,
        'division_label' => 'Team Manager', 'role' => NationalTeamAppearance::ROLE_MANAGER,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => false,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->get('/sa-team');
    $response->assertOk();
    $html = $response->getContent();

    // Manager renders in the "Team manager" header tile, NOT inside a division ul/li.
    $response->assertSee('Team manager');
    $response->assertSee('Manager Max');
    // Captain + shooter both in the Open card.
    $response->assertSee('Captain Kim');
    $response->assertSee('Shooter Steve');
    $response->assertSee('Captain');  // the per-row Captain pill

    // Squad size is 2 (shooter + captain), not 3.
    expect($html)->toContain('2 shooters');
});

it('captain and manager tiles render only when those roles exist on the team', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $shooter = User::factory()->create(['name' => 'Lonely Larry']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'LL1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->get('/sa-team');
    $response->assertOk();
    // Squad tile always renders, but captain + manager tiles only when
    // those roles exist. This team has none of those.
    $response->assertDontSee('Team captain');
    $response->assertDontSee('Team manager');
    $response->assertSee('Squad');
});
