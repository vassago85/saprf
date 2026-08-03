<?php

/**
 * Locks in the rule that match directors can only touch matches they created.
 * Bypass roles (developer, exco, owner, admin) can edit any match.
 *
 * If a refactor breaks this contract, these tests fail immediately.
 */

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    // seedRoles() covers only the four base roles; add developer + exco so
    // the bypass tests can assign them.
    foreach (['developer', 'exco'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
});

function makeMatchOwnedBy(User $creator, Province $province): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
    ]);
}

// ── Match director restricted to own matches ─────────────────────────

it('lets a match director edit their own match', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $match = makeMatchOwnedBy($mdA, $this->province);

    expect($mdA->can('update', $match))->toBeTrue();
});

it('refuses to let a match director edit someone else\'s match', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $mdB = User::factory()->create();
    $mdB->assignRole('match_director');

    $match = makeMatchOwnedBy($mdA, $this->province);

    expect($mdB->can('update', $match))->toBeFalse();
});

it('returns 403 when a match director hits the edit URL for another MD\'s match', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $mdB = User::factory()->create();
    $mdB->assignRole('match_director');

    $match = makeMatchOwnedBy($mdA, $this->province);

    $this->actingAs($mdB)
        ->get(route('matches.edit', $match))
        ->assertForbidden();
});

it('lets a match director see the edit page for their own match', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');

    $match = makeMatchOwnedBy($md, $this->province);

    $this->actingAs($md)
        ->get(route('matches.edit', $match))
        ->assertOk();
});

it('scopes the match list so a pure match director only sees their own matches', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $mdB = User::factory()->create();
    $mdB->assignRole('match_director');

    $ownMatch = makeMatchOwnedBy($mdA, $this->province);
    $ownMatch->update(['name' => 'Own match by MD A']);

    $otherMatch = makeMatchOwnedBy($mdB, $this->province);
    $otherMatch->update(['name' => 'Other match by MD B']);

    $this->actingAs($mdA)
        ->get(route('matches.index'))
        ->assertOk()
        ->assertSee('Own match by MD A')
        ->assertDontSee('Other match by MD B');
});

// ── Bypass roles: developer, exco, owner, admin ───────────────────────

it('lets a developer edit any match', function () {
    $dev = User::factory()->create();
    $dev->assignRole('developer');

    $someoneElse = User::factory()->create();
    $someoneElse->assignRole('match_director');
    $match = makeMatchOwnedBy($someoneElse, $this->province);

    expect($dev->can('update', $match))->toBeTrue();
});

it('lets an owner edit any match', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $someoneElse = User::factory()->create();
    $someoneElse->assignRole('match_director');
    $match = makeMatchOwnedBy($someoneElse, $this->province);

    expect($owner->can('update', $match))->toBeTrue();
});

it('lets an exco member edit any match', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $someoneElse = User::factory()->create();
    $someoneElse->assignRole('match_director');
    $match = makeMatchOwnedBy($someoneElse, $this->province);

    expect($exco->can('update', $match))->toBeTrue();
});

it('lets an admin edit any match', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $someoneElse = User::factory()->create();
    $someoneElse->assignRole('match_director');
    $match = makeMatchOwnedBy($someoneElse, $this->province);

    expect($admin->can('update', $match))->toBeTrue();
});

// ── Members ──────────────────────────────────────────────────────────

it('refuses to let an ordinary member edit any match', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchOwnedBy($md, $this->province);

    expect($member->can('update', $match))->toBeFalse();
});

// ── Score entry inherits the update authorization ─────────────────────

it('refuses to let a match director enter scores on another MD\'s match', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $mdB = User::factory()->create();
    $mdB->assignRole('match_director');

    $match = makeMatchOwnedBy($mdA, $this->province);

    $this->actingAs($mdB)
        ->get(route('scores.entry', $match))
        ->assertForbidden();
});

it('lets a match director enter scores on their own match', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');

    $match = makeMatchOwnedBy($md, $this->province);

    $this->actingAs($md)
        ->get(route('scores.entry', $match))
        ->assertOk();
});

// ── Impact-scoring CSV export inherits update authorization ───────────

it('refuses impact-scoring CSV export for a match director on another MD\'s match', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $mdB = User::factory()->create();
    $mdB->assignRole('match_director');

    $match = makeMatchOwnedBy($mdA, $this->province);

    $this->actingAs($mdB)
        ->get(route('matches.export-impact-scoring', $match))
        ->assertForbidden();
});

// ── Match expenses inherit ownership check ────────────────────────────

it('refuses to let a match director add expenses to another MD\'s match', function () {
    $mdA = User::factory()->create();
    $mdA->assignRole('match_director');

    $mdB = User::factory()->create();
    $mdB->assignRole('match_director');

    $match = makeMatchOwnedBy($mdA, $this->province);

    $this->actingAs($mdB)
        ->post(route('match-expenses.store', $match), [
            'description' => 'Should be denied',
            'amount' => 100,
        ])
        ->assertForbidden();
});
