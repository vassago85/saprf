<?php

declare(strict_types=1);

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

/**
 * Phase 3.4 guarantee: the MD phone number is a private field by default.
 * It must be visible only to admins, the owning MD, or on a per-match
 * opt-in via `show_md_contact_publicly`. Everyone else sees the MD's name
 * but not the number.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->director = User::factory()->create();
    $this->director->assignRole('match_director');

    $this->other = User::factory()->create();

    $this->match = MatchEvent::create([
        'name' => 'MD Visibility Test',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::tomorrow()->toDateString(),
        'status' => 'open',
        'published' => true,
        'created_by' => $this->director->id,
        'match_director' => 'Alice Director',
        'match_director_contact' => '+27 71 555 0100',
        'show_md_contact_publicly' => false,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
});

it('hides the MD phone from anonymous visitors when the flag is off', function () {
    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Alice Director');
    $response->assertDontSee('+27 71 555 0100');
});

it('hides the MD phone from a logged-in non-admin, non-owner visitor', function () {
    $response = $this->actingAs($this->other)->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Alice Director');
    $response->assertDontSee('+27 71 555 0100');
});

it('shows the MD phone to admins regardless of the flag', function () {
    $response = $this->actingAs($this->admin)->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('+27 71 555 0100');
});

it('shows the MD phone to the owning match director regardless of the flag', function () {
    $response = $this->actingAs($this->director)->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('+27 71 555 0100');
});

it('shows the MD phone to everyone once the public-visibility flag is turned on', function () {
    $this->match->update(['show_md_contact_publicly' => true]);

    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('+27 71 555 0100');

    $response = $this->actingAs($this->other)->get('/events/'.$this->match->id);
    $response->assertSee('+27 71 555 0100');
});

it('does not render a contact line when the match has no MD contact at all', function () {
    $this->match->update(['match_director_contact' => null, 'show_md_contact_publicly' => true]);

    $response = $this->actingAs($this->admin)->get('/events/'.$this->match->id);

    $response->assertOk();
    // No phone of any kind to render.
    expect($this->match->canShowDirectorContactTo($this->admin))->toBeFalse();
});
