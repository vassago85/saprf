<?php

/**
 * End-to-end ExCo meeting workflow: create meeting -> add agenda -> add
 * minutes -> add follow-up action -> mark held -> close. Also covers
 * the "closed meeting is read-only" guarantee that the show template
 * relies on to hide edit controls.
 */

use App\Enums\ExcoActionStatus;
use App\Enums\ExcoAgendaItemVisibility;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoAction;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function meetingExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

it('creates a meeting and redirects to the working page', function () {
    $exco = meetingExco();

    $response = $this->actingAs($exco)->post(route('exco.meetings.store'), [
        'title' => 'ExCo — August 2026',
        'type' => ExcoMeetingType::Regular->value,
        'scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        'location' => 'Zoom',
        'attendance_notes' => 'Full board expected',
    ]);

    $response->assertRedirect();

    $meeting = ExcoMeeting::firstWhere('title', 'ExCo — August 2026');
    expect($meeting)->not->toBeNull()
        ->and($meeting->status)->toBe(ExcoMeetingStatus::Draft)
        ->and($meeting->created_by)->toBe($exco->id)
        ->and($meeting->location)->toBe('Zoom');
});

it('walks a meeting through the full lifecycle', function () {
    $exco = meetingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'Meeting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    // Add agenda item
    $this->actingAs($exco)->post(route('exco.meetings.agenda.store', $meeting), [
        'title' => 'Ratify budget',
        'briefing' => 'Please review before meeting',
        'visibility' => ExcoAgendaItemVisibility::Ordinary->value,
    ])->assertRedirect();

    $item = $meeting->agendaItems()->first();
    expect($item)->not->toBeNull()
        ->and($item->title)->toBe('Ratify budget')
        ->and($item->sort_order)->toBe(1);

    // Update it with minutes
    $this->actingAs($exco)->put(route('exco.meetings.agenda.update', [$meeting, $item]), [
        'title' => 'Ratify budget',
        'briefing' => 'Please review before meeting',
        'minutes' => 'Budget ratified unanimously.',
        'visibility' => ExcoAgendaItemVisibility::Ordinary->value,
    ])->assertRedirect();

    expect($item->fresh()->minutes)->toBe('Budget ratified unanimously.');

    // Add an action item on the meeting
    $this->actingAs($exco)->post(route('exco.meetings.actions.store', $meeting), [
        'title' => 'Send budget summary to members',
        'assigned_to' => $exco->id,
        'due_on' => now()->addWeek()->format('Y-m-d'),
        'agenda_item_id' => $item->id,
    ])->assertRedirect();

    $action = $meeting->actions()->first();
    expect($action)->not->toBeNull()
        ->and($action->status)->toBe(ExcoActionStatus::Open)
        ->and($action->agenda_item_id)->toBe($item->id);

    // Move meeting -> held
    $this->actingAs($exco)->post(route('exco.meetings.transition', $meeting), [
        'status' => 'held',
    ])->assertRedirect();

    expect($meeting->fresh()->status)->toBe(ExcoMeetingStatus::Held);

    // Move meeting -> closed
    $this->actingAs($exco)->post(route('exco.meetings.transition', $meeting), [
        'status' => 'closed',
    ])->assertRedirect();

    expect($meeting->fresh()->status)->toBe(ExcoMeetingStatus::Closed);
});

it('refuses illegal status transitions', function () {
    $exco = meetingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'M',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    // Cannot go draft -> closed directly
    $this->actingAs($exco)->post(route('exco.meetings.transition', $meeting), [
        'status' => 'closed',
    ])->assertRedirect();

    expect($meeting->fresh()->status)->toBe(ExcoMeetingStatus::Draft);
});

it('blocks edits on a closed meeting', function () {
    $exco = meetingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'Closed sitting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->put(route('exco.meetings.update', $meeting), [
        'title' => 'Renamed',
        'type' => ExcoMeetingType::Regular->value,
        'scheduled_at' => now()->format('Y-m-d H:i'),
    ])->assertRedirect();

    expect($meeting->fresh()->title)->toBe('Closed sitting');
});

it('cannot add agenda items to a closed meeting', function () {
    $exco = meetingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'Closed',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.agenda.store', $meeting), [
        'title' => 'Late addition',
    ])->assertRedirect();

    expect($meeting->agendaItems()->count())->toBe(0);
});

it('reorders agenda items', function () {
    $exco = meetingExco();

    $meeting = ExcoMeeting::create([
        'title' => 'M',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    $a = ExcoAgendaItem::create([
        'meeting_id' => $meeting->id,
        'sort_order' => 1,
        'title' => 'First',
        'visibility' => ExcoAgendaItemVisibility::Ordinary,
    ]);
    $b = ExcoAgendaItem::create([
        'meeting_id' => $meeting->id,
        'sort_order' => 2,
        'title' => 'Second',
        'visibility' => ExcoAgendaItemVisibility::Ordinary,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.agenda.move', [$meeting, $b]), [
        'direction' => 'up',
    ])->assertRedirect();

    expect($b->fresh()->sort_order)->toBe(1)
        ->and($a->fresh()->sort_order)->toBe(2);
});

it('deletes only draft meetings', function () {
    $exco = meetingExco();

    $draft = ExcoMeeting::create([
        'title' => 'Draft',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    $held = ExcoMeeting::create([
        'title' => 'Held',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Held,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->delete(route('exco.meetings.destroy', $draft))->assertRedirect();
    expect(ExcoMeeting::find($draft->id))->toBeNull();

    $this->actingAs($exco)->delete(route('exco.meetings.destroy', $held))->assertRedirect();
    expect(ExcoMeeting::find($held->id))->not->toBeNull();
});

it('toggles action status open <-> done', function () {
    $exco = meetingExco();

    $action = ExcoAction::create([
        'title' => 'Do the thing',
        'status' => ExcoActionStatus::Open,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->post(route('exco.actions.set-status', $action), [
        'status' => 'done',
    ])->assertRedirect();

    $action->refresh();
    expect($action->status)->toBe(ExcoActionStatus::Done)
        ->and($action->completed_at)->not->toBeNull();

    $this->actingAs($exco)->post(route('exco.actions.set-status', $action), [
        'status' => 'open',
    ])->assertRedirect();

    $action->refresh();
    expect($action->status)->toBe(ExcoActionStatus::Open)
        ->and($action->completed_at)->toBeNull();
});
