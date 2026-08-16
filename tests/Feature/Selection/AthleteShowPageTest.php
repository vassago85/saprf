<?php

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\User;
use App\Services\Selection\PolicyImportService;

beforeEach(fn () => seedRoles());

it('renders the admin athlete page when the declaration was submitted online', function () {
    $cycle = SelectionCycle::create([
        'series' => 'PR22',
        'season' => '2027',
        'championship_name' => 'IPRF PR22 Team World Championships 2027',
        'qualifying_period_start' => '2026-01-01',
        'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00',
        'results_freeze' => '2026-12-31',
        'status' => 'open',
        'evaluation_mode' => SelectionCycle::MODE_ASSUME_QUALIFIED,
    ]);
    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );
    $cycle = $cycle->fresh(['activePolicy']);

    $shooter = User::factory()->create(['name' => 'Alex Shooter']);
    $athlete = SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $shooter->id,
        'state' => SelectionAthlete::STATE_REGISTERED,
    ]);

    SelectionDeclaration::create([
        'selection_athlete_id' => $athlete->id,
        'submitted_at' => now(),
        'status' => SelectionDeclaration::STATUS_SUBMITTED,
        'form_data' => [
            'eligibility_to_compete_received' => true,
            'received_channel' => 'online_form',
            'attestations' => [
                'intention_to_participate' => true,
                'able_and_willing' => true,
                'satisfy_preconditions' => true,
                'no_impairment' => true,
            ],
            'signature' => 'Alex Shooter',
        ],
    ]);

    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('selection.cycles.athletes.show', [$cycle, $athlete]))
        ->assertOk()
        ->assertSee('Alex Shooter')
        ->assertSee('via online form')
        ->assertSee('ELG-05')
        ->assertSee('Intention to participate');
});
