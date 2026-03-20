<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;

beforeEach(function () {
    seedRoles();
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
});

// ── Match creation ──────────────────────────────────────────────────

test('match director can create match', function () {
    $user = User::factory()->create();
    $user->assignRole('match_director');

    expect($user->can('create', MatchEvent::class))->toBeTrue();
});

test('member cannot create match', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    expect($user->can('create', MatchEvent::class))->toBeFalse();
});

test('admin can create match', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->can('create', MatchEvent::class))->toBeTrue();
});

test('owner can create match', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    expect($user->can('create', MatchEvent::class))->toBeTrue();
});

// ── Score override (update policy) ──────────────────────────────────

test('admin can update score', function () {
    $province = Province::where('abbreviation', 'GP')->first();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $score = Score::create([
        'match_id' => createTestMatch($province)->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Test Shooter',
        'raw_score' => 85.000,
        'placement' => 3,
        'division' => 'Open',
        'match_date' => now(),
        'status' => 'pending',
    ]);

    expect($admin->can('update', $score))->toBeTrue();
});

test('member cannot update score', function () {
    $province = Province::where('abbreviation', 'GP')->first();
    $member = User::factory()->create();
    $member->assignRole('member');

    $score = Score::create([
        'match_id' => createTestMatch($province)->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Test Shooter',
        'raw_score' => 85.000,
        'placement' => 3,
        'division' => 'Open',
        'match_date' => now(),
        'status' => 'pending',
    ]);

    expect($member->can('update', $score))->toBeFalse();
});

test('match director cannot update score', function () {
    $province = Province::where('abbreviation', 'GP')->first();
    $director = User::factory()->create();
    $director->assignRole('match_director');

    $score = Score::create([
        'match_id' => createTestMatch($province)->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Test Shooter',
        'raw_score' => 85.000,
        'placement' => 3,
        'division' => 'Open',
        'match_date' => now(),
        'status' => 'pending',
    ]);

    expect($director->can('update', $score))->toBeFalse();
});

// ── Qualification rules (owner-only) ────────────────────────────────

test('owner can create qualification rules', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    expect($user->can('create', QualificationRule::class))->toBeTrue();
});

test('admin cannot create qualification rules', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->can('create', QualificationRule::class))->toBeFalse();
});

test('match director cannot create qualification rules', function () {
    $user = User::factory()->create();
    $user->assignRole('match_director');

    expect($user->can('create', QualificationRule::class))->toBeFalse();
});

test('member cannot create qualification rules', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    expect($user->can('create', QualificationRule::class))->toBeFalse();
});

// ── Route-level role middleware ──────────────────────────────────────

test('match director can access match creation route', function () {
    $user = User::factory()->create();
    $user->assignRole('match_director');

    $response = $this->actingAs($user)->get(route('matches.create'));

    expect($response->getStatusCode())->not->toBe(403);
});

test('member cannot access match creation route', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    $this->actingAs($user)
        ->get(route('matches.create'))
        ->assertForbidden();
});

test('admin can access score override route', function () {
    $province = Province::where('abbreviation', 'GP')->first();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $score = Score::create([
        'match_id' => createTestMatch($province)->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Test Shooter',
        'raw_score' => 85.000,
        'placement' => 3,
        'division' => 'Open',
        'match_date' => now(),
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->post(route('scores.override', $score), [
            'status' => 'valid',
            'reason' => 'Verified manually',
        ])
        ->assertRedirect();
});

test('member cannot access score override route', function () {
    $province = Province::where('abbreviation', 'GP')->first();
    $member = User::factory()->create();
    $member->assignRole('member');

    $score = Score::create([
        'match_id' => createTestMatch($province)->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Test Shooter',
        'raw_score' => 85.000,
        'placement' => 3,
        'division' => 'Open',
        'match_date' => now(),
        'status' => 'pending',
    ]);

    $this->actingAs($member)
        ->post(route('scores.override', $score), [
            'status' => 'valid',
            'reason' => 'Should not work',
        ])
        ->assertForbidden();
});

// ── Helper ──────────────────────────────────────────────────────────

function createTestMatch(Province $province): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Auth Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => User::factory()->create()->id,
    ]);
}
