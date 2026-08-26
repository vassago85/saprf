<?php

/**
 * Post-close lifecycle for the minutes:
 *
 *   closed  ->  circulated (Mark as circulated)
 *   closed  ->  circulated -> adopted (Mark as adopted, links to next
 *               sitting)
 *
 * Also verifies the printable minutes view renders for a held/closed
 * meeting and honours the ExCo role gate.
 */

use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoMeeting;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function lifecycleExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function makeClosedMeeting(User $creator, string $title = 'Closed sitting'): ExcoMeeting
{
    return ExcoMeeting::create([
        'title' => $title,
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $creator->id,
    ]);
}

it('renders the printable minutes view for a closed meeting', function () {
    $exco = lifecycleExco();
    $meeting = makeClosedMeeting($exco);

    $this->actingAs($exco)
        ->get(route('exco.meetings.minutes.print', $meeting))
        ->assertOk()
        ->assertSee($meeting->title)
        ->assertSee('Print / Save as PDF');
});

it('blocks the printable minutes view for non-exco', function () {
    $exco = lifecycleExco();
    $meeting = makeClosedMeeting($exco);

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member->fresh())
        ->get(route('exco.meetings.minutes.print', $meeting))
        ->assertForbidden();
});

it('marks minutes as circulated on a closed meeting', function () {
    $exco = lifecycleExco();
    $meeting = makeClosedMeeting($exco);

    expect($meeting->minutesAreCirculated())->toBeFalse();

    $this->actingAs($exco)
        ->post(route('exco.meetings.mark-circulated', $meeting))
        ->assertRedirect();

    $meeting->refresh();
    expect($meeting->minutesAreCirculated())->toBeTrue()
        ->and($meeting->minutes_circulated_by)->toBe($exco->id)
        ->and($meeting->minutes_circulated_at)->not->toBeNull();
});

it('refuses to circulate minutes of a meeting still in progress', function () {
    $exco = lifecycleExco();

    $meeting = ExcoMeeting::create([
        'title' => 'Held',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Held,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)
        ->post(route('exco.meetings.mark-circulated', $meeting))
        ->assertRedirect();

    expect($meeting->fresh()->minutesAreCirculated())->toBeFalse();
});

it('records adoption at a later sitting once minutes are circulated', function () {
    $exco = lifecycleExco();
    $august = makeClosedMeeting($exco, 'ExCo — August 2026');
    $september = ExcoMeeting::create([
        'title' => 'ExCo — September 2026',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->addWeek(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    // Adoption is refused until circulation has happened.
    $this->actingAs($exco)
        ->post(route('exco.meetings.mark-adopted', $august), [
            'adopted_at_meeting_id' => $september->id,
        ])
        ->assertRedirect();

    expect($august->fresh()->minutesAreAdopted())->toBeFalse();

    // Circulate, then adopt.
    $this->actingAs($exco)->post(route('exco.meetings.mark-circulated', $august));

    $this->actingAs($exco)
        ->post(route('exco.meetings.mark-adopted', $august), [
            'adopted_at_meeting_id' => $september->id,
        ])
        ->assertRedirect();

    $august->refresh();
    expect($august->minutesAreAdopted())->toBeTrue()
        ->and($august->minutes_adopted_meeting_id)->toBe($september->id)
        ->and($august->minutes_adopted_at)->not->toBeNull();
});

it('refuses self-adoption', function () {
    $exco = lifecycleExco();
    $meeting = makeClosedMeeting($exco);

    $this->actingAs($exco)->post(route('exco.meetings.mark-circulated', $meeting));

    $this->actingAs($exco)
        ->post(route('exco.meetings.mark-adopted', $meeting), [
            'adopted_at_meeting_id' => $meeting->id,
        ])
        ->assertRedirect();

    expect($meeting->fresh()->minutesAreAdopted())->toBeFalse();
});

it('validates adopted_at_meeting_id refers to a real meeting', function () {
    $exco = lifecycleExco();
    $meeting = makeClosedMeeting($exco);

    $this->actingAs($exco)->post(route('exco.meetings.mark-circulated', $meeting));

    $this->actingAs($exco)
        ->post(route('exco.meetings.mark-adopted', $meeting), [
            'adopted_at_meeting_id' => 999999,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('adopted_at_meeting_id');

    expect($meeting->fresh()->minutesAreAdopted())->toBeFalse();
});
