<?php

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageReceivedNotification;
use App\Notifications\ContactMessageReplyNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    seedRoles();
    RateLimiter::clear('contact-form:127.0.0.1');
});

function validSubmission(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Alice',
        'surname' => 'Shooter',
        'email' => 'alice@example.com',
        'email_confirmation' => 'alice@example.com',
        'subject' => 'Question about SAPRF membership',
        'message' => 'I would like to know how to renew my SAPRF membership.',
        'hp_field' => '',
        'hp_started_at' => (string) (now()->subMinutes(2)->getTimestamp()),
    ], $overrides);
}

test('the contact form is publicly reachable', function () {
    $this->get(route('contact.create'))
        ->assertOk()
        ->assertSee('Contact Us', escape: false)
        ->assertSee('Message');
});

test('a valid submission is stored and notifies admin recipients', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $owner = User::factory()->create();
    $owner->assignRole('owner');
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->post(route('contact.store'), validSubmission())
        ->assertRedirect(route('contact.thanks'));

    $message = ContactMessage::first();
    expect($message)->not->toBeNull()
        ->and($message->spam_status)->toBe(ContactMessage::SPAM_CLEAN)
        ->and($message->email)->toBe('alice@example.com')
        ->and($message->subject)->toBe('Question about SAPRF membership');

    Notification::assertSentTo([$admin, $owner], ContactMessageReceivedNotification::class);
    Notification::assertNotSentTo([$member], ContactMessageReceivedNotification::class);
});

test('the honeypot field silently marks a submission as spam and skips the notification', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->post(route('contact.store'), validSubmission(['hp_field' => 'https://spam.example.com']))
        ->assertRedirect(route('contact.thanks'));

    $message = ContactMessage::first();
    expect($message)->not->toBeNull()
        ->and($message->spam_status)->toBe(ContactMessage::SPAM_HONEYPOT);

    Notification::assertNothingSent();
});

test('a submission faster than the min fill time is flagged as too fast', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->post(route('contact.store'), validSubmission([
        'hp_started_at' => (string) now()->getTimestamp(),
    ]))->assertRedirect(route('contact.thanks'));

    $message = ContactMessage::first();
    expect($message->spam_status)->toBe(ContactMessage::SPAM_TOO_FAST);

    Notification::assertNothingSent();
});

test('email must be confirmed', function () {
    $this->post(route('contact.store'), validSubmission([
        'email' => 'alice@example.com',
        'email_confirmation' => 'DIFFERENT@example.com',
    ]))->assertSessionHasErrors('email');

    expect(ContactMessage::count())->toBe(0);
});

test('required fields are enforced', function () {
    $this->post(route('contact.store'), [])
        ->assertSessionHasErrors(['first_name', 'surname', 'email', 'subject', 'message']);

    expect(ContactMessage::count())->toBe(0);
});

test('a message is short-blocked when too short', function () {
    $this->post(route('contact.store'), validSubmission(['message' => 'hi']))
        ->assertSessionHasErrors('message');
});

test('rate limiting blocks after 5 submissions from the same IP within an hour', function () {
    Notification::fake();
    RateLimiter::clear('contact-form:127.0.0.1');

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('contact.store'), validSubmission([
            'email' => "user{$i}@example.com",
            'email_confirmation' => "user{$i}@example.com",
        ]))->assertRedirect(route('contact.thanks'));
    }

    $this->post(route('contact.store'), validSubmission([
        'email' => 'sixth@example.com',
        'email_confirmation' => 'sixth@example.com',
    ]))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ContactMessage::count())->toBe(5);
});

// ── Admin surface ───────────────────────────────────────────────────

test('an admin can view the contact-messages index', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    ContactMessage::create([
        'first_name' => 'Bob', 'surname' => 'Byte', 'email' => 'bob@example.com',
        'subject' => 'A question', 'message' => 'test message here',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    $this->actingAs($admin)
        ->get(route('contact-messages.index'))
        ->assertOk()
        ->assertSee('A question')
        ->assertSee('Bob Byte');
});

test('a member cannot view the contact-messages index', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('contact-messages.index'))
        ->assertForbidden();
});

test('an admin can mark a message as handled', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $message = ContactMessage::create([
        'first_name' => 'Bob', 'surname' => 'Byte', 'email' => 'bob@example.com',
        'subject' => 'A question', 'message' => 'test message here',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    $this->actingAs($admin)
        ->post(route('contact-messages.mark-handled', $message), ['handled_notes' => 'Replied via email'])
        ->assertRedirect(route('contact-messages.show', $message));

    $message->refresh();
    expect($message->handled_at)->not->toBeNull()
        ->and($message->handled_by)->toBe($admin->id)
        ->and($message->handled_notes)->toBe('Replied via email');
});

test('opening a clean contact message does not mark it as handled', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $message = ContactMessage::create([
        'first_name' => 'Bob', 'surname' => 'Byte', 'email' => 'bob@example.com',
        'subject' => 'A question', 'message' => 'test message here',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    $this->actingAs($admin)
        ->get(route('contact-messages.show', $message))
        ->assertOk()
        ->assertSee('Mark as handled');

    $message->refresh();
    expect($message->handled_at)->toBeNull();
});

test('an admin can send a mailgun reply without marking the enquiry handled', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $message = ContactMessage::create([
        'first_name' => 'Bob', 'surname' => 'Byte', 'email' => 'bob@example.com',
        'subject' => 'A question', 'message' => 'test message here',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    $this->actingAs($admin)
        ->post(route('contact-messages.reply', $message), [
            'reply' => 'Thanks for your enquiry. We will get back to you shortly.',
        ])
        ->assertRedirect(route('contact-messages.show', $message))
        ->assertSessionHas('success');

    Notification::assertSentOnDemand(ContactMessageReplyNotification::class);

    $message->refresh();
    expect($message->handled_at)->toBeNull();
});

test('a reply cannot be sent for spam enquiries', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $message = ContactMessage::create([
        'first_name' => 'Bob', 'surname' => 'Byte', 'email' => 'bob@example.com',
        'subject' => 'A question', 'message' => 'test message here',
        'spam_status' => ContactMessage::SPAM_HONEYPOT,
    ]);

    $this->actingAs($admin)
        ->post(route('contact-messages.reply', $message), ['reply' => 'Hello there'])
        ->assertRedirect()
        ->assertSessionHas('error');

    Notification::assertNothingSent();
});

test('opening a spam contact message does not mark it as handled', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $message = ContactMessage::create([
        'first_name' => 'Bob', 'surname' => 'Byte', 'email' => 'bob@example.com',
        'subject' => 'A question', 'message' => 'test message here',
        'spam_status' => ContactMessage::SPAM_HONEYPOT,
    ]);

    $this->actingAs($admin)
        ->get(route('contact-messages.show', $message))
        ->assertOk()
        ->assertSee('Mark as handled');

    $message->refresh();
    expect($message->handled_at)->toBeNull();
});
