<?php

/**
 * Covers the restructured /matches/create flow:
 *
 *   - A minimal payload (name, type, level, date, entry fee) succeeds
 *     without the fields the create page no longer renders (status,
 *     cost estimator inputs, provincial dual-count etc.).
 *   - The match is saved as a Draft (`status = draft`, `published = false`)
 *     even though the form no longer carries a Status select.
 *   - The response redirects to the Settings tab (not the overview) so
 *     the MD lands on the tab where they finish setup.
 *   - A clear flash message tells the MD what to do next.
 */

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');
});

it('creates a draft match from the minimal create form and redirects to the settings tab', function () {
    $response = $this->actingAs($this->md)
        ->post(route('matches.store'), [
            // Just the fields the restructured create page renders.
            'name' => 'Minimal Draft Match',
            'match_type' => 'PRS',
            'series_level' => 'club',
            'match_date' => now()->addMonth()->format('Y-m-d'),
            'active_member_fee' => 250,
        ]);

    $match = MatchEvent::query()->where('name', 'Minimal Draft Match')->firstOrFail();

    $response->assertRedirect(route('matches.settings', $match))
        ->assertSessionHas('success', 'Draft created. Add fees, contact details and the public page here, then publish from the bar above.');

    expect($match->status)->toBe('draft')
        ->and((bool) $match->published)->toBeFalse()
        ->and((float) $match->active_member_fee)->toBe(250.0)
        ->and($match->match_type)->toBe('PRS')
        ->and($match->series_level)->toBe('club');
});

it('does not render a status select or cost estimator on the create page', function () {
    $this->actingAs($this->md)
        ->get(route('matches.create'))
        ->assertOk()
        ->assertDontSee('name="status"', escape: false)
        ->assertDontSee('Cost Estimator')
        ->assertDontSee('Projected Revenue')
        ->assertDontSee('estimated_shooters', escape: false)
        ->assertSee('Match entry fee (ZAR)')
        ->assertSee('Create match');
});
