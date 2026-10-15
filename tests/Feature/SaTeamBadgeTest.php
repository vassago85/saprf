<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

/**
 * The "SA 2026" badge is a small gold/green chip rendered next to shooter
 * names on every scoreboard surface and at the top of the public profile.
 * It is driven entirely by rows in `national_team_appearances` — one chip
 * per distinct year, capped at 2 visible plus a "+N" overflow pill.
 */

// ── Component ───────────────────────────────────────────────────────────

it('x-sa-team-badge renders nothing when the shooter has no SA appearances', function () {
    $html = view('components.sa-team-badge', ['years' => []])->render();

    expect(trim($html))->toBe('');
});

it('x-sa-team-badge renders one chip per year with the latest year first', function () {
    $html = view('components.sa-team-badge', [
        'years' => [2024, 2026],
        'tooltip' => 'Open · 2026 IPRF World Championship',
    ])->render();

    // Newest first.
    expect($html)->toContain('SA 2026');
    expect($html)->toContain('SA 2024');
    // Tooltip surfaces as both title attribute and ARIA group label.
    expect($html)->toContain('Open · 2026 IPRF World Championship');
    expect($html)->toContain('Represented South Africa in 2026, 2024');
    // No overflow pill when the list fits within the maxVisible budget.
    expect($html)->not->toContain('+1');
});

it('x-sa-team-badge collapses extra years into a +N overflow pill', function () {
    $html = view('components.sa-team-badge', [
        'years' => [2026, 2024, 2022, 2018],
    ])->render();

    // Only the 2 newest render as full chips.
    expect($html)->toContain('SA 2026');
    expect($html)->toContain('SA 2024');
    // The 2 older years collapse into a single overflow pill.
    expect($html)->toContain('+2');
    expect($html)->not->toContain('SA 2022');
    expect($html)->not->toContain('SA 2018');
});

// ── Public standings ────────────────────────────────────────────────────

it('renders the SA badge next to a shooter name on the public standings table', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $division = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $sapAthlete = User::factory()->create(['name' => 'Protea Pete', 'province_id' => $province->id]);
    Membership::create([
        'user_id' => $sapAthlete->id, 'saprf_number' => 'SAB-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $sapAthlete->id, 'year' => 2026,
        'division_label' => 'Open',
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'host_country' => 'US', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);
    Standing::create([
        'user_id' => $sapAthlete->id, 'season' => '2026', 'series' => 'PRS',
        'points' => 95.0, 'rank' => 1,
    ]);

    $response = $this->get('/standings?season=2026&series=PRS&level=national');

    $response->assertOk();
    $response->assertSee('Protea Pete');
    $response->assertSee('SA 2026');
});

it('does not render the SA badge for a shooter with no national-team appearance', function () {
    $province = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $division = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $plainShooter = User::factory()->create(['name' => 'Plain Patrick', 'province_id' => $province->id]);
    Standing::create([
        'user_id' => $plainShooter->id, 'season' => '2026', 'series' => 'PRS',
        'points' => 75.0, 'rank' => 1,
    ]);

    $response = $this->get('/standings?season=2026&series=PRS&level=national');

    $response->assertOk();
    $response->assertSee('Plain Patrick');
    $response->assertDontSee('SA 2026');
});

// ── Public shooter profile ──────────────────────────────────────────────

it('renders the SA badge in the public profile header for a Protea shooter', function () {
    $shooter = User::factory()->create(['name' => 'Header Harry']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'HDR-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_label' => 'Open',
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'host_country' => 'US', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->get('/shooters/HDR-1/2026');

    $response->assertOk();
    $response->assertSee('Header Harry');
    // The chip appears in the identity header above the Protea Colours hero.
    $response->assertSee('SA 2026');
});

// ── Public /sa-team roster ──────────────────────────────────────────────

it('lists the 2026 roster at /sa-team grouped by year with division labels', function () {
    // Division cards are keyed on the Division model, so seed Ladies.
    $ladies = Division::create(['slug' => 'ladies', 'name' => 'Ladies', 'display_order' => 5, 'is_active' => true]);

    $shooter = User::factory()->create(['name' => 'Roster Rachel']);
    Membership::create([
        'user_id' => $shooter->id, 'saprf_number' => 'RR-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $ladies->id,
        'division_label' => 'Ladies',
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'host_country' => 'US', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->get('/sa-team');

    $response->assertOk();
    $response->assertSee('The Proteas');          // hero heading
    $response->assertSee('2026');
    $response->assertSee('Roster Rachel');
    $response->assertSee('Ladies');               // division card header (styled uppercase via CSS)
    $response->assertSee('2026 IPRF World Championship (Centrefire)');
    $response->assertSee('United States');
});

it('shows the team manager in the header tile rather than a division card', function () {
    // Also create a shooter so the page has a non-empty roster block.
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $manager = User::factory()->create(['name' => 'Clive Example']);
    $shooter = User::factory()->create(['name' => 'Open Oscar']);
    foreach ([
        ['u' => $manager, 'n' => 'MGR-1'],
        ['u' => $shooter, 'n' => 'OPN-1'],
    ] as $entry) {
        Membership::create([
            'user_id' => $entry['u']->id, 'saprf_number' => $entry['n'], 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        ]);
    }
    NationalTeamAppearance::create([
        'user_id' => $manager->id, 'year' => 2026,
        'division_label' => 'Team Manager',
        'role' => NationalTeamAppearance::ROLE_MANAGER,
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'host_country' => 'US', 'awarded_colours' => false,
        'appeared_at' => '2026-09-23',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id, 'division_label' => 'Open',
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'host_country' => 'US', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->get('/sa-team');

    $response->assertOk();
    // Manager appears in the "Team manager" header tile …
    $response->assertSee('Team manager');
    $response->assertSee('Clive Example');
    // … and the shooter appears in the Open division card.
    $response->assertSee('Open Oscar');
    $response->assertSee('Open');
});

it('hides members-only shooters from the roster for guests but shows them to authed members', function () {
    $junior = User::factory()->create([
        'name' => 'Private Junior',
        'public_profile_visibility' => User::PROFILE_VISIBILITY_MEMBERS_ONLY,
    ]);
    Membership::create([
        'user_id' => $junior->id, 'saprf_number' => 'PJR-1', 'membership_type' => 'paid',
        'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $junior->id, 'year' => 2026,
        'division_label' => 'Juniors',
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'host_country' => 'US', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    // Guest → junior hidden entirely.
    $this->get('/sa-team')
        ->assertOk()
        ->assertDontSee('Private Junior');

    // Authed member → junior visible.
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    $this->actingAs($viewer)
        ->get('/sa-team')
        ->assertOk()
        ->assertSee('Private Junior');
});

it('renders a friendly empty state when no appearances have been recorded yet', function () {
    $this->get('/sa-team')
        ->assertOk()
        ->assertSee('No teams recorded yet');
});

// ── NationalTeamAppearance::badgeDataForUsers bulk lookup ───────────────

it('badgeDataForUsers returns one entry per user with newest year first and a tooltip', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $noAppearance = User::factory()->create(['name' => 'Charlie']);

    NationalTeamAppearance::create([
        'user_id' => $alice->id, 'year' => 2024,
        'division_label' => 'Open',
        'championship_name' => 'Worlds 2024',
        'awarded_colours' => true, 'appeared_at' => '2024-07-15',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $alice->id, 'year' => 2026,
        'division_label' => 'Open',
        'championship_name' => 'Worlds 2026',
        'awarded_colours' => false, 'appeared_at' => '2026-09-23',
    ]);
    NationalTeamAppearance::create([
        'user_id' => $bob->id, 'year' => 2026,
        'division_label' => 'Team Manager',
        'role' => NationalTeamAppearance::ROLE_MANAGER,
        'championship_name' => 'Worlds 2026',
        'awarded_colours' => false, 'appeared_at' => '2026-09-23',
    ]);

    $map = NationalTeamAppearance::badgeDataForUsers([$alice->id, $bob->id, $noAppearance->id]);

    // Alice: newest year first.
    expect($map)->toHaveKey($alice->id);
    expect($map[$alice->id]['years'])->toBe([2026, 2024]);
    expect($map[$alice->id]['tooltip'])->toBe('Open · Worlds 2026');
    expect($map[$alice->id]['is_manager'])->toBeFalse();

    // Bob is flagged as a team manager — chip tooltip reflects it.
    expect($map[$bob->id]['years'])->toBe([2026]);
    expect($map[$bob->id]['tooltip'])->toBe('Team Manager · Worlds 2026');
    expect($map[$bob->id]['is_manager'])->toBeTrue();

    // Users with no appearances are omitted (callers use `$map[$id] ?? []`).
    expect($map)->not->toHaveKey($noAppearance->id);
});

// ── Seeder command idempotency + awarded_colours safety ────────────────

it('saprf:seed-national-team-2026 --dry-run reports the plan without inserting rows', function () {
    // Create a single matching user so at least one row would otherwise insert.
    User::factory()->create(['name' => 'Warren Britnell']);

    $this->artisan('saprf:seed-national-team-2026', ['--dry-run' => true])
        ->assertExitCode(0);

    expect(NationalTeamAppearance::count())->toBe(0);
});

it('saprf:seed-national-team-2026 is idempotent across repeated runs', function () {
    // Seed every roster name — a single user per entry, trivially resolvable.
    foreach ([
        'Warren Britnell', 'Leon Goosen', 'Ruan Zeeman', 'Dennis van der Merwe',
        'Terblanche de Jager', 'Marcel Steyn', 'Eliza Mey', 'Monica le Roux',
        'Kim-Leigh Ferreira', 'Natasha Britnell', 'Etienne de Waal',
        'Andries Lategan', 'Le Riche Coetzer Snr', 'Trevor Graham',
        'Conner Britnell', 'Tinus Cronje', 'Johan Nel', 'Donovan Cook',
        'Pieter Cronje', 'Willem van Biljon', 'Mohsin Tajbhai',
        'J.C. Robertson', 'Russell Ferreira', 'Hanno van Niekerk',
        'Chris Leeson', 'Patrick Capes', 'Clive Mey',
    ] as $name) {
        User::factory()->create(['name' => $name]);
    }

    $this->artisan('saprf:seed-national-team-2026')->assertExitCode(0);
    $firstCount = NationalTeamAppearance::count();
    expect($firstCount)->toBe(27);

    // Running again must not create duplicates.
    $this->artisan('saprf:seed-national-team-2026')->assertExitCode(0);
    expect(NationalTeamAppearance::count())->toBe($firstCount);
});

it('saprf:seed-national-team-2026 only awards colours when the user has no existing colours row', function () {
    $firstTimer = User::factory()->create(['name' => 'Warren Britnell']);
    $alreadyColoured = User::factory()->create(['name' => 'Leon Goosen']);

    // Give Leon an existing 2015 colours row.
    NationalTeamAppearance::create([
        'user_id' => $alreadyColoured->id, 'year' => 2015,
        'championship_name' => 'Worlds 2015',
        'awarded_colours' => true, 'appeared_at' => '2015-09-15',
    ]);

    $this->artisan('saprf:seed-national-team-2026')->assertExitCode(0);

    // First-timer gets colours promoted to their 2026 row.
    $warren2026 = NationalTeamAppearance::where('user_id', $firstTimer->id)->where('year', 2026)->first();
    expect($warren2026)->not->toBeNull();
    expect($warren2026->awarded_colours)->toBeTrue();

    // Already-coloured shooter: 2026 row is added but NOT flagged — preserves
    // the one-per-user invariant and keeps the earlier year as their canonical
    // Colours year.
    $leon2026 = NationalTeamAppearance::where('user_id', $alreadyColoured->id)->where('year', 2026)->first();
    expect($leon2026)->not->toBeNull();
    expect($leon2026->awarded_colours)->toBeFalse();

    // Invariant check: exactly one awarded_colours per user.
    expect($alreadyColoured->fresh()->nationalTeamAppearances()->awardedColours()->count())->toBe(1);
    expect($alreadyColoured->fresh()->nationalTeamAppearances()->awardedColours()->first()->year)->toBe(2015);
});

it('saprf:seed-national-team-2026 never awards Protea Colours to the team manager', function () {
    // Clive Mey is a non-shooting support role — the seeder must record
    // his appearance (so the badge shows) but leave awarded_colours FALSE,
    // even on a brand-new user with no prior appearances.
    $clive = User::factory()->create(['name' => 'Clive Mey']);

    $this->artisan('saprf:seed-national-team-2026')->assertExitCode(0);

    $row = NationalTeamAppearance::where('user_id', $clive->id)->where('year', 2026)->first();
    expect($row)->not->toBeNull();
    expect($row->awarded_colours)->toBeFalse();
    expect($row->division_label)->toBe('Team Manager');
    expect($row->division_id)->toBeNull();

    // Invariant check: zero colours rows for the manager.
    expect($clive->fresh()->nationalTeamAppearances()->awardedColours()->count())->toBe(0);
});

it('saprf:seed-national-team-2026 reports ambiguous names without inserting them', function () {
    // Two users share the name "J.C. Robertson" — the command must flag it
    // ambiguous and skip rather than silently picking one.
    User::factory()->create(['name' => 'J.C. Robertson']);
    User::factory()->create(['name' => 'J.C. Robertson']);

    $this->artisan('saprf:seed-national-team-2026')->assertExitCode(0);

    // No appearances inserted for the ambiguous name.
    expect(NationalTeamAppearance::query()
        ->whereHas('user', fn ($q) => $q->where('name', 'J.C. Robertson'))
        ->count()
    )->toBe(0);
});
