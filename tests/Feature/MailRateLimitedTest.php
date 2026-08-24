<?php

/**
 * Wiring test for the mail throttle.
 *
 * Mailgun probation caps the domain at 100 messages/hour. AppServiceProvider
 * registers a "mail" rate limiter at 50/hour (with a 2/min burst cap) so
 * announcement / transactional notifications cannot exhaust the account.
 * Auth-critical mail (OTP + password reset) is DELIBERATELY excluded from
 * the limiter and queued on `high` so announcement bursts on `default`
 * cannot delay a user's login/reset email.
 *
 * Every non-auth notification opts into the limiter via RateLimited queue
 * middleware. This test locks that wiring in — future notifications added
 * without the middleware will visibly regress here.
 *
 * Note: the limiter itself is sized at 60/min and 500/hour in
 * AppServiceProvider (raised so announcement fan-out does not burn tries).
 */

use App\Models\Announcement;
use App\Models\ContactMessage;
use App\Models\MatchAnnouncement;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\SelectionAthlete;
use App\Models\User;
use App\Notifications\AccountHandoverInvitationNotification;
use App\Notifications\ContactMessageReceivedNotification;
use App\Notifications\EmailOtpNotification;
use App\Notifications\FederationAnnouncementNotification;
use App\Notifications\MatchAnnouncementNotification;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipConfirmedNotification;
use App\Notifications\MembershipExpiredNotification;
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

it('registers a "mail" rate limiter at 60/min and 500/hour', function () {
    $limiter = RateLimiter::limiter('mail');

    expect($limiter)->not->toBeNull('AppServiceProvider must register a "mail" rate limiter');

    $limits = $limiter(null);
    expect($limits)->toBeArray()->toHaveCount(2);

    expect($limits[0])->toBeInstanceOf(Limit::class);
    expect($limits[0]->maxAttempts)->toBe(60);
    expect($limits[0]->decaySeconds)->toBe(60);

    expect($limits[1])->toBeInstanceOf(Limit::class);
    expect($limits[1]->maxAttempts)->toBe(500);
    expect($limits[1]->decaySeconds)->toBe(3600);
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
    'MembershipExpiredNotification' => [
        fn () => new MembershipExpiredNotification(new Membership()),
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
    'FederationAnnouncementNotification' => [
        fn () => new FederationAnnouncementNotification(new Announcement()),
    ],
    'MatchAnnouncementNotification' => [
        fn () => new MatchAnnouncementNotification(new MatchAnnouncement(), new User()),
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

// ── Auth-critical mail: high queue, not the mail throttle ────────────

it('queues OTP and password reset on the high queue without the mail rate limiter', function (string $class) {
    // Queued so a large announcement burst on `default` cannot stall auth
    // mail. The worker drains `high` first (docker-compose*.yml). They must
    // NOT use RateLimited('mail') — that limiter is for broadcast volume.
    expect(is_subclass_of($class, ShouldQueue::class))
        ->toBeTrue("{$class} must implement ShouldQueue so it can ride the high queue.");

    $notification = match ($class) {
        EmailOtpNotification::class => new EmailOtpNotification('123456'),
        ResetPasswordNotification::class => new ResetPasswordNotification('reset-token'),
        default => throw new InvalidArgumentException($class),
    };

    expect($notification->queue)->toBe('high');

    if (method_exists($notification, 'middleware')) {
        $middleware = $notification->middleware();
        $rateLimited = collect($middleware)->first(fn ($m) => $m instanceof RateLimited);
        expect($rateLimited)->toBeNull("{$class} must not use the mail RateLimited middleware");
    }
})->with([
    EmailOtpNotification::class,
    ResetPasswordNotification::class,
]);
