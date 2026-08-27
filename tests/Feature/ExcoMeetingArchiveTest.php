<?php

/**
 * Archive escape hatch for closed ExCo meetings. Draft/held keep hard
 * delete; only closed sittings can be archived (soft hide, reversible).
 */

use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\AuditLog;
use App\Models\ExcoMeeting;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function archiveExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function makeMeeting(User $creator, ExcoMeetingStatus $status): ExcoMeeting
{
    return ExcoMeeting::create([
        'title' => 'Meeting ' . fake()->uuid(),
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now(),
        'status' => $status,
        'created_by' => $creator->id,
    ]);
}

it('archives a closed meeting with a reason and audit logs it', function () {
    $exco = archiveExco();
    $meeting = makeMeeting($exco, ExcoMeetingStatus::Closed);

    $this->actingAs($exco)->post(route('exco.meetings.archive', $meeting), [
        'reason' => 'Duplicate of Meeting #4',
    ])->assertRedirect(route('exco.meetings.index'));

    $meeting->refresh();
    expect($meeting->isArchived())->toBeTrue()
        ->and($meeting->archived_by)->toBe($exco->id)
        ->and($meeting->archive_reason)->toBe('Duplicate of Meeting #4');

    expect(AuditLog::query()
        ->where('action_type', 'exco_meeting.archived')
        ->where('entity_id', $meeting->id)
        ->exists())->toBeTrue();
});

it('refuses to archive a draft or held meeting (use hard delete instead)', function () {
    $exco = archiveExco();
    $draft = makeMeeting($exco, ExcoMeetingStatus::Draft);
    $held = makeMeeting($exco, ExcoMeetingStatus::Held);

    $this->actingAs($exco)->post(route('exco.meetings.archive', $draft))
        ->assertRedirect();
    $this->actingAs($exco)->post(route('exco.meetings.archive', $held))
        ->assertRedirect();

    expect($draft->fresh()->isArchived())->toBeFalse()
        ->and($held->fresh()->isArchived())->toBeFalse();
});

it('unarchives an archived meeting and clears the metadata', function () {
    $exco = archiveExco();
    $meeting = makeMeeting($exco, ExcoMeetingStatus::Closed);
    $meeting->update([
        'archived_at' => now(),
        'archived_by' => $exco->id,
        'archive_reason' => 'Test',
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.unarchive', $meeting))
        ->assertRedirect(route('exco.meetings.show', $meeting));

    $meeting->refresh();
    expect($meeting->isArchived())->toBeFalse()
        ->and($meeting->archived_by)->toBeNull()
        ->and($meeting->archive_reason)->toBeNull();

    expect(AuditLog::query()
        ->where('action_type', 'exco_meeting.unarchived')
        ->where('entity_id', $meeting->id)
        ->exists())->toBeTrue();
});

it('hides archived meetings from the active index and shows them on the archived tab', function () {
    $exco = archiveExco();
    $active = makeMeeting($exco, ExcoMeetingStatus::Closed);
    $active->update(['title' => 'Active-Closed']);

    $archived = makeMeeting($exco, ExcoMeetingStatus::Closed);
    $archived->update([
        'title' => 'Archived-Closed',
        'archived_at' => now(),
        'archived_by' => $exco->id,
    ]);

    $this->actingAs($exco)->get(route('exco.meetings.index'))
        ->assertSee('Active-Closed')
        ->assertDontSee('Archived-Closed');

    $this->actingAs($exco)->get(route('exco.meetings.index', ['archived' => 1]))
        ->assertSee('Archived-Closed')
        ->assertDontSee('Active-Closed');
});

it('refuses hard delete of a closed meeting even before archiving', function () {
    $exco = archiveExco();
    $meeting = makeMeeting($exco, ExcoMeetingStatus::Closed);

    $this->actingAs($exco)->delete(route('exco.meetings.destroy', $meeting))
        ->assertRedirect();

    expect(ExcoMeeting::find($meeting->id))->not->toBeNull();
});

it('blocks circulation and adoption actions on an archived meeting', function () {
    $exco = archiveExco();
    $meeting = makeMeeting($exco, ExcoMeetingStatus::Closed);
    $meeting->update([
        'archived_at' => now(),
        'archived_by' => $exco->id,
    ]);

    $this->actingAs($exco)->post(route('exco.meetings.mark-circulated', $meeting))
        ->assertRedirect();

    expect($meeting->fresh()->minutesAreCirculated())->toBeFalse();
});
