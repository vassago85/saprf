<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['code' => 'GP'], ['name' => 'Gauteng', 'abbreviation' => 'GP']);
    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'is_active' => true, 'display_order' => 1]);

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->otherMd = User::factory()->create(['email_verified_at' => now()]);
    $this->otherMd->assignRole('match_director');

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Rename Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subWeek(),
        'status' => 'completed',
        'published' => true,
        'match_director' => $this->md->name,
        'active_member_fee' => 500,
        'non_member_fee' => 650,
        'lapsed_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);
});

function makeRegistrationFor(MatchEvent $match, User $user): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500, 'surcharge_amount' => 0,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 0, 'md_net_amount' => 400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDay(),
    ]);
}

it('lets the owning MD rename the shooter on a registration', function () {
    $shooter = User::factory()->create(['name' => 'Jhon Doe']);
    $reg = makeRegistrationFor($this->match, $shooter);

    $this->actingAs($this->md)
        ->put(route('registrations.update-shooter-name', $reg), ['shooter_name' => 'John Doe'])
        ->assertRedirect();

    $reg->refresh();
    expect($reg->shooter_name)->toBe('John Doe');
    // Real member accounts are NEVER renamed by a per-match rename —
    // only the registration-level display label gets updated.
    expect($shooter->fresh()->name)->toBe('Jhon Doe');
});

it('renames the stub walk-in user when the registration points at a saprf.walkin account', function () {
    $stub = User::factory()->create([
        'name' => 'Impact Mangled',
        'email' => 'impact.mangled.abc123@saprf.walkin',
    ]);
    $reg = makeRegistrationFor($this->match, $stub);

    $this->actingAs($this->md)
        ->put(route('registrations.update-shooter-name', $reg), ['shooter_name' => 'Clean Name'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $reg->refresh();
    $stub->refresh();

    expect($reg->shooter_name)->toBe('Clean Name')
        ->and($stub->name)->toBe('Clean Name'); // stub account tracks the registration
});

it('forbids a different MD from renaming entries on a match they do not own', function () {
    $shooter = User::factory()->create(['name' => 'Steven']);
    $reg = makeRegistrationFor($this->match, $shooter);

    $this->actingAs($this->otherMd)
        ->put(route('registrations.update-shooter-name', $reg), ['shooter_name' => 'Steven Hijacked'])
        ->assertForbidden();

    $reg->refresh();
    expect($reg->shooter_name)->toBe('Steven');
});

it('rejects renames shorter than 2 characters', function () {
    $shooter = User::factory()->create();
    $reg = makeRegistrationFor($this->match, $shooter);

    $this->actingAs($this->md)
        ->put(route('registrations.update-shooter-name', $reg), ['shooter_name' => 'X'])
        ->assertSessionHasErrors('shooter_name');

    $reg->refresh();
    expect($reg->shooter_name)->toBe($shooter->name);
});

it('shows the Edit name button on the registration page for the owning MD', function () {
    $shooter = User::factory()->create();
    $reg = makeRegistrationFor($this->match, $shooter);

    $this->actingAs($this->md)
        ->get(route('registrations.show', $reg))
        ->assertOk()
        ->assertSee('Edit name');
});

it('admins can rename any registration without being the MD', function () {
    $shooter = User::factory()->create(['name' => 'Needs Fix']);
    $reg = makeRegistrationFor($this->match, $shooter);

    $this->actingAs($this->admin)
        ->put(route('registrations.update-shooter-name', $reg), ['shooter_name' => 'Fixed'])
        ->assertRedirect();

    expect($reg->fresh()->shooter_name)->toBe('Fixed');
});
