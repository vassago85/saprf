<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1]
    );

    $matchDate = Carbon::today()->subDays(14);

    // Simulate a day-1 provincial sibling that was imported under the old
    // "everyone counts" default: every score is currently graded 'valid'
    // via the match-level bypass, even though the shooter has an expired
    // membership.
    $this->match = MatchEvent::create([
        'name' => 'Legacy Day-1 Provincial',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => $matchDate,
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => $this->admin->id,
        'everyone_counts' => true,
    ]);

    $this->lapsedShooter = User::factory()->create(['name' => 'Lapsed Larry']);
    Membership::create([
        'user_id' => $this->lapsedShooter->id,
        'saprf_number' => 'SAPRF-TOGGLE-001',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => $matchDate->copy()->subYear(),
    ]);

    $this->paidShooter = User::factory()->create(['name' => 'Paid Penny']);
    Membership::create([
        'user_id' => $this->paidShooter->id,
        'saprf_number' => 'SAPRF-TOGGLE-002',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => $matchDate->copy()->addYear(),
    ]);
});

function toggleTestScore(MatchEvent $match, User $user, Carbon $matchDate): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 80,
        'placement' => 1,
        'division_id' => Division::where('slug', 'open')->value('id'),
        'match_date' => $matchDate,
        // Persisted result of the old bypass: bypass grade was 'valid'
        // + is_member=true. That is the state that has to change when
        // an admin unticks the box.
        'status' => 'valid',
        'is_member' => true,
        'validation_reason' => 'Match rule: all shooters count regardless of membership state on the day.',
    ]);
}

it('re-grades every score on the match when an admin unticks "everyone counts"', function () {
    $matchDate = Carbon::parse($this->match->match_date);

    $lapsedScore = toggleTestScore($this->match, $this->lapsedShooter, $matchDate);
    $paidScore = toggleTestScore($this->match, $this->paidShooter, $matchDate);

    $response = $this->actingAs($this->admin)->put(route('matches.update', $this->match), [
        'name' => $this->match->name,
        'match_type' => $this->match->match_type,
        'series' => $this->match->series,
        'series_level' => $this->match->series_level,
        'season' => $this->match->season,
        'province_id' => $this->match->province_id,
        'match_date' => $this->match->match_date->format('Y-m-d'),
        'status' => $this->match->status,
        'active_member_fee' => 0,
        // Old default was 1. Untick it.
        'everyone_counts' => 0,
        'divisions' => [Division::where('slug', 'open')->value('id')],
    ]);

    $response->assertRedirect(route('matches.show', $this->match));

    // Lapsed shooter should drop out of the "valid" pool: expired membership
    // + match date 14 days ago = past the 7-day renewal grace window.
    expect($lapsedScore->fresh()->status)->toBe('lapsed')
        ->and($lapsedScore->fresh()->is_member)->toBeFalse();

    // Paid member should stay valid — the bypass wasn't the only reason
    // they qualified, so removing it doesn't hurt them.
    expect($paidScore->fresh()->status)->toBe('valid')
        ->and($paidScore->fresh()->is_member)->toBeTrue();

    // And the flag itself is now off, so future imports/re-evaluations
    // apply the normal membership check.
    expect($this->match->fresh()->everyone_counts)->toBeFalse();
});

it('does not re-grade scores when the "everyone counts" flag is untouched', function () {
    $matchDate = Carbon::parse($this->match->match_date);

    $lapsedScore = toggleTestScore($this->match, $this->lapsedShooter, $matchDate);

    // Update a different, harmless field. Score status must not move.
    $response = $this->actingAs($this->admin)->put(route('matches.update', $this->match), [
        'name' => $this->match->name.' (renamed)',
        'match_type' => $this->match->match_type,
        'series' => $this->match->series,
        'series_level' => $this->match->series_level,
        'season' => $this->match->season,
        'province_id' => $this->match->province_id,
        'match_date' => $this->match->match_date->format('Y-m-d'),
        'status' => $this->match->status,
        'active_member_fee' => 0,
        'everyone_counts' => 1,
        'divisions' => [Division::where('slug', 'open')->value('id')],
    ]);

    $response->assertRedirect(route('matches.show', $this->match));

    expect($lapsedScore->fresh()->status)->toBe('valid');
});
