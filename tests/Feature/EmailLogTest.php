<?php

/**
 * Verifies the outbound-mail audit log:
 *
 *   - Every sent notification lands in `email_logs` with the right
 *     recipient, subject, notification class, and mailer.
 *   - `email_log_id` is injected into the outgoing message's
 *     X-Mailgun-Variables header (so webhook correlation works).
 *   - The row is created in `queued` state by LogSendingMail and
 *     flipped to `sent` by LogSentMail.
 *   - Bodies for sensitive notification classes (password reset, OTP,
 *     invitation) are NEVER persisted, and the row is flagged
 *     `body_redacted = true`.
 *   - The webhook consumer moves the row through delivered / bounced /
 *     complained on incoming Mailgun events (correlation is by our
 *     injected email_log_id user-variable).
 *   - The admin outbox page is gated to developer / owner / exco.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Config::set('services.mailgun.webhook_signing_key', 'test-signing-key');
});

function excoUser(): User
{
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole(['exco', 'member']);
    return $u;
}

function memberUser(string $email = 'member@example.test'): User
{
    $u = User::factory()->create(['email_verified_at' => now(), 'email' => $email]);
    $u->assignRole('member');
    return $u;
}

// ── LogSendingMail / LogSentMail ────────────────────────────────────────────

it('records a row in email_logs for a plain notification', function () {
    $member = memberUser('recorded@example.test');

    // Notification::fake short-circuits before MessageSending fires, so
    // send synchronously through the real mailer using the `array` driver.
    Config::set('mail.default', 'array');

    $member->notifyNow(new ResetPasswordNotification('some-fake-token'));

    $log = EmailLog::query()->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->to_email)->toBe('recorded@example.test');
    expect($log->notification_class)->toBe(ResetPasswordNotification::class);
    expect($log->status)->toBe(EmailLog::STATUS_SENT);
    expect($log->sent_at)->not->toBeNull();
});

it('redacts the body of a sensitive-class notification', function () {
    $member = memberUser('token@example.test');
    Config::set('mail.default', 'array');

    $member->notifyNow(new ResetPasswordNotification('super-secret-token-xyz'));

    $log = EmailLog::query()->latest('id')->first();
    expect($log->body_redacted)->toBeTrue();
    expect($log->body_html)->toBeNull();
    expect($log->body_preview)->toBeNull();
});

it('keeps the body for a non-sensitive federation announcement', function () {
    $exco = excoUser();
    $member = memberUser('audience@example.test');
    Config::set('mail.default', 'array');

    $announcement = Announcement::create([
        'title' => 'Public post',
        'body' => 'This is the public body.',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);
    $recipient = AnnouncementRecipient::create(['announcement_id' => $announcement->id, 'user_id' => $member->id]);
    $delivery = AnnouncementDelivery::create([
        'announcement_recipient_id' => $recipient->id,
        'channel' => DeliveryChannel::Mail,
        'status' => DeliveryStatus::Queued,
    ]);

    $member->notifyNow(new FederationAnnouncementNotification($announcement, $delivery));

    $log = EmailLog::query()->where('to_email', 'audience@example.test')->firstOrFail();
    expect($log->body_redacted)->toBeFalse();
    expect($log->body_html)->toContain('public body');
    expect($log->notification_class)->toBe(FederationAnnouncementNotification::class);
});

it('injects the email_log_id into X-Mailgun-Variables on the outgoing email', function () {
    $member = memberUser('injected@example.test');
    Config::set('mail.default', 'array');

    $member->notifyNow(new ResetPasswordNotification('t'));

    // The array mailer captures every Symfony message it received.
    $mailer = app('mailer');
    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($sent)->not->toBeEmpty();

    $email = $sent->last()->getOriginalMessage();
    $header = $email->getHeaders()->get('X-Mailgun-Variables');
    expect($header)->not->toBeNull();

    $vars = json_decode($header->getBodyAsString(), true);
    expect($vars)->toBeArray()->toHaveKey('email_log_id');

    $log = EmailLog::query()->latest('id')->first();
    expect($vars['email_log_id'])->toBe($log->id);
});

// ── Mailgun webhook → email_logs correlation ────────────────────────────────

function mailgunLogPayload(string $event, EmailLog $log, string $severity = '', string $reason = ''): array
{
    $timestamp = (string) time();
    $token = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $timestamp . $token, 'test-signing-key');

    $eventData = [
        'event' => $event,
        'recipient' => $log->to_email,
        'user-variables' => ['email_log_id' => $log->id, 'user_id' => $log->user_id],
    ];
    if ($severity !== '') {
        $eventData['severity'] = $severity;
    }
    if ($reason !== '') {
        $eventData['reason'] = $reason;
    }

    return [
        'signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => $signature],
        'event-data' => $eventData,
    ];
}

it('moves an email_logs row to Delivered on a delivered event', function () {
    $log = EmailLog::create([
        'to_email' => 'x@example.test',
        'subject' => 'hi',
        'status' => EmailLog::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $this->postJson('/webhooks/mailgun', mailgunLogPayload('delivered', $log))->assertOk();

    expect($log->fresh()->status)->toBe(EmailLog::STATUS_DELIVERED);
    expect($log->fresh()->delivered_at)->not->toBeNull();
});

it('moves an email_logs row to Bounced on a permanent failure', function () {
    $log = EmailLog::create([
        'to_email' => 'x@example.test',
        'subject' => 'hi',
        'status' => EmailLog::STATUS_SENT,
    ]);

    $this->postJson('/webhooks/mailgun', mailgunLogPayload('failed', $log, 'permanent', 'No such user'))->assertOk();

    $fresh = $log->fresh();
    expect($fresh->status)->toBe(EmailLog::STATUS_BOUNCED);
    expect($fresh->error)->toContain('No such user');
    expect($fresh->bounced_at)->not->toBeNull();
});

it('moves an email_logs row to Complained on a spam complaint', function () {
    $log = EmailLog::create([
        'to_email' => 'x@example.test',
        'subject' => 'hi',
        'status' => EmailLog::STATUS_SENT,
    ]);

    $this->postJson('/webhooks/mailgun', mailgunLogPayload('complained', $log))->assertOk();

    expect($log->fresh()->status)->toBe(EmailLog::STATUS_COMPLAINED);
    expect($log->fresh()->complained_at)->not->toBeNull();
});

it('does NOT roll a Bounced email_logs row back to Delivered', function () {
    $log = EmailLog::create([
        'to_email' => 'x@example.test',
        'subject' => 'hi',
        'status' => EmailLog::STATUS_SENT,
    ]);

    $this->postJson('/webhooks/mailgun', mailgunLogPayload('failed', $log, 'permanent'))->assertOk();
    expect($log->fresh()->status)->toBe(EmailLog::STATUS_BOUNCED);

    $this->postJson('/webhooks/mailgun', mailgunLogPayload('delivered', $log))->assertOk();
    expect($log->fresh()->status)->toBe(EmailLog::STATUS_BOUNCED);
});

// ── Admin outbox gating + rendering ─────────────────────────────────────────

it('blocks a plain member from the outbox', function () {
    $member = memberUser();

    $this->actingAs($member)->get(route('email-logs.index'))->assertStatus(403);
});

it('lets an exco see the outbox and open an entry', function () {
    $exco = excoUser();

    $log = EmailLog::create([
        'to_email' => 'seen@example.test',
        'subject' => 'A visible email',
        'status' => EmailLog::STATUS_DELIVERED,
        'notification_class' => FederationAnnouncementNotification::class,
        'body_html' => '<p>Look at me</p>',
    ]);

    $this->actingAs($exco)->get(route('email-logs.index'))
        ->assertOk()
        ->assertSee('seen@example.test')
        ->assertSee('A visible email');

    $this->actingAs($exco)->get(route('email-logs.show', $log))
        ->assertOk()
        ->assertSee('A visible email');
});

it('shows a redacted-body banner on the detail view for sensitive classes', function () {
    $exco = excoUser();

    $log = EmailLog::create([
        'to_email' => 'reset@example.test',
        'subject' => 'Reset your password',
        'status' => EmailLog::STATUS_SENT,
        'notification_class' => ResetPasswordNotification::class,
        'body_redacted' => true,
    ]);

    $this->actingAs($exco)->get(route('email-logs.show', $log))
        ->assertOk()
        ->assertSee('redacted', false);
});
