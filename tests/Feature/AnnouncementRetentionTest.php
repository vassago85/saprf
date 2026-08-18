<?php

/**
 * Retention + tab behaviour.
 *
 * Locks in the three retention modes and the way `scopeInbox` /
 * `scopeArchive` filter them, plus the `?tab=inbox|archive` toggle on
 * /communications. Written mostly against the model scopes directly so
 * a bug in the scope logic is caught before it manifests as a
 * mysteriously-empty inbox in the UI.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementRetention;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function makeAnnouncementFor(User $creator, array $overrides = []): Announcement
{
    return Announcement::create(array_merge([
        'title' => 'Retention test',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement->value,
        'retention' => AnnouncementRetention::ExpiresOnDate->value,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent->value,
        'requires_acknowledgement' => false,
        'created_by' => $creator->id,
        'sent_at' => now(),
        'recipient_count' => 0,
    ], $overrides));
}

function makeMatchFor(User $creator, string $status = 'open'): MatchEvent
{
    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    return MatchEvent::create([
        'name' => 'Retention match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => \Carbon\Carbon::today()->addWeek(),
        'status' => $status,
        'published' => true,
        'active_member_fee' => 550.00,
        'created_by' => $creator->id,
    ]);
}

// ── permanent retention ─────────────────────────────────────────────

it('shows a permanent announcement in Inbox for the first 60 days and always in Archive', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);

    $fresh = makeAnnouncementFor($creator, [
        'title' => 'Fresh policy',
        'retention' => AnnouncementRetention::Permanent->value,
        'sent_at' => now()->subDays(10),
    ]);

    $stale = makeAnnouncementFor($creator, [
        'title' => 'Old policy',
        'retention' => AnnouncementRetention::Permanent->value,
        'sent_at' => now()->subDays(90),
    ]);

    $inbox = Announcement::inbox()->pluck('title')->all();
    $archive = Announcement::archive()->pluck('title')->all();

    expect($inbox)->toContain('Fresh policy')->not->toContain('Old policy');
    expect($archive)->toContain('Fresh policy')->toContain('Old policy');
});

// ── expires_on_date retention ───────────────────────────────────────

it('drops an expires_on_date announcement out of Inbox after its expiry but keeps it in Archive', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);

    $current = makeAnnouncementFor($creator, [
        'title' => 'Current',
        'retention' => AnnouncementRetention::ExpiresOnDate->value,
        'expires_at' => now()->addWeek(),
    ]);

    $expired = makeAnnouncementFor($creator, [
        'title' => 'Expired',
        'retention' => AnnouncementRetention::ExpiresOnDate->value,
        'expires_at' => now()->subWeek(),
    ]);

    $openEnded = makeAnnouncementFor($creator, [
        'title' => 'No expiry',
        'retention' => AnnouncementRetention::ExpiresOnDate->value,
        'expires_at' => null,
    ]);

    $inbox = Announcement::inbox()->pluck('title')->all();
    $archive = Announcement::archive()->pluck('title')->all();

    expect($inbox)->toContain('Current')->toContain('No expiry')->not->toContain('Expired');
    expect($archive)->toContain('Current')->toContain('No expiry')->toContain('Expired');
});

// ── match_scoped retention ─────────────────────────────────────────

it('hides a match-scoped bulletin from Inbox AND Archive the instant the match completes', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);

    $liveMatch = makeMatchFor($creator, 'open');
    $doneMatch = makeMatchFor($creator, 'completed');
    $cancelledMatch = makeMatchFor($creator, 'cancelled');

    $live = makeAnnouncementFor($creator, [
        'title' => 'Live bulletin',
        'category' => AnnouncementCategory::MatchBulletin->value,
        'retention' => AnnouncementRetention::MatchScoped->value,
        'match_id' => $liveMatch->id,
    ]);
    $done = makeAnnouncementFor($creator, [
        'title' => 'Done bulletin',
        'category' => AnnouncementCategory::MatchBulletin->value,
        'retention' => AnnouncementRetention::MatchScoped->value,
        'match_id' => $doneMatch->id,
    ]);
    $cancelled = makeAnnouncementFor($creator, [
        'title' => 'Cancelled bulletin',
        'category' => AnnouncementCategory::MatchBulletin->value,
        'retention' => AnnouncementRetention::MatchScoped->value,
        'match_id' => $cancelledMatch->id,
    ]);

    $inbox = Announcement::inbox()->pluck('title')->all();
    $archive = Announcement::archive()->pluck('title')->all();

    expect($inbox)->toContain('Live bulletin')
        ->not->toContain('Done bulletin')
        ->not->toContain('Cancelled bulletin');

    expect($archive)->toContain('Live bulletin')
        ->not->toContain('Done bulletin')
        ->not->toContain('Cancelled bulletin');
});

it('vanishes an in-flight match bulletin from the member view when the match flips to completed', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $match = makeMatchFor($creator, 'open');

    $bulletin = makeAnnouncementFor($creator, [
        'title' => 'Weather update',
        'category' => AnnouncementCategory::MatchBulletin->value,
        'retention' => AnnouncementRetention::MatchScoped->value,
        'match_id' => $match->id,
    ]);

    expect(Announcement::inbox()->pluck('id')->all())->toContain($bulletin->id);

    // MD marks the match completed.
    $match->update(['status' => 'completed']);

    expect(Announcement::inbox()->pluck('id')->all())->not->toContain($bulletin->id)
        ->and(Announcement::archive()->pluck('id')->all())->not->toContain($bulletin->id);
});

// ── retracted always hidden ────────────────────────────────────────

it('excludes retracted announcements from both Inbox and Archive regardless of retention', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);

    $bad = makeAnnouncementFor($creator, [
        'title' => 'Whoops',
        'retention' => AnnouncementRetention::Permanent->value,
        'retracted_at' => now(),
        'retracted_by' => $creator->id,
    ]);

    expect(Announcement::inbox()->pluck('id')->all())->not->toContain($bad->id)
        ->and(Announcement::archive()->pluck('id')->all())->not->toContain($bad->id);
});

// ── /communications tab query ──────────────────────────────────────

it('the /communications tab param routes to the matching scope', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole(['exco', 'member']);

    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $fresh = makeAnnouncementFor($creator, [
        'title' => 'Fresh policy piece',
        'retention' => AnnouncementRetention::Permanent->value,
        'sent_at' => now()->subDays(5),
    ]);
    $stale = makeAnnouncementFor($creator, [
        'title' => 'Stale policy piece',
        'retention' => AnnouncementRetention::Permanent->value,
        'sent_at' => now()->subDays(120),
    ]);

    foreach ([$fresh, $stale] as $a) {
        AnnouncementRecipient::create([
            'announcement_id' => $a->id,
            'user_id' => $me->id,
        ]);
    }

    // Default (no tab) → Inbox: shows only the fresh one.
    $this->actingAs($me)
        ->get(route('communications.index'))
        ->assertOk()
        ->assertSee('Fresh policy piece')
        ->assertDontSee('Stale policy piece');

    // Archive tab → shows both.
    $this->actingAs($me)
        ->get(route('communications.index', ['tab' => 'archive']))
        ->assertOk()
        ->assertSee('Fresh policy piece')
        ->assertSee('Stale policy piece');
});

it('unread badge counts only Inbox items, not the whole Archive', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $me = User::factory()->create(['email_verified_at' => now()]);
    $me->assignRole('member');

    $doneMatch = makeMatchFor($creator, 'completed');

    // One inbox-eligible item.
    $inboxItem = makeAnnouncementFor($creator, [
        'title' => 'Inbox item',
        'retention' => AnnouncementRetention::ExpiresOnDate->value,
        'expires_at' => now()->addWeek(),
    ]);

    // One archive-only item (match-scoped, match completed → invisible
    // in BOTH Inbox and Archive per product rules → shouldn't count).
    $stale = makeAnnouncementFor($creator, [
        'title' => 'Stale bulletin',
        'category' => AnnouncementCategory::MatchBulletin->value,
        'retention' => AnnouncementRetention::MatchScoped->value,
        'match_id' => $doneMatch->id,
    ]);

    foreach ([$inboxItem, $stale] as $a) {
        AnnouncementRecipient::create([
            'announcement_id' => $a->id,
            'user_id' => $me->id,
        ]);
    }

    $this->actingAs($me)
        ->getJson(route('communications.unread-count'))
        ->assertOk()
        ->assertJsonPath('unread', 1);
});
