<?php

use App\Models\SelectionCycle;
use App\Models\User;

beforeEach(fn () => seedRoles());

function cycleFixture(): SelectionCycle
{
    return SelectionCycle::create([
        'series' => 'PR22', 'season' => '2027', 'championship_name' => 'IPRF WCH',
        'qualifying_period_start' => '2026-01-01', 'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00', 'results_freeze' => '2026-12-31',
        'status' => 'open',
    ]);
}

it('forbids plain members from the selection cycles index', function () {
    $u = User::factory()->create();
    $u->assignRole('member');

    $this->actingAs($u)->get('/selection/cycles')->assertForbidden();
});

it('allows iprf_selector to view cycles', function () {
    cycleFixture();
    $u = User::factory()->create();
    $u->assignRole('iprf_selector');

    $this->actingAs($u)->get('/selection/cycles')->assertOk();
});

it('allows exco to view cycles via Gate::before bypass', function () {
    cycleFixture();
    $u = User::factory()->create();
    $u->assignRole('exco');

    $this->actingAs($u)->get('/selection/cycles')->assertOk();
});

it('forbids iprf_selector from creating a cycle (owner-only)', function () {
    $u = User::factory()->create();
    $u->assignRole('iprf_selector');

    $this->actingAs($u)->get('/selection/cycles/create')->assertForbidden();
});

it('allows owner to create a cycle', function () {
    $u = User::factory()->create();
    $u->assignRole('owner');

    $this->actingAs($u)->get('/selection/cycles/create')->assertOk();
});
