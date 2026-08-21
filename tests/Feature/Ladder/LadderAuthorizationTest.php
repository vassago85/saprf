<?php

use App\Models\Barrel;
use App\Models\LadderSession;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

it('rejects another member trying to view someone else\'s ladder session', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $intruder = User::factory()->create();
    $intruder->assignRole('member');

    $session = LadderSession::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('ladder-sessions.show', $session))
        ->assertForbidden();
});

it('rejects another member trying to delete someone else\'s ladder session', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $session = LadderSession::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('ladder-sessions.destroy', $session))
        ->assertForbidden();

    expect(LadderSession::whereKey($session->id)->exists())->toBeTrue();
});

it('rejects another member trying to export someone else\'s ladder CSV', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $session = LadderSession::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('ladder-sessions.export.csv', $session))
        ->assertForbidden();
});

it('rejects exco even though exco bypasses every other policy', function () {
    $owner = User::factory()->create();
    $exco = User::factory()->create();
    $exco->assignRole('exco');
    $session = LadderSession::factory()->for($owner)->create();

    // The whole point of the carve-out in AppServiceProvider: a load recipe
    // is personal intellectual property and several nationally-ranked
    // shooters compete against each other. Exco does not get to see it.
    $this->actingAs($exco)
        ->get(route('ladder-sessions.show', $session))
        ->assertForbidden();
});

it('rejects developer for the same reason', function () {
    $owner = User::factory()->create();
    $dev = User::factory()->create();
    $dev->assignRole('developer');
    $session = LadderSession::factory()->for($owner)->create();

    $this->actingAs($dev)
        ->get(route('ladder-sessions.show', $session))
        ->assertForbidden();
});

it('lets the owner view, delete, and export their own ladder session', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $session = LadderSession::factory()->for($owner)->create();

    $this->actingAs($owner)->get(route('ladder-sessions.show', $session))->assertOk();
    $this->actingAs($owner)->get(route('ladder-sessions.export.csv', $session))->assertOk();
    $this->actingAs($owner)->delete(route('ladder-sessions.destroy', $session))->assertRedirect();
});

it('rejects another member trying to view or edit someone else\'s barrel', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $barrel = Barrel::factory()->for($owner)->create();

    $this->actingAs($intruder)->get(route('barrels.edit', $barrel))->assertForbidden();
    $this->actingAs($intruder)->put(route('barrels.update', $barrel), [
        'label' => 'Hacked', 'starting_round_count' => 0,
    ])->assertForbidden();
    $this->actingAs($intruder)->delete(route('barrels.destroy', $barrel))->assertForbidden();
});

it('rejects exco from viewing or editing barrels for the same policy reason', function () {
    $owner = User::factory()->create();
    $exco = User::factory()->create();
    $exco->assignRole('exco');
    $barrel = Barrel::factory()->for($owner)->create();

    $this->actingAs($exco)->get(route('barrels.edit', $barrel))->assertForbidden();
});
