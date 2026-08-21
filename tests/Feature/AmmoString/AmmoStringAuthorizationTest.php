<?php

use App\Models\AmmoString;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

/**
 * String records are personal reloading data, subject to the same
 * developer/exco carve-out as barrels and ladder sessions. Other members —
 * including EXCO and developers — must never see them via the web UI.
 */
it('rejects another member trying to view someone else\'s string', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $intruder = User::factory()->create();
    $intruder->assignRole('member');

    $string = AmmoString::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('ammo-strings.show', $string))
        ->assertForbidden();
});

it('rejects another member trying to delete someone else\'s string', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $string = AmmoString::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('ammo-strings.destroy', $string))
        ->assertForbidden();

    expect(AmmoString::whereKey($string->id)->exists())->toBeTrue();
});

it('rejects another member trying to export someone else\'s string CSV', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $string = AmmoString::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('ammo-strings.export.csv', $string))
        ->assertForbidden();
});

it('rejects exco even though exco bypasses every other policy', function () {
    $owner = User::factory()->create();
    $exco = User::factory()->create();
    $exco->assignRole('exco');
    $string = AmmoString::factory()->for($owner)->create();

    // Same rationale as ladders: a confirmed load's SD is personal
    // intellectual property. Exco does not get to see it.
    $this->actingAs($exco)
        ->get(route('ammo-strings.show', $string))
        ->assertForbidden();
});

it('rejects developer for the same reason', function () {
    $owner = User::factory()->create();
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    $string = AmmoString::factory()->for($owner)->create();

    $this->actingAs($dev)
        ->get(route('ammo-strings.show', $string))
        ->assertForbidden();
});

it('lets the owner view, delete, and export their own string', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $string = AmmoString::factory()->for($owner)->create();

    $this->actingAs($owner)->get(route('ammo-strings.show', $string))->assertOk();
    $this->actingAs($owner)->get(route('ammo-strings.export.csv', $string))->assertOk();
    $this->actingAs($owner)->delete(route('ammo-strings.destroy', $string))->assertRedirect();
});

it('only lists the caller\'s own strings on the index', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $stranger = User::factory()->create();
    $stranger->assignRole('member');
    AmmoString::factory()->for($owner)->create(['label' => 'Owner load']);
    AmmoString::factory()->for($stranger)->create(['label' => 'Stranger load']);

    $response = $this->actingAs($owner)->get(route('ammo-strings.index'));

    $response->assertOk()
        ->assertSee('Owner load')
        ->assertDontSee('Stranger load');
});
