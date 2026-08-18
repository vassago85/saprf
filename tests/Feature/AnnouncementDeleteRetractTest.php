<?php

/**
 * Covers the two "oops I made a mistake" paths that federation
 * announcements need to survive their first real send cycle:
 *
 *   1. **Delete** — hard-off-switch for a draft or cancelled row.
 *      Nobody was ever affected. Soft-deletes the announcement (row
 *      hidden by the SoftDeletes global scope) and wipes uploaded
 *      attachments from the `announcements` disk. Blocked on
 *      sent/sending/scheduled: those go through cancel or retract.
 *
 *   2. **Retract** — soft-off-switch for a *sent* row. The email is
 *      already in inboxes and can't be recalled, but we hide the
 *      /communications archive copy so the mistake stops living on
 *      the portal. Reason is required and captured in the audit log.
 *
 * Access control is handled at the route level by the existing
 * `role:developer|exco|chair` middleware — a plain member can never
 * reach these endpoints. We verify that middleware wall here too.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceType;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('announcements');
});

function drExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function drMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user->fresh();
}

function drMakeAnnouncement(User $creator, AnnouncementStatus $status = AnnouncementStatus::Draft): Announcement
{
    $announcement = Announcement::create([
        'title' => 'Test announcement ' . uniqid(),
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => $status,
        'created_by' => $creator->id,
        'sent_at' => $status === AnnouncementStatus::Sent ? now() : null,
    ]);

    return $announcement->fresh();
}

// ── Delete drafts / cancelled ──────────────────────────────────────────────

it('deletes a draft announcement and removes its attachments from disk', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco);

    $file = UploadedFile::fake()->create('policy.pdf', 20, 'application/pdf');
    $path = $file->store((string) $announcement->id, 'announcements');
    AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => $path,
        'filename' => 'policy.pdf',
        'mime' => 'application/pdf',
        'size' => 20,
    ]);

    Storage::disk('announcements')->assertExists($path);

    $this->actingAs($exco)
        ->delete(route('announcements.destroy', $announcement))
        ->assertRedirect(route('announcements.index'));

    expect(Announcement::query()->find($announcement->id))->toBeNull();
    expect(Announcement::withTrashed()->find($announcement->id))->not->toBeNull();
    Storage::disk('announcements')->assertMissing($path);
});

it('deletes a cancelled announcement', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Cancelled);

    $this->actingAs($exco)
        ->delete(route('announcements.destroy', $announcement))
        ->assertRedirect(route('announcements.index'));

    expect(Announcement::query()->find($announcement->id))->toBeNull();
});

it('refuses to delete a scheduled announcement', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Scheduled);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->delete(route('announcements.destroy', $announcement))
        ->assertRedirect(route('announcements.show', $announcement))
        ->assertSessionHas('error');

    expect(Announcement::query()->find($announcement->id))->not->toBeNull();
});

it('refuses to delete a sent announcement (use retract instead)', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->delete(route('announcements.destroy', $announcement))
        ->assertRedirect(route('announcements.show', $announcement))
        ->assertSessionHas('error');

    expect(Announcement::query()->find($announcement->id))->not->toBeNull();
});

it('refuses to delete a sending announcement', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sending);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->delete(route('announcements.destroy', $announcement))
        ->assertRedirect(route('announcements.show', $announcement))
        ->assertSessionHas('error');

    expect(Announcement::query()->find($announcement->id))->not->toBeNull();
});

it('writes an audit log entry when an announcement is deleted', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco);

    $this->actingAs($exco)->delete(route('announcements.destroy', $announcement));

    $log = AuditLog::query()
        ->where('action_type', 'announcement.deleted')
        ->where('entity_id', $announcement->id)
        ->first();

    expect($log)->not->toBeNull();
});

it('blocks a plain member from deleting via role middleware', function () {
    $exco = drExco();
    $member = drMember();
    $announcement = drMakeAnnouncement($exco);

    $this->actingAs($member)
        ->delete(route('announcements.destroy', $announcement))
        ->assertForbidden();

    expect(Announcement::query()->find($announcement->id))->not->toBeNull();
});

// ── Retract sent ──────────────────────────────────────────────────────────

it('retracts a sent announcement with a reason', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)
        ->post(route('announcements.retract', $announcement), [
            'reason' => 'Sent in error — corrected version to follow.',
        ])
        ->assertRedirect(route('announcements.show', $announcement));

    $announcement->refresh();

    expect($announcement->isRetracted())->toBeTrue()
        ->and($announcement->retracted_by)->toBe($exco->id)
        ->and($announcement->retraction_reason)->toBe('Sent in error — corrected version to follow.');
});

it('rejects a retract with no reason', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->post(route('announcements.retract', $announcement), ['reason' => ''])
        ->assertSessionHasErrors(['reason']);

    expect($announcement->fresh()->isRetracted())->toBeFalse();
});

it('rejects a retract with a reason that is too short', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->post(route('announcements.retract', $announcement), ['reason' => 'oops'])
        ->assertSessionHasErrors(['reason']);

    expect($announcement->fresh()->isRetracted())->toBeFalse();
});

it('refuses to retract a draft', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->post(route('announcements.retract', $announcement), [
            'reason' => 'Wrong lifecycle stage but still a valid reason string.',
        ])
        ->assertRedirect(route('announcements.show', $announcement))
        ->assertSessionHas('error');

    expect($announcement->fresh()->isRetracted())->toBeFalse();
});

it('refuses to retract twice', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)->post(route('announcements.retract', $announcement), [
        'reason' => 'First retraction — genuine mistake.',
    ]);

    $this->actingAs($exco)
        ->from(route('announcements.show', $announcement))
        ->post(route('announcements.retract', $announcement), [
            'reason' => 'Trying again after the first one landed.',
        ])
        ->assertSessionHas('error');
});

it('writes an audit log entry with the retraction reason', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)->post(route('announcements.retract', $announcement), [
        'reason' => 'Contains the wrong meeting date, sending correction.',
    ]);

    $log = AuditLog::query()
        ->where('action_type', 'announcement.retracted')
        ->where('entity_id', $announcement->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->reason)->toBe('Contains the wrong meeting date, sending correction.');
});

// ── Member-facing effect of retract ───────────────────────────────────────

it('hides a retracted announcement from the members communications index', function () {
    $exco = drExco();
    $member = drMember();

    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);
    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)->get(route('communications.index'))
        ->assertSee($announcement->title);

    $this->actingAs($exco)->post(route('announcements.retract', $announcement), [
        'reason' => 'Wrong link in step 3, correction coming.',
    ]);

    $this->actingAs($member)->get(route('communications.index'))
        ->assertDontSee($announcement->title);
});

it('returns 404 to a member trying to open a retracted announcement directly', function () {
    $exco = drExco();
    $member = drMember();

    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);
    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($exco)->post(route('announcements.retract', $announcement), [
        'reason' => 'Contains outdated info.',
    ]);

    $this->actingAs($member)->get(route('communications.show', $announcement))
        ->assertNotFound();
});

it('excludes retracted announcements from the unread-count endpoint', function () {
    $exco = drExco();
    $member = drMember();

    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);
    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $member->id,
    ]);

    $this->actingAs($member)->getJson(route('communications.unread-count'))
        ->assertOk()
        ->assertJsonPath('unread', 1);

    $this->actingAs($exco)->post(route('announcements.retract', $announcement), [
        'reason' => 'Mistaken send — cleaning up.',
    ]);

    $this->actingAs($member)->getJson(route('communications.unread-count'))
        ->assertOk()
        ->assertJsonPath('unread', 0);
});

it('keeps the retracted row visible to admins with a retracted badge', function () {
    $exco = drExco();
    $announcement = drMakeAnnouncement($exco, AnnouncementStatus::Sent);

    $this->actingAs($exco)->post(route('announcements.retract', $announcement), [
        'reason' => 'Wrong file attached to the send.',
    ]);

    $this->actingAs($exco)->get(route('announcements.index'))
        ->assertSee($announcement->title)
        ->assertSee('Retracted');
});
