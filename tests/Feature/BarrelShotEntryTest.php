<?php

use App\Models\Barrel;
use App\Models\BarrelShotEntry;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function barrelOwner(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user;
}

it('lets the owner log a practice shot entry and recalculates round_count', function () {
    $user = barrelOwner();
    $barrel = Barrel::factory()->for($user)->create([
        'starting_round_count' => 500,
        'round_count' => 500,
    ]);

    $this->actingAs($user)
        ->post(route('barrels.shot-entries.store', $barrel), [
            'fired_on' => now()->toDateString(),
            'shot_count' => 40,
            'type' => 'practice',
            'notes' => 'Zero and drills',
        ])
        ->assertRedirect(route('barrels.show', $barrel));

    expect(BarrelShotEntry::count())->toBe(1)
        ->and($barrel->fresh()->round_count)->toBe(540)
        ->and($barrel->fresh()->starting_round_count)->toBe(500);
});

it('sums multiple entries into round_count', function () {
    $user = barrelOwner();
    $barrel = Barrel::factory()->for($user)->create([
        'starting_round_count' => 100,
        'round_count' => 100,
    ]);

    $this->actingAs($user)->post(route('barrels.shot-entries.store', $barrel), [
        'fired_on' => now()->toDateString(),
        'shot_count' => 25,
        'type' => 'practice',
    ]);
    $this->actingAs($user)->post(route('barrels.shot-entries.store', $barrel), [
        'fired_on' => now()->subDay()->toDateString(),
        'shot_count' => 60,
        'type' => 'non_saprf',
        'notes' => 'Club fun shoot',
    ]);

    expect($barrel->fresh()->round_count)->toBe(185);
});

it('recalculates round_count when an entry is deleted', function () {
    $user = barrelOwner();
    $barrel = Barrel::factory()->for($user)->create([
        'starting_round_count' => 200,
        'round_count' => 200,
    ]);

    $this->actingAs($user)->post(route('barrels.shot-entries.store', $barrel), [
        'fired_on' => now()->toDateString(),
        'shot_count' => 50,
        'type' => 'practice',
    ]);

    $entry = BarrelShotEntry::firstOrFail();
    expect($barrel->fresh()->round_count)->toBe(250);

    $this->actingAs($user)
        ->delete(route('barrels.shot-entries.destroy', [$barrel, $entry]))
        ->assertRedirect(route('barrels.show', $barrel));

    expect(BarrelShotEntry::count())->toBe(0)
        ->and($barrel->fresh()->round_count)->toBe(200);
});

it('recalculates round_count when starting rounds change on the barrel', function () {
    $user = barrelOwner();
    $barrel = Barrel::factory()->for($user)->create([
        'starting_round_count' => 100,
        'round_count' => 100,
    ]);

    $barrel->shotEntries()->create([
        'user_id' => $user->id,
        'fired_on' => now()->toDateString(),
        'shot_count' => 30,
        'type' => 'practice',
    ]);
    $barrel->recalculateRoundCount();
    expect($barrel->fresh()->round_count)->toBe(130);

    $this->actingAs($user)
        ->put(route('barrels.update', $barrel), [
            'label' => $barrel->label,
            'starting_round_count' => 500,
        ])
        ->assertRedirect(route('barrels.show', $barrel));

    expect($barrel->fresh()->starting_round_count)->toBe(500)
        ->and($barrel->fresh()->round_count)->toBe(530);
});

it('rejects another member trying to log entries on someone else\'s barrel', function () {
    $owner = barrelOwner();
    $intruder = barrelOwner();
    $barrel = Barrel::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->post(route('barrels.shot-entries.store', $barrel), [
            'fired_on' => now()->toDateString(),
            'shot_count' => 10,
            'type' => 'practice',
        ])
        ->assertForbidden();

    expect(BarrelShotEntry::count())->toBe(0);
});

it('rejects another member trying to delete entries on someone else\'s barrel', function () {
    $owner = barrelOwner();
    $intruder = barrelOwner();
    $barrel = Barrel::factory()->for($owner)->create([
        'starting_round_count' => 0,
        'round_count' => 0,
    ]);
    $entry = $barrel->shotEntries()->create([
        'user_id' => $owner->id,
        'fired_on' => now()->toDateString(),
        'shot_count' => 20,
        'type' => 'practice',
    ]);

    $this->actingAs($intruder)
        ->delete(route('barrels.shot-entries.destroy', [$barrel, $entry]))
        ->assertForbidden();

    expect(BarrelShotEntry::whereKey($entry->id)->exists())->toBeTrue();
});

it('rejects the show page for non-owners', function () {
    $owner = barrelOwner();
    $intruder = barrelOwner();
    $barrel = Barrel::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('barrels.show', $barrel))
        ->assertForbidden();
});

it('validates shot_count and type', function () {
    $user = barrelOwner();
    $barrel = Barrel::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('barrels.show', $barrel))
        ->post(route('barrels.shot-entries.store', $barrel), [
            'fired_on' => now()->toDateString(),
            'shot_count' => 0,
            'type' => 'practice',
        ])
        ->assertRedirect(route('barrels.show', $barrel))
        ->assertSessionHasErrors('shot_count');

    $this->actingAs($user)
        ->from(route('barrels.show', $barrel))
        ->post(route('barrels.shot-entries.store', $barrel), [
            'fired_on' => now()->toDateString(),
            'shot_count' => 30,
            'type' => 'match',
        ])
        ->assertSessionHasErrors('type');

    $this->actingAs($user)
        ->from(route('barrels.show', $barrel))
        ->post(route('barrels.shot-entries.store', $barrel), [
            'fired_on' => now()->addDay()->toDateString(),
            'shot_count' => 30,
            'type' => 'practice',
        ])
        ->assertSessionHasErrors('fired_on');
});

it('rejects updating an entry that belongs to a different barrel', function () {
    $user = barrelOwner();
    $barrelA = Barrel::factory()->for($user)->create();
    $barrelB = Barrel::factory()->for($user)->create();

    $entry = $barrelA->shotEntries()->create([
        'user_id' => $user->id,
        'fired_on' => now()->toDateString(),
        'shot_count' => 15,
        'type' => 'practice',
    ]);

    $this->actingAs($user)
        ->put(route('barrels.shot-entries.update', [$barrelB, $entry]), [
            'fired_on' => now()->toDateString(),
            'shot_count' => 99,
            'type' => 'practice',
        ])
        ->assertNotFound();
});
