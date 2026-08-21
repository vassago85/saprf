<?php

use App\Enums\LadderVariable;
use App\Models\LadderSession;
use App\Models\User;
use App\Services\Ladder\LadderAnalysis;
use Livewire\Volt\Volt;

beforeEach(function () {
    seedRoles();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

it('creates a session, applies the paste, and toggles a step out of the fit', function () {
    // Controller creates a blank session.
    $this->actingAs($this->user)
        ->post(route('ladder-sessions.store'), [
            'name' => 'H4350 test',
            'variable' => 'charge_weight',
            'fired_on' => now()->toDateString(),
        ])
        ->assertRedirect();

    $session = LadderSession::forUser($this->user->id)->latest('id')->first();
    expect($session)->not->toBeNull();

    // Mount the Volt component with the created session.
    $paste = <<<'TXT'
40.0  2576.0
40.2  2586.3  2575.9  2584.6
40.4  2618.7  2608.8  2607.1
40.6  2611.6  2606.0  2634.7
40.8  2633.8  2620.6  2626.7
41.0  2652.8  2632.2
41.6  2709.6
TXT;

    $component = Volt::actingAs($this->user)
        ->test('ladder.session', ['session' => $session])
        ->set('paste', $paste)
        ->call('applyPaste');

    $session->refresh()->load('steps.shots');
    expect($session->steps)->toHaveCount(7);
    // Every step should carry the shots we pasted (excluding parse rejects).
    expect($session->steps->pluck('shots')->flatten()->count())->toBe(16);

    // Every step defaults to include_in_fit=true. Toggle 40.0, 40.2 and 41.6
    // out to reproduce the spec's base configuration.
    foreach ([40.0, 40.2, 41.6] as $value) {
        $step = $session->steps->first(fn ($s) => (float) $s->value === $value);
        $component->call('toggleStepInFit', $step->id);
    }

    // Recompute — slope should now match the spec's 51.25 fps/gr.
    $result = app(LadderAnalysis::class, [])::analyze($session->fresh(['steps.shots']));
    expect($result->trend->slope)->toBeGreaterThan(51.24)->toBeLessThan(51.26);

    // Excluding a shot should reduce that step's SD.
    $step406 = $session->steps->first(fn ($s) => (float) $s->value === 40.6);
    $sdBefore = collect($result->steps)->firstWhere('stepId', $step406->id)->sd;

    $highShot = $step406->shots->firstWhere('velocity_fps', 2634.7);
    $component->call('toggleShotExcluded', $highShot->id);

    $after = LadderAnalysis::analyze($session->fresh(['steps.shots']));
    $sdAfter = collect($after->steps)->firstWhere('stepId', $step406->id)->sd;
    expect($sdAfter)->toBeLessThan($sdBefore);
});

it('drops implausibly-low velocities and lines with fewer than two numbers', function () {
    $session = LadderSession::factory()->for($this->user)->create([
        'variable' => LadderVariable::ChargeWeight,
    ]);

    // First line has one lone number (rejected). Second has one plausible
    // velocity and one 42-fps garbage value (rejected). Third valid.
    $paste = <<<'TXT'
40.0
40.2 2586.3 42
40.4 2618.7 2608.8
TXT;

    Volt::actingAs($this->user)
        ->test('ladder.session', ['session' => $session])
        ->set('paste', $paste)
        ->call('applyPaste');

    $session->refresh()->load('steps.shots');
    // Only steps 40.2 and 40.4 make it in.
    expect($session->steps)->toHaveCount(2);
    // Step 40.2 has one usable velocity (the 42 gets dropped).
    $step402 = $session->steps->first(fn ($s) => (float) $s->value === 40.2);
    expect($step402->shots)->toHaveCount(1);
    expect((float) $step402->shots->first()->velocity_fps)->toBe(2586.3);
});

it('exports the analysis as CSV', function () {
    $session = LadderSession::factory()->for($this->user)->create([
        'variable' => LadderVariable::ChargeWeight,
    ]);
    $step = $session->steps()->create([
        'value' => 40.4,
        'include_in_fit' => true,
        'sort_order' => 0,
    ]);
    foreach ([2618.7, 2608.8, 2607.1] as $i => $v) {
        $step->shots()->create([
            'velocity_fps' => $v,
            'sequence' => $i,
            'excluded' => false,
        ]);
    }

    $response = $this->actingAs($this->user)
        ->get(route('ladder-sessions.export.csv', $session));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->getContent())->toContain('Charge (gr)');
    expect($response->getContent())->toContain('40.4');
});
