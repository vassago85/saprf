<?php

/**
 * Wiring test for the mail throttle.
 *
 * Mailgun has no advertised hard rate limit on standard plans, but a
 * runaway loop or a match-director blast to hundreds of shooters can still
 * push through enough mail in a burst to bump into connection-pool
 * contention. AppServiceProvider registers a "mail" rate limiter capped at
 * 5/sec and 300/min, and every non-auth notification opts into it via the
 * RateLimited queue middleware. This test locks that wiring in — future
 * notifications added without the middleware will visibly regress here.
 *
 * Auth-critical mail (OTP + password reset) is DELIBERATELY excluded so
 * users are never delayed at login. The final assertion documents that.
 */

use App\Models\ContactMessage;
use App\Models\Membership;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\SelectionAthlete;
use App\Models\User;
use App\Notifications\AccountHandoverInvitationNotification;
use App\Notifications\ContactMessageReceivedNotification;
use App\Notifications\EmailOtpNotification;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipConfirmedNotification;
use App\Notifications\MembershipExpiringSoonNotification;
use App\Notifications\MembershipLapsedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SelectionDeclarationSubmittedNotification;
use App\Notifications\SponsoredEntryPaidNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;

// ── Limiter definition ──────────────────────────────────────────────

it('registers a "mail" rate limiter at 5/sec and 300/min', function () {
    $limiter = RateLimiter::limiter('mail');

    expect($limiter)->not->toBeNull('AppServiceProvider must register a "mail" rate limiter');

    $limits = $limiter(null);
    expect($limits)->toBeArray()->toHaveCount(2);

    // Both perSecond() and perMinute() store their window as decaySeconds
    // under the hood — 1 for the per-second cap, 60 for the per-minute cap.
    expect($limits[0])->toBeInstanceOf(Limit::class);
    expect($limits[0]->maxAttempts)->toBe(5);
    expect($limits[0]->decaySeconds)->toBe(1);

    expect($limits[1])->toBeInstanceOf(Limit::class);
    expect($limits[1]->maxAttempts)->toBe(300);
    expect($limits[1]->decaySeconds)->toBe(60);
});

// ── Per-notification wiring ─────────────────────────────────────────
//
// Every entry is a fully-constructed notification instance. Models are
// unsaved on purpose — we only exercise `middleware()`, so no DB is
// required and no toMail() rendering happens. Adding a new outbound
// notification? Wire it up and add it here so it can't silently ship
// without a throttle.

dataset('throttled_notifications', [
    'PaymentReceivedNotification' => [
        fn () => new PaymentReceivedNotification(new Payment(), 'membership'),
    ],
    'MembershipConfirmedNotification' => [
        fn () => new MembershipConfirmedNotification(new Membership()),
    ],
    'MembershipLapsedNotification' => [
        fn () => new MembershipLapsedNotification(new Membership()),
    ],
    'MembershipExpiringSoonNotification' => [
        fn () => new MembershipExpiringSoonNotification(new Membership(), 30),
    ],
    'MatchRegistrationConfirmedNotification' => [
        fn () => new MatchRegistrationConfirmedNotification(new MatchRegistration()),
    ],
    'SponsoredEntryPaidNotification' => [
        fn () => new SponsoredEntryPaidNotification(
            new MatchRegistration(),
            new Payment(),
            new User(),
        ),
    ],
    'AccountHandoverInvitationNotification' => [
        fn () => new AccountHandoverInvitationNotification(new User(), new User(), 'token'),
    ],
    'ContactMessageReceivedNotification' => [
        fn () => new ContactMessageReceivedNotification(new ContactMessage()),
    ],
    'MemberInvitationNotification' => [
        fn () => new MemberInvitationNotification('token'),
    ],
    'SelectionDeclarationSubmittedNotification' => [
        fn () => new SelectionDeclarationSubmittedNotification(new SelectionAthlete()),
    ],
]);

it('queues the notification and routes it through the mail rate limiter', function (Closure $make) {
    $notification = $make();

    expect($notification)->toBeInstanceOf(ShouldQueue::class);

    $middleware = $notification->middleware();
    expect($middleware)->toBeArray();

    $rateLimited = collect($middleware)->first(fn ($m) => $m instanceof RateLimited);
    expect($rateLimited)->not->toBeNull('every throttled notification must ship a RateLimited middleware');

    // The limiter name is stored on a protected property — poke it via
    // reflection so we catch anyone who accidentally hardwires the wrong
    // limiter (e.g. new RateLimited('default')) at review time.
    $ref = new ReflectionObject($rateLimited);
    $prop = $ref->getProperty('limiterName');
    $prop->setAccessible(true);
    expect($prop->getValue($rateLimited))->toBe('mail');
})->with('throttled_notifications');

// ── Auth-critical mail stays inline ─────────────────────────────────

it('does NOT queue OTP or password reset mail so users are never delayed at login', function (string $class) {
    // Class-level check only — no need to instantiate. If someone flips
    // one of these to ShouldQueue in a future refactor, this fails and
    // forces a conversation about the auth-latency trade-off first.
    expect(is_subclass_of($class, ShouldQueue::class))
        ->toBeFalse("{$class} must stay inline: queueing it adds worker-sleep latency before the user's OTP/reset link arrives.");
})->with([
    EmailOtpNotification::class,
    ResetPasswordNotification::class,
]);
