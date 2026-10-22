<?php

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageReceivedNotification;
use App\Notifications\ContactMessageReplyNotification;
use App\Services\SettingsService;
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

test('a valid submission is stored and notifies the secretary inbox', function () {
    Notification::fake();

    $member = User::factory()->create();
    $member->assignRole('member');

    $secretary = app(SettingsService::class)->secretaryEmail();
    expect($secretary)->not->toBeEmpty();

    $this->post(route('contact.store'), validSubmission())
        ->assertRedirect(route('contact.thanks'));

    $message = ContactMessage::first();
    expect($message)->not->toBeNull()
        ->and($message->spam_status)->toBe(ContactMessage::SPAM_CLEAN)
        ->and($message->email)->toBe('alice@example.com')
        ->and($message->subject)->toBe('Question about SAPRF membership');

    Notification::assertSentOnDemand(
        ContactMessageReceivedNotification::class,
        function ($notification, $channels, $notifiable) use ($secretary) {
            return ($notifiable->routes['mail'] ?? null) === $secretary;
        },
    );
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

test('submissions with 2 or more URLs are flagged as url_flood spam', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->post(route('contact.store'), validSubmission([
        'message' => 'Great site! Check out https://cheap-pills.example.com and also https://buy-crypto.example.top for amazing deals.',
    ]))->assertRedirect(route('contact.thanks'));

    $message = ContactMessage::first();
    expect($message->spam_status)->toBe(ContactMessage::SPAM_URL_FLOOD);

    Notification::assertNothingSent();
});

test('submissions with no URLs or just one URL are still accepted as clean', function () {
    Notification::fake();

    $this->post(route('contact.store'), validSubmission([
        'message' => 'I run https://my-club.org and would like to affiliate. Please advise.',
    ]))->assertRedirect(route('contact.thanks'));

    expect(ContactMessage::first()->spam_status)->toBe(ContactMessage::SPAM_CLEAN);
});

test('submissions with porn keywords are flagged as spam', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->post(route('contact.store'), validSubmission([
        'subject' => 'List of niche porn categories',
        'message' => 'Please visit our site for the best adult content around.',
    ]))->assertRedirect(route('contact.thanks'));

    expect(ContactMessage::first()->spam_status)->toBe(ContactMessage::SPAM_KEYWORD);
    Notification::assertNothingSent();
});

test('submissions from disposable email domains are flagged as spam', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Hit via a tenant-style subdomain to confirm the suffix-match works —
    // exactly the pattern of the live abuse ("bob@rio.olivia.fastemail.digital").
    $this->post(route('contact.store'), validSubmission([
        'email' => 'bob@rio.olivia.fastemail.digital',
        'email_confirmation' => 'bob@rio.olivia.fastemail.digital',
    ]))->assertRedirect(route('contact.thanks'));

    expect(ContactMessage::first()->spam_status)->toBe(ContactMessage::SPAM_DISPOSABLE_EMAIL);
    Notification::assertNothingSent();
});

test('normal email domains are not flagged as disposable', function () {
    Notification::fake();

    $this->post(route('contact.store'), validSubmission([
        'email' => 'legit@gmail.com',
        'email_confirmation' => 'legit@gmail.com',
    ]))->assertRedirect(route('contact.thanks'));

    expect(ContactMessage::first()->spam_status)->toBe(ContactMessage::SPAM_CLEAN);
});

test('a missing Turnstile token is flagged when Turnstile is configured', function () {
    Notification::fake();
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');
    $settings = app(\App\Services\SettingsService::class);
    $settings->set('turnstile_site_key', 'site', 'test');
    $settings->set('turnstile_secret', 'secret', 'test');
    $settings->set('turnstile_enabled', '1', 'test');
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');

    $this->post(route('contact.store'), validSubmission([
        // No 'cf-turnstile-response' field.
    ]))->assertRedirect(route('contact.thanks'));

    expect(ContactMessage::first()->spam_status)->toBe(ContactMessage::SPAM_TURNSTILE_FAILED);
    Notification::assertNothingSent();
});

test('a valid Turnstile token lets the submission through as clean', function () {
    Notification::fake();
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');
    $settings = app(\App\Services\SettingsService::class);
    $settings->set('turnstile_site_key', 'site', 'test');
    $settings->set('turnstile_secret', 'secret', 'test');
    $settings->set('turnstile_enabled', '1', 'test');
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');

    \Illuminate\Support\Facades\Http::fake([
        'challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true]),
    ]);

    $this->post(route('contact.store'), validSubmission([
        'cf-turnstile-response' => 'good-token',
    ]))->assertRedirect(route('contact.thanks'));

    expect(ContactMessage::first()->spam_status)->toBe(ContactMessage::SPAM_CLEAN);
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

test('empty All filters still list spam messages', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    ContactMessage::create([
        'first_name' => 'Spam', 'surname' => 'Bot', 'email' => 'spam@example.com',
        'subject' => 'Honeypot subject XYZ', 'message' => 'bot message content here',
        'spam_status' => ContactMessage::SPAM_HONEYPOT,
    ]);
    ContactMessage::create([
        'first_name' => 'Fast', 'surname' => 'Bot', 'email' => 'fast@example.com',
        'subject' => 'Too fast subject XYZ', 'message' => 'bot message content here',
        'spam_status' => ContactMessage::SPAM_TOO_FAST,
    ]);

    $this->actingAs($admin)
        ->get('/contact-messages?status=&handled=')
        ->assertOk()
        ->assertSee('Honeypot subject XYZ')
        ->assertSee('Too fast subject XYZ');
});

test('honeypot filter lists honeypot spam even with All handled', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    ContactMessage::create([
        'first_name' => 'Spam', 'surname' => 'Bot', 'email' => 'spam@example.com',
        'subject' => 'Honeypot only ABC', 'message' => 'bot message content here',
        'spam_status' => ContactMessage::SPAM_HONEYPOT,
    ]);

    $this->actingAs($admin)
        ->get('/contact-messages?status=honeypot&handled=')
        ->assertOk()
        ->assertSee('Honeypot only ABC');
});

test('default index hides spam under clean+unhandled', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    ContactMessage::create([
        'first_name' => 'Spam', 'surname' => 'Bot', 'email' => 'spam@example.com',
        'subject' => 'Hidden spam DEF', 'message' => 'bot message content here',
        'spam_status' => ContactMessage::SPAM_HONEYPOT,
    ]);

    $this->actingAs($admin)
        ->get(route('contact-messages.index'))
        ->assertOk()
        ->assertDontSee('Hidden spam DEF')
        ->assertSee('No enquiries match your filters.');
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
