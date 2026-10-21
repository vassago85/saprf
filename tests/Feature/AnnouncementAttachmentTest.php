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
use App\Notifications\FederationAnnouncementNotification;
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

// ── Email attachments ──────────────────────────────────────────────────────

it('attaches announcement files to the notification email', function () {
    $member = attMember('mail');

    $announcement = Announcement::create([
        'title' => 'With file',
        'body' => 'See attached',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => attExco()->id,
    ]);

    Storage::disk('announcements')->put("{$announcement->id}/policy.pdf", 'pdf-bytes');
    Storage::disk('announcements')->put("{$announcement->id}/notes.txt", 'notes');

    AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/policy.pdf",
        'filename' => "policy.pdf\r\nBcc: evil@example.test",
        'mime' => 'application/pdf',
        'size' => 9,
    ]);
    AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/notes.txt",
        'filename' => 'notes.txt',
        'mime' => 'text/plain',
        'size' => 5,
    ]);

    $mail = (new FederationAnnouncementNotification($announcement))->toMail($member);

    expect($mail->attachments)->toHaveCount(2)
        ->and($mail->attachments[0]['options']['as'])->toBe('policy.pdf')
        ->and($mail->attachments[0]['options']['mime'])->toBe('application/pdf')
        ->and($mail->attachments[1]['options']['as'])->toBe('notes.txt')
        ->and($mail->introLines)->toContain('The files for this notice are attached to this email.');
});

it('leaves oversized announcement files as a portal download', function () {
    config(['mail.announcement_attachment_budget' => 8]);

    $member = attMember('budget');

    $announcement = Announcement::create([
        'title' => 'Too big',
        'body' => 'See portal',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => attExco()->id,
    ]);

    Storage::disk('announcements')->put("{$announcement->id}/big.pdf", str_repeat('x', 20));
    Storage::disk('announcements')->put("{$announcement->id}/ok.txt", 'short');

    AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/big.pdf",
        'filename' => 'big.pdf',
        'mime' => 'application/pdf',
        'size' => 20,
    ]);
    AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/ok.txt",
        'filename' => 'ok.txt',
        'mime' => 'text/plain',
        'size' => 5,
    ]);

    $mail = (new FederationAnnouncementNotification($announcement))->toMail($member);

    expect($mail->attachments)->toHaveCount(1)
        ->and($mail->attachments[0]['options']['as'])->toBe('ok.txt')
        ->and($mail->introLines)->toContain('The file for this notice is attached to this email.')
        ->and($mail->introLines)->toContain('One or more files were too large to attach. Open the notice in the portal to download them.');
});

it('embeds image attachments in the announcement email', function () {
    $member = attMember('inline-image');

    $announcement = Announcement::create([
        'title' => 'Worlds poster',
        'body' => 'South Africa hosts the championship.',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => attExco()->id,
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Storage::disk('announcements')->put("{$announcement->id}/poster.png", $png);

    $attachment = AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/poster.png",
        'filename' => 'Rondavels.png',
        'mime' => 'image/png',
        'size' => strlen($png),
    ]);

    $member->notifyNow(new FederationAnnouncementNotification($announcement));

    $sent = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1);

    $email = $sent->first()->getOriginalMessage();
    $html = $email->getHtmlBody();
    $cid = 'saprf-'.$attachment->id.'.png';

    expect($html)->toContain('src="cid:'.$cid.'"')
        ->and($html)->toContain('alt="Rondavels"');

    $inline = collect($email->getAttachments())->first(
        fn ($part) => $part->getDisposition() === 'inline',
    );

    expect($inline)->not->toBeNull()
        ->and($inline->getFilename())->toBe($cid)
        ->and($email->getTextBody())->not->toContain('<img');
});

it('still builds the notification email when an attachment file is missing', function () {
    $member = attMember('missing');

    $announcement = Announcement::create([
        'title' => 'Missing file',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => attExco()->id,
    ]);

    AnnouncementAttachment::create([
        'announcement_id' => $announcement->id,
        'path' => "{$announcement->id}/gone.pdf",
        'filename' => 'gone.pdf',
        'mime' => 'application/pdf',
        'size' => 4,
    ]);

    $mail = (new FederationAnnouncementNotification($announcement))->toMail($member);

    expect($mail->attachments)->toBeEmpty()
        ->and($mail->subject)->toBe($announcement->display_title);
});
