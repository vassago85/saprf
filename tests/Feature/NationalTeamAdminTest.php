<?php

use App\Models\Division;
use App\Models\Membership;
use App\Models\NationalTeam;
use App\Models\NationalTeamAppearance;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function adminActor(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function shooterWith(string $name, string $saprfNumber): User
{
    $u = User::factory()->create(['name' => $name]);
    Membership::create([
        'user_id' => $u->id,
        'saprf_number' => $saprfNumber,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    return $u;
}

// ────────────────────────────────────────────────────────────────────────
// Guarding
// ────────────────────────────────────────────────────────────────────────

it('plain members get 403 on all team-year admin routes', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)->get('/team-years')->assertForbidden();
    $this->actingAs($member)->get('/team-years/create')->assertForbidden();
    $this->actingAs($member)->post('/team-years', ['year' => 2027, 'championship_name' => 'x', 'discipline' => 'centrefire'])->assertForbidden();
});

it('plain members get 403 on appearance edit routes', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $shooter = shooterWith('S', '1');
    $appearance = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => 'Worlds 2026', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)->get('/appearances/'.$appearance->id.'/edit')->assertForbidden();
    $this->actingAs($member)
        ->patch('/appearances/'.$appearance->id, ['year' => 2026, 'championship_name' => 'Worlds 2026', 'appeared_at' => '2026-09-23'])
        ->assertForbidden();
});

// ────────────────────────────────────────────────────────────────────────
// Appearance edit — the main gap the user asked us to close
// ────────────────────────────────────────────────────────────────────────

it('admin can promote a shooter to captain via the edit screen', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $shooter = shooterWith('Warren', 'WB1');
    $appearance = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'role' => NationalTeamAppearance::ROLE_SHOOTER,
        'championship_name' => '2026 Worlds (Centrefire)', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $response = $this->actingAs(adminActor())->patch('/appearances/'.$appearance->id, [
        'year' => 2026,
        'division_id' => $open->id,
        'role' => NationalTeamAppearance::ROLE_CAPTAIN,
        'championship_name' => '2026 Worlds (Centrefire)',
        'awarded_colours' => '1',
        'appeared_at' => '2026-09-23',
    ]);

    $response->assertRedirect();
    expect($appearance->fresh()->role)->toBe(NationalTeamAppearance::ROLE_CAPTAIN);
    expect($appearance->fresh()->awarded_colours)->toBeTrue();
});

it('admin can fix a shooter-imported-as-manager by switching the role', function () {
    // Simulates "Clive was imported as shooter, admin now switches to manager."
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $clive = shooterWith('Clive', 'MGR-1');
    $appearance = NationalTeamAppearance::create([
        'user_id' => $clive->id, 'year' => 2026,
        'division_id' => $open->id, 'role' => NationalTeamAppearance::ROLE_SHOOTER,
        'championship_name' => '2026 Worlds', 'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
    ]);

    $this->actingAs(adminActor())->patch('/appearances/'.$appearance->id, [
        'year' => 2026,
        'division_id' => $open->id,
        'role' => NationalTeamAppearance::ROLE_MANAGER,
        'championship_name' => '2026 Worlds',
        'awarded_colours' => '1', // admin leaves it ticked — the controller clears it anyway
        'appeared_at' => '2026-09-23',
    ])->assertRedirect();

    $appearance->refresh();
    expect($appearance->role)->toBe(NationalTeamAppearance::ROLE_MANAGER);
    // Managers don't shoot: colours are cleared even if the admin forgot to untick.
    expect($appearance->awarded_colours)->toBeFalse();
});

it('admin cannot double-award colours to the same shooter across two rows', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $shooter = shooterWith('Warren', 'WB1');

    // The row that already carries colours.
    NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2024,
        'division_id' => $open->id,
        'championship_name' => '2024 Worlds', 'awarded_colours' => true,
        'appeared_at' => '2024-07-15',
    ]);

    // A second (later) appearance without colours.
    $second = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => '2026 Worlds', 'awarded_colours' => false,
        'appeared_at' => '2026-09-23',
    ]);

    // Admin tries to tick colours on the second row too.
    $response = $this->actingAs(adminActor())->patch('/appearances/'.$second->id, [
        'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => '2026 Worlds',
        'awarded_colours' => '1',
        'appeared_at' => '2026-09-23',
    ]);

    $response->assertSessionHasErrors('awarded_colours');
    expect($second->fresh()->awarded_colours)->toBeFalse();
});

it('admin can re-attach an appearance to a different team year by changing the championship name', function () {
    // Admin accidentally recorded 2026 Rimfire as Centrefire. Fix it.
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $shooter = shooterWith('A', 'A1');
    $cf = NationalTeam::create([
        'year' => 2026, 'championship_name' => '2026 IPRF Worlds (Centrefire)',
        'discipline' => 'centrefire',
    ]);
    $appearance = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'national_team_id' => $cf->id,
        'championship_name' => '2026 IPRF Worlds (Centrefire)',
        'appeared_at' => '2026-09-23',
    ]);

    $this->actingAs(adminActor())->patch('/appearances/'.$appearance->id, [
        'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => '2026 IPRF Worlds (Rimfire)',
        'appeared_at' => '2026-09-23',
    ])->assertRedirect();

    $appearance->refresh();
    expect($appearance->championship_name)->toBe('2026 IPRF Worlds (Rimfire)');
    // The parent team-year record was created (or re-used) with discipline=rimfire.
    expect($appearance->national_team_id)->not->toBe($cf->id);
    expect($appearance->nationalTeam->discipline)->toBe('rimfire');
});

it('appearance update with back_to=team_year redirects to the team year edit page', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $shooter = shooterWith('A', 'A1');
    $team = NationalTeam::create([
        'year' => 2026, 'championship_name' => '2026 Worlds',
        'discipline' => 'centrefire',
    ]);
    $appearance = NationalTeamAppearance::create([
        'user_id' => $shooter->id, 'year' => 2026,
        'division_id' => $open->id,
        'national_team_id' => $team->id,
        'championship_name' => '2026 Worlds',
        'appeared_at' => '2026-09-23',
    ]);

    $this->actingAs(adminActor())->patch('/appearances/'.$appearance->id, [
        'year' => 2026,
        'division_id' => $open->id,
        'championship_name' => '2026 Worlds',
        'appeared_at' => '2026-09-23',
        'back_to' => 'team_year',
    ])->assertRedirect('/team-years/'.$team->id.'/edit');
});

// ────────────────────────────────────────────────────────────────────────
// Team year create
// ────────────────────────────────────────────────────────────────────────

it('admin can create a new team year directly from the admin panel', function () {
    $response = $this->actingAs(adminActor())->post('/team-years', [
        'year' => 2027,
        'championship_name' => '2027 IPRF World Championship (Centrefire)',
        'discipline' => 'centrefire',
        'host_country' => 'ZA',
        'city' => 'Cape Town',
        'venue' => 'Western Cape Range',
        'starts_on' => '2027-09-15',
        'ends_on' => '2027-09-20',
    ]);

    $team = NationalTeam::where('year', 2027)->first();
    expect($team)->not->toBeNull();
    expect($team->championship_name)->toBe('2027 IPRF World Championship (Centrefire)');
    $response->assertRedirect('/team-years/'.$team->id.'/edit');
});

it('creating a duplicate (year, championship_name) is rejected with a friendly error', function () {
    NationalTeam::create([
        'year' => 2026,
        'championship_name' => '2026 Worlds',
        'discipline' => 'centrefire',
    ]);

    $this->actingAs(adminActor())->post('/team-years', [
        'year' => 2026,
        'championship_name' => '2026 Worlds',
        'discipline' => 'rimfire',
    ])->assertSessionHasErrors('championship_name');

    expect(NationalTeam::where('year', 2026)->count())->toBe(1);
});

it('end date must be on or after start date', function () {
    $this->actingAs(adminActor())->post('/team-years', [
        'year' => 2027,
        'championship_name' => '2027 Worlds',
        'discipline' => 'centrefire',
        'starts_on' => '2027-09-20',
        'ends_on' => '2027-09-15',
    ])->assertSessionHasErrors('ends_on');
});

// ────────────────────────────────────────────────────────────────────────
// Team year edit page renders the roster
// ────────────────────────────────────────────────────────────────────────

it('team year edit page lists the roster with role chips, Edit links and + Add shooter', function () {
    $open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $team = NationalTeam::create([
        'year' => 2026, 'championship_name' => '2026 Worlds',
        'discipline' => 'centrefire', 'host_country' => 'US',
    ]);
    $captain = shooterWith('Captain Kim', 'KIM1');
    $manager = shooterWith('Manager Max', 'MAX1');

    foreach ([
        ['u' => $captain, 'role' => NationalTeamAppearance::ROLE_CAPTAIN],
        ['u' => $manager, 'role' => NationalTeamAppearance::ROLE_MANAGER],
    ] as $r) {
        NationalTeamAppearance::create([
            'user_id' => $r['u']->id, 'year' => 2026,
            'division_id' => $open->id, 'role' => $r['role'],
            'national_team_id' => $team->id,
            'championship_name' => '2026 Worlds',
            'appeared_at' => '2026-09-23',
        ]);
    }

    $response = $this->actingAs(adminActor())->get('/team-years/'.$team->id.'/edit');

    $response->assertOk();
    $response->assertSee('Roster');
    $response->assertSee('Captain Kim');
    $response->assertSee('Manager Max');
    $response->assertSee('+ Add shooter');
    // Edit links carry back_to=team_year so saving returns the admin here.
    $response->assertSee('back_to=team_year', false);
});
