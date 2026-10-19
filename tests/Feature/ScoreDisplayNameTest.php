<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('admin');

    $this->junior = Division::firstOrCreate(
        ['slug' => 'junior'],
        ['name' => 'Junior', 'display_order' => 1, 'is_active' => true]
    );

    $this->match = MatchEvent::create([
        'name' => 'Name Correction Match',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ]);
});

it('lets an MD correct the scoreboard name while keeping the linked user', function () {
    $karen = User::factory()->create(['name' => 'Karen Watridge', 'email_verified_at' => now()]);

    $score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $karen->id,
        'shooter_name' => 'Karen Watridge',
        'division_id' => $this->junior->id,
        'day1_raw_score' => 40,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $this->match->match_date,
    ]);

    expect($score->display_name)->toBe('Karen Watridge');

    $this->actingAs($this->admin)
        ->patch(route('scores.display-name', $score), [
            'shooter_name' => 'Kaden Watridge',
        ])
        ->assertRedirect();

    $score->refresh();
    expect($score->shooter_name)->toBe('Kaden Watridge')
        ->and($score->user_id)->toBe($karen->id)
        ->and($score->display_name)->toBe('Kaden Watridge')
        ->and($score->raw_meta['impact']['kept_impact_name'])->toBeTrue()
        ->and($score->raw_meta['impact']['linked_entry_name'])->toBe('Karen Watridge');
});
