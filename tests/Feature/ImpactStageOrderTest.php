<?php

use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Carbon;

it('lists impact stages in stage-number order on the public results', function () {
    $creator = User::factory()->create();
    $match = MatchEvent::create([
        'name' => 'Stage Order Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'club',
        'season' => (string) Carbon::now()->year,
        'match_date' => Carbon::today()->subDay(),
        'status' => 'completed',
        'published' => true,
        'match_director' => 'Director',
        'active_member_fee' => 250,
        'non_member_fee' => 400,
        'lapsed_member_fee' => 300,
        'created_by' => $creator->id,
    ]);

    Score::create([
        'match_id' => $match->id,
        'shooter_name' => 'Kevin Goncalves',
        'user_id' => $creator->id,
        'raw_score' => 44,
        'day1_raw_score' => 44,
        'status' => 'valid',
        'match_date' => $match->match_date,
        'raw_meta' => ['impact' => ['stages' => [
            ['name' => 'Stage 2 Stumps', 'points' => 12],
            ['name' => 'Stage 5 Drums', 'points' => 9],
            ['name' => 'Stage 1 Bench', 'points' => 12],
            ['name' => 'Stage 3 Boomsloop', 'points' => 11],
        ]]],
    ]);

    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertSeeInOrder([
            'Stage 1 Bench',
            'Stage 2 Stumps',
            'Stage 3 Boomsloop',
            'Stage 5 Drums',
        ]);
});
