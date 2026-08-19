<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\RifleConfiguration;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();
});

function rifleMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user;
}

function makeRifle(User $user, array $overrides = []): RifleConfiguration
{
    return RifleConfiguration::create(array_merge([
        'user_id' => $user->id,
        'nickname' => 'Test Rifle',
        'is_active' => true,
    ], $overrides));
}

it('saves a rifle as the main PRS rifle', function () {
    $user = rifleMember();

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => '6.5 Creedmoor',
            'primary_series' => 'PRS',
        ])
        ->assertRedirect(route('rifle-configurations.index'));

    $rifle = RifleConfiguration::query()->where('user_id', $user->id)->first();

    expect($rifle)->not->toBeNull()
        ->and($rifle->primary_series)->toBe('PRS');
});

it('lets a member keep one main PRS rifle and one main PR22 rifle', function () {
    $user = rifleMember();

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => 'Creedmoor',
            'primary_series' => 'PRS',
        ]);

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => 'Rimfire',
            'primary_series' => 'PR22',
        ]);

    $rifles = RifleConfiguration::query()->where('user_id', $user->id)->get();

    expect($rifles)->toHaveCount(2)
        ->and($rifles->firstWhere('nickname', 'Creedmoor')->primary_series)->toBe('PRS')
        ->and($rifles->firstWhere('nickname', 'Rimfire')->primary_series)->toBe('PR22');
});

it('clears the previous main PRS rifle when another is marked main PRS', function () {
    $user = rifleMember();
    $old = makeRifle($user, ['nickname' => 'Old PRS', 'primary_series' => 'PRS']);

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => 'New PRS',
            'primary_series' => 'PRS',
        ]);

    expect($old->fresh()->primary_series)->toBeNull()
        ->and(RifleConfiguration::query()->where('nickname', 'New PRS')->value('primary_series'))->toBe('PRS');
});

it('shows Main PRS and Main PR22 badges instead of a generic Primary label', function () {
    $user = rifleMember();
    $prs = makeRifle($user, ['nickname' => 'Creedmoor', 'primary_series' => 'PRS']);
    $pr22 = makeRifle($user, ['nickname' => 'Rimfire', 'primary_series' => 'PR22']);

    $this->actingAs($user)
        ->get(route('rifle-configurations.show', $prs))
        ->assertOk()
        ->assertSee('Main PRS')
        ->assertDontSee('>Primary<', false);

    $this->actingAs($user)
        ->get(route('rifle-configurations.show', $pr22))
        ->assertOk()
        ->assertSee('Main PR22')
        ->assertDontSee('>Primary<', false);
});

it('clears the main-series slot when a rifle is archived', function () {
    $user = rifleMember();
    $rifle = makeRifle($user, ['nickname' => 'Creedmoor', 'primary_series' => 'PRS']);

    $this->actingAs($user)
        ->delete(route('rifle-configurations.destroy', $rifle))
        ->assertRedirect(route('rifle-configurations.index'));

    expect($rifle->fresh()->is_active)->toBeFalse()
        ->and($rifle->fresh()->primary_series)->toBeNull();
});

it('saves a main rifle as visible on the shooter profile when asked', function () {
    $user = rifleMember();

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => 'Creedmoor',
            'primary_series' => 'PRS',
            'show_on_profile' => '1',
        ]);

    $rifle = RifleConfiguration::query()->where('nickname', 'Creedmoor')->first();

    expect($rifle->primary_series)->toBe('PRS')
        ->and($rifle->show_on_profile)->toBeTrue();
});

it('does not show a main rifle on the shooter profile unless opted in', function () {
    $user = rifleMember();

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => 'Creedmoor',
            'primary_series' => 'PRS',
        ]);

    expect(RifleConfiguration::query()->where('nickname', 'Creedmoor')->value('show_on_profile'))->toBeFalse();
});

it('clears the profile flag when a rifle is no longer a main', function () {
    $user = rifleMember();
    $rifle = makeRifle($user, [
        'nickname' => 'Creedmoor',
        'primary_series' => 'PRS',
        'show_on_profile' => true,
    ]);

    $this->actingAs($user)
        ->put(route('rifle-configurations.update', $rifle), [
            'nickname' => 'Creedmoor',
            'primary_series' => '',
        ]);

    expect($rifle->fresh()->primary_series)->toBeNull()
        ->and($rifle->fresh()->show_on_profile)->toBeFalse();
});

it('stores all optional gear details on a rifle', function () {
    $user = rifleMember();

    $this->actingAs($user)
        ->post(route('rifle-configurations.store'), [
            'nickname' => 'Creedmoor',
            'primary_series' => 'PRS',
            'show_on_profile' => '1',
            'trigger_description' => "Bix'n Andy",
            'muzzle_brake_description' => 'Botnia Solutions',
            'bipod_description' => 'MDT Ckye Pod',
            'magazine_description' => 'MDT',
            'tripod_description' => 'Leofoto',
            'brass_description' => 'Lapua',
            'powder_description' => 'Hodgdon',
            'rangefinder_description' => 'Vortex',
            'gunsmith_description' => 'Preece Precision',
            'scope_mount_description' => 'Spuhr',
            'bag_description' => 'Wiebad',
            'chronograph_description' => 'Garmin Xero C1',
        ])
        ->assertRedirect();

    $rifle = RifleConfiguration::query()->where('nickname', 'Creedmoor')->first();

    expect($rifle->trigger_description)->toBe("Bix'n Andy")
        ->and($rifle->muzzle_brake_description)->toBe('Botnia Solutions')
        ->and($rifle->bipod_description)->toBe('MDT Ckye Pod')
        ->and($rifle->magazine_description)->toBe('MDT')
        ->and($rifle->tripod_description)->toBe('Leofoto')
        ->and($rifle->brass_description)->toBe('Lapua')
        ->and($rifle->powder_description)->toBe('Hodgdon')
        ->and($rifle->rangefinder_description)->toBe('Vortex')
        ->and($rifle->gunsmith_description)->toBe('Preece Precision')
        ->and($rifle->scope_mount_description)->toBe('Spuhr')
        ->and($rifle->bag_description)->toBe('Wiebad')
        ->and($rifle->chronograph_description)->toBe('Garmin Xero C1');
});

it('pre-selects the main PRS rifle when registering for a PRS match', function () {
    $user = rifleMember();
    $prs = makeRifle($user, ['nickname' => 'Creedmoor', 'primary_series' => 'PRS']);
    makeRifle($user, ['nickname' => 'Rimfire', 'primary_series' => 'PR22']);

    $division = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $match = MatchEvent::create([
        'name' => 'PRS Provincial',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'created_by' => $user->id,
    ]);
    $match->divisions()->attach($division->id);

    $this->actingAs($user)
        ->get(route('events.register', $match))
        ->assertOk()
        ->assertSee('value="'.$prs->id.'"', false)
        ->assertSee('selected', false)
        ->assertSee('(Main PRS)');
});
