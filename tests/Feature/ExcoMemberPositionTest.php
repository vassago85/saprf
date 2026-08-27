<?php

/**
 * ExCo members page: assigning a portfolio (Chair, Secretary,
 * Treasurer, Events Schedule, etc.) to a user with the exco/chair
 * role, and having that position render alongside their name in the
 * printable minutes and next to action item owners.
 */

use App\Enums\ExcoActionStatus;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoAction;
use App\Models\ExcoMeeting;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function memberPageExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

it('lists every user with the exco or chair role on the members page', function () {
    $viewer = memberPageExco();

    $peer = User::factory()->create(['name' => 'Andries Lategan']);
    $peer->assignRole(['chair', 'member']);

    $outsider = User::factory()->create(['name' => 'Random Punter']);
    $outsider->assignRole('member');

    $this->actingAs($viewer)
        ->get(route('exco.members.index'))
        ->assertOk()
        ->assertSee($viewer->name)
        ->assertSee('Andries Lategan')
        ->assertDontSee('Random Punter');
});

it('blocks non-exco / non-chair users from the members page', function () {
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $outsider->assignRole('member');

    $this->actingAs($outsider->fresh())
        ->get(route('exco.members.index'))
        ->assertForbidden();
});

it('saves a members position via the update route', function () {
    $viewer = memberPageExco();

    $target = User::factory()->create(['name' => 'Paul Charsley']);
    $target->assignRole(['exco', 'member']);

    $this->actingAs($viewer)
        ->put(route('exco.members.update', $target), ['exco_position' => 'Secretary'])
        ->assertRedirect(route('exco.members.index'))
        ->assertSessionHas('success');

    expect($target->fresh()->exco_position)->toBe('Secretary');
});

it('trims whitespace and treats an empty string as null (no portfolio)', function () {
    $viewer = memberPageExco();

    $target = User::factory()->create(['exco_position' => 'Secretary']);
    $target->assignRole(['exco', 'member']);

    $this->actingAs($viewer)
        ->put(route('exco.members.update', $target), ['exco_position' => '   '])
        ->assertRedirect();

    expect($target->fresh()->exco_position)->toBeNull();
});

it('refuses to update the position of a user without an exco role', function () {
    $viewer = memberPageExco();

    $target = User::factory()->create();
    $target->assignRole('member');

    $this->actingAs($viewer)
        ->put(route('exco.members.update', $target), ['exco_position' => 'Chair'])
        ->assertNotFound();

    expect($target->fresh()->exco_position)->toBeNull();
});

it('rejects a position longer than 100 characters', function () {
    $viewer = memberPageExco();

    $target = User::factory()->create();
    $target->assignRole(['exco', 'member']);

    $this->actingAs($viewer)
        ->put(route('exco.members.update', $target), [
            'exco_position' => str_repeat('X', 200),
        ])
        ->assertSessionHasErrors('exco_position');

    expect($target->fresh()->exco_position)->toBeNull();
});

it('renders each members position in the printable minutes', function () {
    $chair = User::factory()->create(['name' => 'Andries Lategan', 'exco_position' => 'Chair']);
    $chair->assignRole(['chair', 'exco']);

    $secretary = User::factory()->create(['name' => 'Paul Charsley', 'exco_position' => 'Secretary']);
    $secretary->assignRole(['exco', 'member']);

    $meeting = ExcoMeeting::create([
        'title' => 'ExCo — 26 August 2026',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $secretary->id,
    ]);

    $this->actingAs($secretary->fresh())
        ->get(route('exco.meetings.minutes.print', $meeting))
        ->assertOk()
        ->assertSeeInOrder(['Members', 'Andries Lategan', 'Chair', 'Paul Charsley', 'Secretary']);
});

it('appends the position to an action item owner in the printable minutes', function () {
    $secretary = User::factory()->create(['name' => 'Paul Charsley', 'exco_position' => 'Secretary']);
    $secretary->assignRole(['exco', 'member']);

    $owner = User::factory()->create(['name' => 'Warren Britnell', 'exco_position' => 'Events Schedule']);
    $owner->assignRole(['exco', 'member']);

    $meeting = ExcoMeeting::create([
        'title' => 'ExCo — 26 August 2026',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $secretary->id,
    ]);

    ExcoAction::create([
        'meeting_id' => $meeting->id,
        'title' => 'Publish the annual selection criteria',
        'assigned_to' => $owner->id,
        'status' => ExcoActionStatus::Open,
        'created_by' => $secretary->id,
    ]);

    $this->actingAs($secretary->fresh())
        ->get(route('exco.meetings.minutes.print', $meeting))
        ->assertOk()
        ->assertSeeInOrder(['Warren Britnell', '(Events Schedule)']);
});
