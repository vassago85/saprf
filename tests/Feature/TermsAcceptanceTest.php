<?php

use App\Models\MembershipFeeTier;
use Livewire\Volt\Volt;

beforeEach(function () {
    seedRoles();
});

test('the public terms page renders the verbatim markdown', function () {
    $this->get(route('legal.terms'))
        ->assertOk()
        ->assertSee('Terms & Conditions', escape: false)
        ->assertSee('SAPRF Membership')
        ->assertSee('South African Precision Rifle Federation');
});

test('the public privacy page renders the verbatim markdown', function () {
    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertSee('Privacy Policy')
        ->assertSee('Business and personal information')
        ->assertSee('Cookies')
        ->assertSee('Data may be', escape: false)
        ->assertSee('outside South Africa');
});

test('the terms page injects the highest active fee tier into the liability cap', function () {
    MembershipFeeTier::query()->delete();
    MembershipFeeTier::create([
        'slug' => 'adult', 'name' => 'Adult', 'price' => 450,
        'duration_months' => 12, 'display_order' => 1, 'is_active' => true, 'is_default' => true,
    ]);
    MembershipFeeTier::create([
        'slug' => 'senior', 'name' => 'Senior', 'price' => 900,
        'duration_months' => 12, 'display_order' => 2, 'is_active' => true, 'is_default' => false,
    ]);

    $this->get(route('legal.terms'))
        ->assertOk()
        ->assertSee('R 900')
        ->assertDontSee('{{LIABILITY_CAP}}', escape: false);
});

test('the terms page falls back to a default cap when no fee tiers are seeded', function () {
    MembershipFeeTier::query()->delete();

    $this->get(route('legal.terms'))
        ->assertOk()
        ->assertSee('R 1,000');
});

test('registration is blocked when the terms checkbox is not ticked', function () {
    $province = \App\Models\Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test.terms@example.com')
        ->set('id_type', 'sa_id')
        ->set('sa_id_number', '8001015009087')
        ->set('province_id', $province->id)
        ->set('password', 'SuperSecret123')
        ->set('password_confirmation', 'SuperSecret123')
        ->set('terms_accepted', false)
        ->call('register')
        ->assertHasErrors(['terms_accepted' => 'accepted']);

    expect(\App\Models\User::where('email', 'test.terms@example.com')->exists())->toBeFalse();
});

test('registration succeeds when the terms checkbox is ticked', function () {
    $province = \App\Models\Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    Volt::test('pages.auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test.acceptor@example.com')
        ->set('id_type', 'sa_id')
        ->set('sa_id_number', '8001015009088')
        ->set('province_id', $province->id)
        ->set('password', 'SuperSecret123')
        ->set('password_confirmation', 'SuperSecret123')
        ->set('terms_accepted', true)
        ->call('register')
        ->assertHasNoErrors();

    expect(\App\Models\User::where('email', 'test.acceptor@example.com')->exists())->toBeTrue();
});
