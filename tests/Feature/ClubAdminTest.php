<?php

use App\Models\Club;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function clubAdminUser(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    return $user;
}

test('admin can view the clubs index', function () {
    $admin = clubAdminUser();
    Club::create(['name' => 'Pretoria PRC', 'slug' => 'pretoria-prc']);

    $this->actingAs($admin)
        ->get(route('clubs.index'))
        ->assertOk()
        ->assertSee('Pretoria PRC');
});

test('a member cannot view the clubs index', function () {
    $member = clubAdminUser('member');

    $this->actingAs($member)
        ->get(route('clubs.index'))
        ->assertForbidden();
});

test('admin can create a club, defaulting to recognised + active', function () {
    $admin = clubAdminUser();
    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->actingAs($admin)
        ->post(route('clubs.store'), [
            'name' => 'New Rifle Club',
            'abbreviation' => 'NRC',
            'province_id' => $province->id,
            'saprf_recognised' => '1',
            'is_active' => '1',
        ])
        ->assertRedirect(route('clubs.index'));

    $club = Club::where('name', 'New Rifle Club')->first();
    expect($club)->not->toBeNull()
        ->and($club->slug)->toBe('new-rifle-club')
        ->and($club->saprf_recognised)->toBeTrue()
        ->and($club->is_active)->toBeTrue();
});

test('unchecked checkboxes persist as false on update', function () {
    $admin = clubAdminUser();
    $club = Club::create([
        'name' => 'Recognise Me',
        'slug' => 'recognise-me',
        'saprf_recognised' => true,
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->put(route('clubs.update', $club), [
            'name' => 'Recognise Me',
        ])
        ->assertRedirect(route('clubs.index'));

    $club->refresh();
    expect($club->saprf_recognised)->toBeFalse()
        ->and($club->is_active)->toBeFalse();
});

test('toggling recognition flips the flag', function () {
    $admin = clubAdminUser();
    $club = Club::create([
        'name' => 'Toggle Me',
        'slug' => 'toggle-me',
        'saprf_recognised' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('clubs.toggle-recognition', $club))
        ->assertRedirect();

    expect($club->fresh()->saprf_recognised)->toBeFalse();

    $this->actingAs($admin)
        ->post(route('clubs.toggle-recognition', $club))
        ->assertRedirect();

    expect($club->fresh()->saprf_recognised)->toBeTrue();
});

test('owner can merge a source club into a target and reassign members', function () {
    $owner = clubAdminUser('owner');
    $source = Club::create(['name' => 'Old Club', 'slug' => 'old-club']);
    $target = Club::create(['name' => 'New Club', 'slug' => 'new-club']);

    $u1 = User::factory()->create(['club_id' => $source->id]);
    $u2 = User::factory()->create(['club_id' => $source->id]);
    $unaffected = User::factory()->create(['club_id' => $target->id]);

    $this->actingAs($owner)
        ->post(route('clubs.merge', $source), [
            'target_id' => $target->id,
        ])
        ->assertRedirect(route('clubs.index'));

    expect(Club::find($source->id))->toBeNull()
        ->and($u1->fresh()->club_id)->toBe($target->id)
        ->and($u2->fresh()->club_id)->toBe($target->id)
        ->and($unaffected->fresh()->club_id)->toBe($target->id);
});

test('cannot merge a club into itself', function () {
    $owner = clubAdminUser('owner');
    $club = Club::create(['name' => 'Only Club', 'slug' => 'only-club']);

    $this->actingAs($owner)
        ->post(route('clubs.merge', $club), [
            'target_id' => $club->id,
        ])
        ->assertSessionHasErrors('target_id');

    expect(Club::find($club->id))->not->toBeNull();
});

test('cannot delete a club that still has members', function () {
    $owner = clubAdminUser('owner');
    $club = Club::create(['name' => 'Keep Me', 'slug' => 'keep-me']);
    User::factory()->create(['club_id' => $club->id]);

    $this->actingAs($owner)
        ->delete(route('clubs.destroy', $club))
        ->assertRedirect();

    expect(Club::find($club->id))->not->toBeNull();
});

test('empty club can be deleted', function () {
    $owner = clubAdminUser('owner');
    $club = Club::create(['name' => 'Bye Bye', 'slug' => 'bye-bye']);

    $this->actingAs($owner)
        ->delete(route('clubs.destroy', $club))
        ->assertRedirect(route('clubs.index'));

    expect(Club::find($club->id))->toBeNull();
});

test('admin cannot merge — only owner (+ developer/exco via Gate::before)', function () {
    $admin = clubAdminUser('admin');
    $source = Club::create(['name' => 'Src', 'slug' => 'src']);
    $target = Club::create(['name' => 'Tgt', 'slug' => 'tgt']);

    $this->actingAs($admin)
        ->post(route('clubs.merge', $source), ['target_id' => $target->id])
        ->assertForbidden();

    expect(Club::find($source->id))->not->toBeNull();
});
