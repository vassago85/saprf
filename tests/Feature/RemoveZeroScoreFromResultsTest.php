<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->md = User::factory()->create(['name' => 'Owner MD']);
    $this->md->assignRole('match_director');

    $this->otherMd = User::factory()->create(['name' => 'Someone Else']);
    $this->otherMd->assignRole('match_director');

    $this->member = User::factory()->create(['name' => 'Regular Member']);
    $this->member->assignRole('member');

    $this->match = MatchEvent::create([
        'name' => 'Test Match',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subDay()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->md->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
});

function makeZeroRemovalScore(int $matchId, string $name, float $day1 = 0): Score
{
    return Score::create([
        'match_id' => $matchId,
        'shooter_name' => $name,
        'day1_raw_score' => $day1,
        'status' => 'valid',
        'is_member' => false,
        'match_date' => now()->subDay()->toDateString(),
    ]);
}

it('lets the match director delete a zero-score row via the inline action', function () {
    $score = makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->md)
        ->delete(route('scores.remove-zero', $score));

    $response->assertRedirect();
    expect(Score::find($score->id))->toBeNull();
});

it('lets an admin delete a zero-score row on any match', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $score = makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->actingAs($admin)
        ->delete(route('scores.remove-zero', $score));

    $response->assertRedirect();
    expect(Score::find($score->id))->toBeNull();
});

it('blocks a match director who does not own the match', function () {
    $score = makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->otherMd)
        ->delete(route('scores.remove-zero', $score));

    $response->assertForbidden();
    expect(Score::find($score->id))->not->toBeNull();
});

it('blocks non-privileged members entirely', function () {
    $score = makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->member)
        ->delete(route('scores.remove-zero', $score));

    // The route sits inside the developer|exco|owner|admin|match_director
    // middleware group, so a member hits the middleware first.
    $response->assertForbidden();
    expect(Score::find($score->id))->not->toBeNull();
});

it('refuses to delete a non-zero score even for authorised users', function () {
    $score = makeZeroRemovalScore($this->match->id, 'Real Rachel', 42.5);

    $response = $this->actingAs($this->md)
        ->delete(route('scores.remove-zero', $score));

    $response->assertStatus(422);
    expect(Score::find($score->id))->not->toBeNull();
});

it('renders the inline trash affordance on the public results page for the match owner', function () {
    makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->md)
        ->get(route('events.show', $this->match));

    $response->assertOk()
        // The Alpine template must pass the can_remove flag through so a
        // trash button renders only for zero rows viewed by an admin/MD.
        ->assertSee('can_remove')
        ->assertSee('removeZeroScore')
        ->assertSee('removeZeroForm');
});

it('does not expose the remove affordance to anonymous visitors', function () {
    makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->get(route('events.show', $this->match));

    $response->assertOk()
        // The blade should still render, but the trash form + handler must
        // not leak into the page for unauthenticated viewers.
        ->assertDontSee('removeZeroScore')
        ->assertDontSee('removeZeroForm');
});

it('does not expose the remove affordance to non-owner match directors', function () {
    makeZeroRemovalScore($this->match->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->otherMd)
        ->get(route('events.show', $this->match));

    $response->assertOk()
        ->assertDontSee('removeZeroScore')
        ->assertDontSee('removeZeroForm');
});
