<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->open = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->ladies = Division::create([
        'slug' => 'ladies',
        'name' => 'Ladies',
        'is_active' => true,
        'display_order' => 2,
    ]);

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Score Entry Division Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $this->md->id,
    ]);

    $this->shooter = User::factory()->create([
        'name' => 'Aliza Mey',
        'division_id' => $this->open->id,
    ]);

    $this->registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'division_id' => $this->open->id,
        'shooter_name' => $this->shooter->name,
        'email' => $this->shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);
});

it('renders a division dropdown on the score entry page', function () {
    $this->actingAs($this->md)
        ->get(route('scores.entry', $this->match))
        ->assertOk()
        ->assertSee('name="entries[0][division_id]"', false)
        ->assertSee('Open')
        ->assertSee('Ladies');
});

it('saves the chosen division onto the score and the registration', function () {
    $this->actingAs($this->md)
        ->post(route('scores.entry.store', $this->match), [
            'entries' => [
                [
                    'user_id' => $this->shooter->id,
                    'day1' => 61,
                    'division_id' => $this->ladies->id,
                ],
            ],
        ])
        ->assertRedirect(route('scores.entry', $this->match));

    $score = Score::where('match_id', $this->match->id)
        ->where('user_id', $this->shooter->id)
        ->first();

    expect($score)->not->toBeNull()
        ->and((int) $score->division_id)->toBe($this->ladies->id)
        ->and((int) $this->registration->fresh()->division_id)->toBe($this->ladies->id)
        ->and((int) $this->shooter->fresh()->division_id)->toBe($this->open->id);
});

it('corrects division on an existing score without requiring a new score value', function () {
    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => $this->shooter->name,
        'raw_score' => 61,
        'day1_raw_score' => 61,
        'division_id' => $this->open->id,
        'match_date' => $this->match->match_date,
        'status' => 'pending',
    ]);

    $this->actingAs($this->md)
        ->post(route('scores.entry.store', $this->match), [
            'entries' => [
                [
                    'user_id' => $this->shooter->id,
                    'day1' => '',
                    'division_id' => $this->ladies->id,
                ],
            ],
        ])
        ->assertRedirect(route('scores.entry', $this->match));

    $score = Score::where('match_id', $this->match->id)
        ->where('user_id', $this->shooter->id)
        ->first();

    expect((int) $score->division_id)->toBe($this->ladies->id)
        ->and((float) $score->day1_raw_score)->toBe(61.0)
        ->and((int) $this->registration->fresh()->division_id)->toBe($this->ladies->id);
});

it('rejects a division that is not offered by the match', function () {
    $factory = Division::create([
        'slug' => 'factory',
        'name' => 'Factory',
        'is_active' => true,
        'display_order' => 3,
    ]);

    $this->match->divisions()->attach([$this->open->id, $this->ladies->id]);

    $this->actingAs($this->md)
        ->post(route('scores.entry.store', $this->match), [
            'entries' => [
                [
                    'user_id' => $this->shooter->id,
                    'day1' => 61,
                    'division_id' => $factory->id,
                ],
            ],
        ])
        ->assertSessionHasErrors('entries.0.division_id');

    expect(Score::where('match_id', $this->match->id)->count())->toBe(0)
        ->and((int) $this->registration->fresh()->division_id)->toBe($this->open->id);
});
