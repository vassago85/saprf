<?php

/**
 * MD "Message Entrants" broadcast — unified pipeline.
 *
 * Post-unification this route writes a normal `Announcement` row
 * (category = match_bulletin, retention = match_scoped, match_id
 * pinned) and hands off to the same publisher every federation
 * announcement uses. The behavioural contracts stay exactly what they
 * were before the unification:
 *
 *   1. Only confirmed + waitlisted entries are notified. Cancelled +
 *      pending never receive the blast.
 *   2. Managed juniors do NOT receive mail — their parent (via
 *      registered_by_user_id, falling back to parent_id) does. Two
 *      juniors registered by the same parent produce exactly one send.
 *   3. Match creator MDs, owners, admins, exco, developer can send. An
 *      MD of a *different* match hits 403.
 *   4. Subject + body validation is enforced; oversize bodies produce
 *      zero DB rows, zero audit rows, and zero notifications.
 *   5. Every send writes an Announcement + AuditLog and stores the
 *      exact recipient count.
 *
 * The old `MatchAnnouncementNotification` class + `match_announcements`
 * table are left in place for the backfill migration to reference; new
 * bulletins land in `announcements` and are dispatched via
 * `FederationAnnouncementNotification` like every other announcement.
 */

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'MD Announcement Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 550.00,
        'created_by' => $this->md->id,
    ]);
});

if (! function_exists('makeMdRegistration')) {
    /**
     * Local test helper for MD-bulletin coverage. Named with the `md`
     * prefix to avoid colliding with the differently-shaped
     * `makeRegistration()` helper in RegistrationPaymentRetryTest.
     */
    function makeMdRegistration(MatchEvent $match, User $user, string $status = 'confirmed', ?int $registeredBy = null): MatchRegistration
    {
        return MatchRegistration::create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'registered_by_user_id' => $registeredBy,
            'shooter_name' => $user->name,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 550,
            'payment_status' => 'paid',
            'registration_status' => $status,
            'registered_at' => now(),
        ]);
    }
}

// ── Scope: confirmed + waitlisted only ─────────────────────────────

it('notifies confirmed and waitlisted entrants but never cancelled or pending ones', function () {
    Notification::fake();

    $confirmed = User::factory()->create(['email_verified_at' => now()]);
    $waitlisted = User::factory()->create(['email_verified_at' => now()]);
    $pending = User::factory()->create(['email_verified_at' => now()]);
    $cancelled = User::factory()->create(['email_verified_at' => now()]);

    makeMdRegistration($this->match, $confirmed, 'confirmed');
    makeMdRegistration($this->match, $waitlisted, 'waitlisted');
    makeMdRegistration($this->match, $pending, 'pending');
    makeMdRegistration($this->match, $cancelled, 'cancelled');

    $this->actingAs($this->md)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Range briefing update',
            'body' => 'Please arrive at 07:00 for the safety briefing.',
        ])
        ->assertRedirect(route('matches.show', $this->match))
        ->assertSessionHas('success');

    Notification::assertSentTo($confirmed, FederationAnnouncementNotification::class);
    Notification::assertSentTo($waitlisted, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($pending, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($cancelled, FederationAnnouncementNotification::class);

    $announcement = Announcement::firstOrFail();
    expect($announcement->recipient_count)->toBe(2)
        ->and($announcement->match_id)->toBe($this->match->id)
        ->and($announcement->category->value)->toBe('match_bulletin')
        ->and($announcement->retention->value)->toBe('match_scoped');

    $audience = $announcement->audiences()->first();
    $audienceType = is_object($audience->type) ? $audience->type->value : $audience->type;
    expect($audienceType)->toBe('match_entrants')
        ->and($audience->value['status_scope'])->toBe(['confirmed', 'waitlisted']);
});

// ── Managed junior routing ────────────────────────────────────────

it('routes a managed junior\'s notification to the parent, not the junior', function () {
    Notification::fake();

    $parent = User::factory()->create(['email_verified_at' => now()]);
    $parent->assignRole('member');

    $junior = User::factory()->create([
        'is_managed_account' => true,
        'parent_id' => $parent->id,
        'managed_relationship' => 'junior',
    ]);

    makeMdRegistration($this->match, $junior, 'confirmed', registeredBy: $parent->id);

    $this->actingAs($this->md)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Junior parking is on the west side',
            'body' => 'Parents dropping juniors, use the west gate.',
        ])
        ->assertRedirect();

    Notification::assertSentTo($parent, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($junior, FederationAnnouncementNotification::class);

    expect(Announcement::firstOrFail()->recipient_count)->toBe(1);
});

it('falls back to parent_id when the managed junior was registered by someone other than the parent', function () {
    Notification::fake();

    $parent = User::factory()->create(['email_verified_at' => now()]);
    $parent->assignRole('member');

    $junior = User::factory()->create([
        'is_managed_account' => true,
        'parent_id' => $parent->id,
        'managed_relationship' => 'junior',
    ]);

    makeMdRegistration($this->match, $junior, 'confirmed', registeredBy: null);

    $this->actingAs($this->md)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Test',
            'body' => 'body',
        ]);

    Notification::assertSentTo($parent, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($junior, FederationAnnouncementNotification::class);
});

it('sends only one notification to a parent even when they have multiple managed juniors entered', function () {
    Notification::fake();

    $parent = User::factory()->create(['email_verified_at' => now()]);
    $parent->assignRole('member');

    $juniorA = User::factory()->create([
        'is_managed_account' => true,
        'parent_id' => $parent->id,
        'managed_relationship' => 'junior',
    ]);
    $juniorB = User::factory()->create([
        'is_managed_account' => true,
        'parent_id' => $parent->id,
        'managed_relationship' => 'junior',
    ]);

    makeMdRegistration($this->match, $juniorA, 'confirmed', registeredBy: $parent->id);
    makeMdRegistration($this->match, $juniorB, 'waitlisted', registeredBy: $parent->id);

    $this->actingAs($this->md)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Duplicate parent test',
            'body' => 'body',
        ]);

    Notification::assertSentToTimes($parent, FederationAnnouncementNotification::class, 1);

    expect(Announcement::firstOrFail()->recipient_count)->toBe(1);
});

// ── Authorization ─────────────────────────────────────────────────

it('blocks a match director from broadcasting on a match they do not own', function () {
    Notification::fake();

    $otherMd = User::factory()->create(['email_verified_at' => now()]);
    $otherMd->assignRole('match_director');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    makeMdRegistration($this->match, $shooter, 'confirmed');

    $this->actingAs($otherMd)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'trying to hijack',
            'body' => 'body',
        ])
        ->assertForbidden();

    Notification::assertNothingSent();
    expect(Announcement::count())->toBe(0);
});

it('blocks a plain member entirely', function () {
    Notification::fake();

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('matches.announcements.create', $this->match))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'nope',
            'body' => 'body',
        ])
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('lets an admin broadcast on any match', function () {
    Notification::fake();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    makeMdRegistration($this->match, $shooter, 'confirmed');

    $this->actingAs($admin)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Admin push',
            'body' => 'Federation-level notice.',
        ])
        ->assertRedirect(route('matches.show', $this->match));

    Notification::assertSentTo($shooter, FederationAnnouncementNotification::class);
    expect(Announcement::firstOrFail()->created_by)->toBe($admin->id);
});

// ── Validation ────────────────────────────────────────────────────

it('rejects a body longer than 5000 characters without writing anything', function () {
    Notification::fake();

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    makeMdRegistration($this->match, $shooter, 'confirmed');

    $this->actingAs($this->md)
        ->from(route('matches.announcements.create', $this->match))
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Too long',
            'body' => str_repeat('a', 5001),
        ])
        ->assertRedirect(route('matches.announcements.create', $this->match))
        ->assertSessionHasErrors('body');

    Notification::assertNothingSent();
    expect(Announcement::count())->toBe(0)
        ->and(AuditLog::where('action_type', 'match.announcement.sent')->count())->toBe(0);
});

it('rejects a subject longer than 200 characters without writing anything', function () {
    Notification::fake();

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    makeMdRegistration($this->match, $shooter, 'confirmed');

    $this->actingAs($this->md)
        ->from(route('matches.announcements.create', $this->match))
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => str_repeat('A', 201),
            'body' => 'ok',
        ])
        ->assertSessionHasErrors('subject');

    Notification::assertNothingSent();
    expect(Announcement::count())->toBe(0);
});

it('rejects an empty subject or body', function () {
    Notification::fake();

    $this->actingAs($this->md)
        ->from(route('matches.announcements.create', $this->match))
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => '',
            'body' => '',
        ])
        ->assertSessionHasErrors(['subject', 'body']);

    Notification::assertNothingSent();
});

it('refuses to send when nobody is on the entry list', function () {
    Notification::fake();

    $this->actingAs($this->md)
        ->from(route('matches.announcements.create', $this->match))
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Anyone home?',
            'body' => 'body',
        ])
        ->assertRedirect(route('matches.announcements.create', $this->match))
        ->assertSessionHas('error');

    Notification::assertNothingSent();
    expect(Announcement::count())->toBe(0)
        ->and(AuditLog::where('action_type', 'match.announcement.sent')->count())->toBe(0);
});

// ── Persistence + audit ───────────────────────────────────────────

it('records the announcement row and an audit log entry on success', function () {
    Notification::fake();

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    makeMdRegistration($this->match, $shooter, 'confirmed');

    $this->actingAs($this->md)
        ->post(route('matches.announcements.store', $this->match), [
            'subject' => 'Weather update',
            'body' => 'Rain expected — bring waterproofs.',
        ])
        ->assertRedirect();

    $announcement = Announcement::firstOrFail();
    expect($announcement->match_id)->toBe($this->match->id)
        ->and($announcement->created_by)->toBe($this->md->id)
        ->and($announcement->title)->toBe('Weather update')
        ->and($announcement->body)->toBe('Rain expected — bring waterproofs.')
        ->and($announcement->recipient_count)->toBe(1)
        ->and($announcement->category->value)->toBe('match_bulletin')
        ->and($announcement->retention->value)->toBe('match_scoped')
        ->and($announcement->sent_at)->not->toBeNull();

    $audit = AuditLog::where('action_type', 'match.announcement.sent')->firstOrFail();
    expect($audit->entity_type)->toBe('Announcement')
        ->and($audit->entity_id)->toBe($announcement->id)
        ->and($audit->user_id)->toBe($this->md->id)
        ->and($audit->new_value)->toMatchArray([
            'match_id' => $this->match->id,
            'subject' => 'Weather update',
            'recipient_count' => 1,
            'status_scope' => ['confirmed', 'waitlisted'],
            'category' => 'match_bulletin',
            'retention' => 'match_scoped',
        ]);
});

// ── Compose page render ───────────────────────────────────────────

it('renders the compose page for the match creator with the correct recipient count', function () {
    $confirmed = User::factory()->create(['email_verified_at' => now()]);
    $cancelled = User::factory()->create(['email_verified_at' => now()]);
    makeMdRegistration($this->match, $confirmed, 'confirmed');
    makeMdRegistration($this->match, $cancelled, 'cancelled');

    $this->actingAs($this->md)
        ->get(route('matches.announcements.create', $this->match))
        ->assertOk()
        ->assertSee('Message Entrants')
        ->assertSee('1 entrant');
});
