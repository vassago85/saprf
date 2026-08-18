<?php

/**
 * Member-side of the Notification Centre.
 *
 * The archive contract:
 *   - a member only sees announcements they were snapshotted onto
 *   - opening an announcement stamps read_at exactly once
 *   - acknowledgement is idempotent and only allowed on
 *     requires_acknowledgement announcements
 *   - attachment downloads 403 for non-recipients
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
});

function makeSentAnnouncement(User $creator, array $overrides = []): Announcement
{
    return Announcement::create(array_merge([
        'title' => 'Broadcast title',
        'body' => 'Broadcast body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent,
        'requires_acknowledgement' => false,
        'created_by' => $creator->id,
        'sent_at' => now(),
        'recipient_count' => 0,
    ], $overrides));
}

it('lists only announcements the member is a recipient of', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $other = User::factory()->create(['email_verified_at' => now()]);
    $other->assignRole('member');

    $mineAnnouncement = makeSentAnnouncement($creator, ['title' => 'For me']);
    $othersAnnouncement = makeSentAnnouncement($creator, ['title' => 'Not for me']);

    AnnouncementRecipient::create([
        'announcement_id' => $mineAnnouncement->id,
        'user_id' => $me->id,
    ]);
    AnnouncementRecipient::create([
        'announcement_id' => $othersAnnouncement->id,
        'user_id' => $other->id,
    ]);

    $response = $this->actingAs($me)->get(route('communications.index'));

    $response->assertOk()
        ->assertSee('For me')
        ->assertDontSee('Not for me');
});

it('marks a recipient row as read the first time the member opens it', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $announcement = makeSentAnnouncement($creator);
    $recipient = AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $me->id,
    ]);

    expect($recipient->read_at)->toBeNull();

    $this->actingAs($me)
        ->get(route('communications.show', $announcement))
        ->assertOk();

    $recipient->refresh();
    expect($recipient->read_at)->not->toBeNull();
});

it('does not let a member open an announcement they are not on', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $stranger->assignRole('member');

    $announcement = makeSentAnnouncement($creator);

    $this->actingAs($stranger)
        ->get(route('communications.show', $announcement))
        ->assertNotFound();
});

it('acknowledges a policy change only once', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'chair', 'member']);

    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $announcement = makeSentAnnouncement($creator, [
        'category' => AnnouncementCategory::PolicyChange,
        'requires_acknowledgement' => true,
    ]);
    $recipient = AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $me->id,
    ]);

    $this->actingAs($me)
        ->post(route('communications.acknowledge', $announcement))
        ->assertRedirect();

    $first = $recipient->fresh()->acknowledged_at;
    expect($first)->not->toBeNull();

    // A second ack must not overwrite the timestamp — the receipt has to
    // pin the moment of first acknowledgement.
    $this->travel(1)->minutes();
    $this->actingAs($me)
        ->post(route('communications.acknowledge', $announcement))
        ->assertRedirect();

    expect($recipient->fresh()->acknowledged_at->timestamp)->toBe($first->timestamp);
});

it('refuses acknowledgement when the announcement does not require it', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $announcement = makeSentAnnouncement($creator, ['requires_acknowledgement' => false]);
    $recipient = AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $me->id,
    ]);

    $this->actingAs($me)
        ->post(route('communications.acknowledge', $announcement))
        ->assertSessionHas('error');

    expect($recipient->fresh()->acknowledged_at)->toBeNull();
});

it('403s attachment downloads for non-recipients but serves them to recipients', function () {
    Storage::fake('announcements');
    Storage::disk('announcements')->put('example.pdf', 'fake pdf bytes');

    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $recipientUser = User::factory()->create(['email_verified_at' => now()]);
    $recipientUser->assignRole('member');

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $stranger->assignRole('member');

    $announcement = makeSentAnnouncement($creator);
    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $recipientUser->id,
    ]);

    $attachment = AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => 'example.pdf',
        'filename' => 'example.pdf',
        'mime' => 'application/pdf',
        'size' => 14,
    ]);

    $this->actingAs($stranger)
        ->get(route('communications.attachment', ['announcement' => $announcement, 'attachment' => $attachment->id]))
        ->assertForbidden();

    $this->actingAs($recipientUser)
        ->get(route('communications.attachment', ['announcement' => $announcement, 'attachment' => $attachment->id]))
        ->assertOk();
});

it('returns an unread count via the polling endpoint', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $announcement = makeSentAnnouncement($creator, ['title' => 'Pending read']);
    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $me->id,
    ]);

    $response = $this->actingAs($me)
        ->getJson(route('communications.unread-count'));

    $response->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('latest.0.title', 'Pending read');
});
