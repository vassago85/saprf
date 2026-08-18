<?php

/**
 * Saved distribution lists — CRUD endpoints and cycle guard.
 *
 * The resolver already has separate coverage for saved-list expansion
 * (see AudienceResolverTest). Here we only care about the HTTP surface:
 *   - who can hit the routes (Exco/Chair only)
 *   - rules survive create/update
 *   - a list cannot save a saved_list rule pointing at itself
 */

use App\Enums\AudienceType;
use App\Models\SavedDistributionList;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function slistExco(): User
{
    // Deliberately no Membership row — EnsureProfileComplete only enforces
    // the SASCOC fields for paid+active memberships, so Exco staff with
    // no membership sail past it and the routes are reachable in tests.
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function slistMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user->fresh();
}

// ── Gating ─────────────────────────────────────────────────────────────────

it('blocks plain members from the saved lists index', function () {
    $this->actingAs(slistMember())
        ->get(route('saved-lists.index'))
        ->assertForbidden();
});

it('allows exco to see the saved lists index', function () {
    $this->actingAs(slistExco())
        ->get(route('saved-lists.index'))
        ->assertOk();
});

// ── CRUD ──────────────────────────────────────────────────────────────────

it('creates a saved list with rules', function () {
    $exco = slistExco();

    $response = $this->actingAs($exco)->post(route('saved-lists.store'), [
        'name' => 'All match directors',
        'description' => 'Everyone with match_director role',
        'rules' => [
            ['mode' => 'include', 'type' => 'role', 'value' => ['role' => 'match_director']],
        ],
    ]);

    $response->assertRedirect(route('saved-lists.index'));

    $list = SavedDistributionList::query()->firstWhere('name', 'All match directors');
    expect($list)->not->toBeNull();
    expect($list->rules)->toHaveCount(1);
    expect($list->rules[0]['type'])->toBe('role');
    expect($list->created_by)->toBe($exco->id);
});

it('updates a saved list', function () {
    $exco = slistExco();

    $list = SavedDistributionList::create([
        'name' => 'Original',
        'rules' => [['mode' => 'include', 'type' => 'active_members', 'value' => []]],
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->put(route('saved-lists.update', $list), [
        'name' => 'Renamed',
        'description' => 'New description',
        'rules' => [
            ['mode' => 'include', 'type' => 'role', 'value' => ['role' => 'exco']],
        ],
    ])->assertRedirect(route('saved-lists.index'));

    $list->refresh();
    expect($list->name)->toBe('Renamed');
    expect($list->rules[0]['value']['role'])->toBe('exco');
});

it('deletes (soft-deletes) a saved list', function () {
    $exco = slistExco();

    $list = SavedDistributionList::create([
        'name' => 'Doomed',
        'rules' => [['mode' => 'include', 'type' => 'all', 'value' => []]],
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->delete(route('saved-lists.destroy', $list))->assertRedirect();

    expect(SavedDistributionList::query()->find($list->id))->toBeNull();
    expect(SavedDistributionList::withTrashed()->find($list->id))->not->toBeNull();
});

// ── Cycle guard ────────────────────────────────────────────────────────────

it('rejects a saved list that references itself', function () {
    $exco = slistExco();

    $list = SavedDistributionList::create([
        'name' => 'Cycle candidate',
        'rules' => [['mode' => 'include', 'type' => 'active_members', 'value' => []]],
        'created_by' => $exco->id,
    ]);

    $response = $this->actingAs($exco)->put(route('saved-lists.update', $list), [
        'name' => 'Cycle candidate',
        'rules' => [
            ['mode' => 'include', 'type' => AudienceType::SavedList->value, 'value' => ['list_id' => $list->id]],
        ],
    ]);

    $response->assertSessionHasErrors('rules');

    $list->refresh();
    expect($list->rules[0]['type'])->toBe('active_members');
});
