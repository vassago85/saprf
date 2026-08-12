<?php

use App\Models\SelectionAppeal;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionWaiver;
use App\Models\User;

beforeEach(fn () => seedRoles());

function seedCycleAndAthlete(): array
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22', 'season' => '2027', 'championship_name' => 'IPRF WCH',
        'qualifying_period_start' => '2026-01-01', 'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00', 'results_freeze' => '2026-12-31',
        'status' => 'open',
    ]);
    $shooter = User::factory()->create();
    $athlete = SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $shooter->id, 'state' => 'declared']);

    return [$cycle, $athlete];
}

it('records a waiver request and lets an owner decide it', function () {
    [$cycle, $athlete] = seedCycleAndAthlete();
    $selector = User::factory()->create(); $selector->assignRole('iprf_selector');
    $owner = User::factory()->create(); $owner->assignRole('owner');

    $this->actingAs($selector)
        ->post(route('selection.cycles.athletes.waivers.store', [$cycle, $athlete]), [
            'waived_rule_id' => 'PART-05',
            'request_text' => 'Missed SA champs — knee injury',
        ])
        ->assertRedirect();

    $waiver = SelectionWaiver::firstOrFail();
    expect($waiver->outcome)->toBe(SelectionWaiver::OUTCOME_PENDING);

    $this->actingAs($owner)
        ->put(route('selection.cycles.athletes.waivers.decide', [$cycle, $athlete, $waiver]), [
            'outcome' => 'granted', 'response_text' => 'Approved.',
        ])
        ->assertRedirect();

    expect($waiver->fresh()->outcome)->toBe(SelectionWaiver::OUTCOME_GRANTED);
});

it('records an appeal and only owner can decide', function () {
    [$cycle, $athlete] = seedCycleAndAthlete();
    $selector = User::factory()->create(); $selector->assignRole('iprf_selector');
    $owner = User::factory()->create(); $owner->assignRole('owner');

    $this->actingAs($selector)
        ->post(route('selection.cycles.athletes.appeals.store', [$cycle, $athlete]), [
            'lodged_at' => '2027-02-01', 'reason' => 'Team composition disputed',
            'fee_amount' => 5000, 'fee_paid_at' => '2027-02-01',
        ])
        ->assertRedirect();

    $appeal = SelectionAppeal::firstOrFail();
    expect($appeal->outcome)->toBe(SelectionAppeal::OUTCOME_PENDING);

    // Selector cannot decide.
    $this->actingAs($selector)
        ->put(route('selection.cycles.athletes.appeals.decide', [$cycle, $athlete, $appeal]), [
            'outcome' => 'upheld',
        ])
        ->assertForbidden();

    // Owner can.
    $this->actingAs($owner)
        ->put(route('selection.cycles.athletes.appeals.decide', [$cycle, $athlete, $appeal]), [
            'outcome' => 'upheld', 'refund_issued_at' => '2027-02-05',
        ])
        ->assertRedirect();

    expect($appeal->fresh()->outcome)->toBe(SelectionAppeal::OUTCOME_UPHELD);
});
