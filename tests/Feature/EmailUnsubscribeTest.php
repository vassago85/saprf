<?php

/**
 * RFC 8058 signed one-click unsubscribe endpoint.
 *
 * Covers:
 *   - Unsigned or tampered URL is rejected (403 signed middleware).
 *   - POST works without CSRF (Gmail hits POST from its own servers).
 *   - Non-mandatory category mutes just that category.
 *   - Mandatory category mutes every non-mandatory category (the
 *     graceful degradation path: we can't stop mandatory notices
 *     without withdrawing membership, so we at least respect the
 *     stated intent for everything else).
 *   - Endpoint is idempotent — Gmail retries a failed one-click.
 */

use App\Enums\AnnouncementCategory;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    seedRoles();
});

function makeSubscriber(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');
    return $user;
}

it('rejects an unsigned unsubscribe URL', function () {
    $user = makeSubscriber();

    $this->get('/email/unsubscribe/' . $user->id . '?category=' . AnnouncementCategory::Announcement->value)
        ->assertStatus(403);
});

it('rejects a tampered signature', function () {
    $user = makeSubscriber();

    $url = URL::signedRoute('email.unsubscribe', [
        'user' => $user->id,
        'category' => AnnouncementCategory::Announcement->value,
    ]);
    // Flip a byte in the signature.
    $tampered = preg_replace('/signature=([a-f0-9]{4})/', 'signature=deadbeef', $url);

    $this->get($tampered)->assertStatus(403);
});

it('accepts a valid signed GET and mutes the single non-mandatory category', function () {
    $user = makeSubscriber();

    $url = URL::signedRoute('email.unsubscribe', [
        'user' => $user->id,
        'category' => AnnouncementCategory::Announcement->value,
    ]);

    $this->get($url)->assertOk()->assertSee('unsubscribed', false);

    $pref = NotificationPreference::firstWhere('user_id', $user->id);
    expect($pref)->not->toBeNull();
    expect($pref->muted_email_categories)->toContain(AnnouncementCategory::Announcement->value);
    expect($pref->muted_email_categories)->not->toContain(AnnouncementCategory::PolicyChange->value);
});

it('accepts a valid signed POST (Gmail one-click) without CSRF', function () {
    $user = makeSubscriber();

    $url = URL::signedRoute('email.unsubscribe', [
        'user' => $user->id,
        'category' => AnnouncementCategory::Announcement->value,
    ]);

    // No csrf token, no session — mimics Gmail's server hitting the URL.
    $this->post($url)->assertOk();

    $pref = NotificationPreference::firstWhere('user_id', $user->id);
    expect($pref->muted_email_categories)->toContain(AnnouncementCategory::Announcement->value);
});

it('mutes every non-mandatory category when the source category is mandatory', function () {
    $user = makeSubscriber();

    $url = URL::signedRoute('email.unsubscribe', [
        'user' => $user->id,
        'category' => AnnouncementCategory::PolicyChange->value,
    ]);

    $this->get($url)->assertOk();

    $pref = NotificationPreference::firstWhere('user_id', $user->id);
    $muted = $pref->muted_email_categories;

    // Every non-mandatory category should now be muted.
    foreach (AnnouncementCategory::cases() as $case) {
        if ($case->isMandatory()) {
            expect($muted)->not->toContain($case->value);
        } else {
            expect($muted)->toContain($case->value);
        }
    }
});

it('is idempotent — retried unsubscribes do not duplicate mutes', function () {
    $user = makeSubscriber();

    $url = URL::signedRoute('email.unsubscribe', [
        'user' => $user->id,
        'category' => AnnouncementCategory::Announcement->value,
    ]);

    $this->post($url)->assertOk();
    $this->post($url)->assertOk();
    $this->post($url)->assertOk();

    $pref = NotificationPreference::firstWhere('user_id', $user->id);
    $counts = array_count_values($pref->muted_email_categories);

    expect($counts[AnnouncementCategory::Announcement->value])->toBe(1);
});
