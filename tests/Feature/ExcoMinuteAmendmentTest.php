<?php

/**
 * Proposed-change workflow for circulated ExCo minutes.
 *
 *   pending -> submitted by any ExCo user during the review window
 *   accepted / rejected -> resolved by the chair (with a note)
 *
 * The review window is (closed + circulated + not adopted); outside
 * that window the amendments API refuses new submissions and refuses
 * to resolve pending ones.
 */

use App\Enums\ExcoAgendaItemVisibility;
use App\Enums\ExcoAmendmentStatus;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\ExcoMinuteAmendment;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function amendmentExco(): User
{
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole(['exco', 'member']);

    return $u->fresh();
}

function circulatedMeeting(User $creator): ExcoMeeting
{
    $m = ExcoMeeting::create([
        'title' => 'August sitting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->subDay(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $creator->id,
    ]);
    $m->update([
        'minutes_circulated_at' => now(),
        'minutes_circulated_by' => $creator->id,
    ]);

    return $m->fresh();
}

function agendaFor(ExcoMeeting $meeting, string $title = 'Item one'): ExcoAgendaItem
{
    return ExcoAgendaItem::create([
        'meeting_id' => $meeting->id,
        'sort_order' => 1,
        'title' => $title,
        'minutes' => 'Original minute text.',
        'visibility' => ExcoAgendaItemVisibility::Ordinary,
    ]);
}

it('lets an exco member propose an amendment during the review window', function () {
    $exco = amendmentExco();
    $meeting = circulatedMeeting($exco);
    $item = agendaFor($meeting);

    $this->actingAs($exco)->post(route('exco.meetings.amendments.store', $meeting), [
        'agenda_item_id' => $item->id,
        'proposed_text' => 'In item 1, change "original" to "revised".',
    ])->assertRedirect();

    $amendment = ExcoMinuteAmendment::firstWhere('meeting_id', $meeting->id);
    expect($amendment)->not->toBeNull()
        ->and($amendment->status)->toBe(ExcoAmendmentStatus::Pending)
        ->and($amendment->proposed_by)->toBe($exco->id)
        ->and($amendment->agenda_item_id)->toBe($item->id);
});

it('refuses amendments before circulation', function () {
    $exco = amendmentExco();
    $meeting = ExcoMeeting::create([
        'title' => 'Closed',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => ExcoMeetingStatus::Closed,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.amendments.store', $meeting), [
        'proposed_text' => 'Anything',
    ])->assertRedirect();

    expect(ExcoMinuteAmendment::count())->toBe(0);
});

it('refuses amendments after adoption', function () {
    $exco = amendmentExco();
    $adopting = ExcoMeeting::create([
        'title' => 'September sitting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->addWeek(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);
    $meeting = circulatedMeeting($exco);
    $meeting->update([
        'minutes_adopted_at' => now(),
        'minutes_adopted_meeting_id' => $adopting->id,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.amendments.store', $meeting->fresh()), [
        'proposed_text' => 'Too late.',
    ])->assertRedirect();

    expect(ExcoMinuteAmendment::count())->toBe(0);
});

it('resolves a pending amendment as accepted with a chair note', function () {
    $exco = amendmentExco();
    $meeting = circulatedMeeting($exco);
    $item = agendaFor($meeting);

    $amendment = ExcoMinuteAmendment::create([
        'meeting_id' => $meeting->id,
        'agenda_item_id' => $item->id,
        'proposed_by' => $exco->id,
        'proposed_text' => 'Change X to Y.',
        'status' => ExcoAmendmentStatus::Pending,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.amendments.resolve', [$meeting, $amendment]), [
        'decision' => 'accepted',
        'notes' => 'Applied to item 1 minutes.',
    ])->assertRedirect();

    $amendment->refresh();
    expect($amendment->status)->toBe(ExcoAmendmentStatus::Accepted)
        ->and($amendment->resolved_by)->toBe($exco->id)
        ->and($amendment->resolution_notes)->toBe('Applied to item 1 minutes.');
});

it('resolves a pending amendment as rejected', function () {
    $exco = amendmentExco();
    $meeting = circulatedMeeting($exco);
    $amendment = ExcoMinuteAmendment::create([
        'meeting_id' => $meeting->id,
        'proposed_by' => $exco->id,
        'proposed_text' => 'Bad idea.',
        'status' => ExcoAmendmentStatus::Pending,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.amendments.resolve', [$meeting, $amendment]), [
        'decision' => 'rejected',
        'notes' => 'Not what was said.',
    ])->assertRedirect();

    expect($amendment->fresh()->status)->toBe(ExcoAmendmentStatus::Rejected);
});

it('lets the proposer withdraw their own pending amendment', function () {
    $exco = amendmentExco();
    $meeting = circulatedMeeting($exco);
    $amendment = ExcoMinuteAmendment::create([
        'meeting_id' => $meeting->id,
        'proposed_by' => $exco->id,
        'proposed_text' => 'Withdraw me.',
        'status' => ExcoAmendmentStatus::Pending,
    ]);

    $this->actingAs($exco)->delete(route('exco.meetings.amendments.destroy', [$meeting, $amendment]))
        ->assertRedirect();

    expect(ExcoMinuteAmendment::find($amendment->id))->toBeNull();
});

it('refuses withdrawal by someone other than the proposer', function () {
    $author = amendmentExco();
    $other = amendmentExco();
    $meeting = circulatedMeeting($author);
    $amendment = ExcoMinuteAmendment::create([
        'meeting_id' => $meeting->id,
        'proposed_by' => $author->id,
        'proposed_text' => 'Mine.',
        'status' => ExcoAmendmentStatus::Pending,
    ]);

    $this->actingAs($other)->delete(route('exco.meetings.amendments.destroy', [$meeting, $amendment]))
        ->assertRedirect();

    expect(ExcoMinuteAmendment::find($amendment->id))->not->toBeNull();
});

it('unlocks minutes editing on agenda items during the review window', function () {
    $exco = amendmentExco();
    $meeting = circulatedMeeting($exco);
    $item = agendaFor($meeting);

    $this->actingAs($exco)->put(route('exco.meetings.agenda.update', [$meeting, $item]), [
        'minutes' => 'Revised minute text.',
    ])->assertRedirect();

    expect($item->fresh()->minutes)->toBe('Revised minute text.');
});

it('keeps agenda item minutes locked once minutes are adopted', function () {
    $exco = amendmentExco();
    $adopting = ExcoMeeting::create([
        'title' => 'September',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->addWeek(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);
    $meeting = circulatedMeeting($exco);
    $meeting->update([
        'minutes_adopted_at' => now(),
        'minutes_adopted_meeting_id' => $adopting->id,
    ]);
    $item = agendaFor($meeting->fresh());
    $original = $item->minutes;

    $this->actingAs($exco)->put(route('exco.meetings.agenda.update', [$meeting->fresh(), $item]), [
        'minutes' => 'Trying to sneak an edit in.',
    ])->assertRedirect();

    expect($item->fresh()->minutes)->toBe($original);
});
