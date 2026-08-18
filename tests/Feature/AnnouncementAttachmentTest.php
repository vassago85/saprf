<?php

/**
 * Private attachments on announcements. Uploads land on the `announcements`
 * disk (storage/app/announcements); downloads are gated to recipients on
 * the member side (CommunicationsController) and to Exco on the composer
 * side (AnnouncementController).
 *
 * Compose-time upload lives in AnnouncementController::store; delete /
 * download for Exco live on the same controller.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceType;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('announcements');
});

function attExco(): User
{
    // No Membership row → EnsureProfileComplete waves them through.
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function attMember(string $suffix): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user->fresh();
}

// ── Upload ─────────────────────────────────────────────────────────────────

it('stores uploaded attachments on the private disk', function () {
    $exco = attExco();

    $this->actingAs($exco)->post(route('announcements.store'), [
        'title' => 'With attachment',
        'body' => 'See attached',
        'category' => AnnouncementCategory::Announcement->value,
        'priority' => 'normal',
        'audiences' => [
            ['mode' => 'include', 'type' => AudienceType::Individual->value, 'value' => ['user_ids' => [$exco->id]]],
        ],
        'attachments' => [
            UploadedFile::fake()->create('policy.pdf', 20, 'application/pdf'),
        ],
    ])->assertRedirect();

    $announcement = Announcement::query()->firstWhere('title', 'With attachment');
    expect($announcement)->not->toBeNull();

    $attachment = $announcement->attachments()->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->filename)->toBe('policy.pdf');
    expect($attachment->mime)->toContain('pdf');

    Storage::disk('announcements')->assertExists($attachment->path);
});

// ── Delete ─────────────────────────────────────────────────────────────────

it('removes an attachment from a draft announcement', function () {
    $exco = attExco();

    $announcement = Announcement::create([
        'title' => 'Draft with file',
        'body' => 'x',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'created_by' => $exco->id,
    ]);

    Storage::disk('announcements')->put("{$announcement->id}/doc.pdf", 'body');

    $attachment = AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/doc.pdf",
        'filename' => 'doc.pdf',
        'mime' => 'application/pdf',
        'size' => 4,
    ]);

    $this->actingAs($exco)
        ->delete(route('announcements.attachment.destroy', [$announcement, $attachment]))
        ->assertRedirect();

    expect(AnnouncementAttachment::find($attachment->id))->toBeNull();
    Storage::disk('announcements')->assertMissing("{$announcement->id}/doc.pdf");
});

it('refuses to remove an attachment from a sent announcement', function () {
    $exco = attExco();

    $announcement = Announcement::create([
        'title' => 'Sent with file',
        'body' => 'x',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent,
        'created_by' => $exco->id,
        'sent_at' => now(),
    ]);

    Storage::disk('announcements')->put("{$announcement->id}/doc.pdf", 'body');

    $attachment = AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/doc.pdf",
        'filename' => 'doc.pdf',
        'mime' => 'application/pdf',
        'size' => 4,
    ]);

    $this->actingAs($exco)
        ->delete(route('announcements.attachment.destroy', [$announcement, $attachment]))
        ->assertRedirect();

    expect(AnnouncementAttachment::find($attachment->id))->not->toBeNull();
});

// ── Download ───────────────────────────────────────────────────────────────

it('lets a recipient member download an attachment', function () {
    $exco = attExco();
    $member = attMember('recip');

    $announcement = Announcement::create([
        'title' => 'Sent',
        'body' => 'x',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent,
        'created_by' => $exco->id,
        'sent_at' => now(),
    ]);

    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $member->id,
    ]);

    Storage::disk('announcements')->put("{$announcement->id}/doc.pdf", 'hello');

    $attachment = AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/doc.pdf",
        'filename' => 'doc.pdf',
        'mime' => 'application/pdf',
        'size' => 5,
    ]);

    $this->actingAs($member)
        ->get(route('communications.attachment', [$announcement, $attachment->id]))
        ->assertOk();
});

it('403s a non-recipient member trying to download', function () {
    $exco = attExco();
    $recipient = attMember('recip2');
    $outsider = attMember('outsider');

    $announcement = Announcement::create([
        'title' => 'Sent',
        'body' => 'x',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent,
        'created_by' => $exco->id,
        'sent_at' => now(),
    ]);

    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $recipient->id,
    ]);

    Storage::disk('announcements')->put("{$announcement->id}/doc.pdf", 'hello');

    $attachment = AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/doc.pdf",
        'filename' => 'doc.pdf',
        'mime' => 'application/pdf',
        'size' => 5,
    ]);

    $this->actingAs($outsider)
        ->get(route('communications.attachment', [$announcement, $attachment->id]))
        ->assertForbidden();
});

// ── CSV export ─────────────────────────────────────────────────────────────

it('exports outstanding acknowledgements as CSV', function () {
    $exco = attExco();

    $announcement = Announcement::create([
        'title' => 'Policy',
        'body' => 'x',
        'category' => AnnouncementCategory::PolicyChange,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent,
        'created_by' => $exco->id,
        'sent_at' => now(),
        'requires_acknowledgement' => true,
    ]);

    $ackedMember = attMember('acked');
    $outstandingMember = attMember('out');

    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $ackedMember->id,
        'acknowledged_at' => now(),
    ]);
    AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $outstandingMember->id,
        'acknowledged_at' => null,
    ]);

    $response = $this->actingAs($exco)
        ->get(route('announcements.outstanding-csv', $announcement))
        ->assertOk();

    $body = $response->getContent();
    expect($body)->toContain('SAPRF #');
    expect($body)->toContain($outstandingMember->email);
    expect($body)->not->toContain($ackedMember->email);
});
