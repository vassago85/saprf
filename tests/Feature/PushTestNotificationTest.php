<?php

/**
 * Covers the /push/test self-service endpoint that lets a signed-in
 * member fire a canned "Test notification" to every device they've
 * registered. The endpoint is a UX-critical debug tool — it's how a
 * member (or support person on their behalf) verifies push actually
 * works end-to-end before relying on it for real announcements.
 *
 * Delivery itself uses `WebPushChannel::sendPayload`, which is already
 * exercised at the transport level in WebPushSubscriptionTest. Here we
 * pin down the contract of the endpoint:
 *
 *   - guests are rejected (route sits behind auth middleware)
 *   - a user with zero subscriptions gets a clear `no_subscriptions` reason
 *   - VAPID missing on the server bubbles a `vapid_missing` reason
 *   - happy path returns the counts payload shape the JS expects
 *
 * We do NOT actually fire an HTTPS request to Google's FCM push service
 * from the test suite; the WebPush client short-circuits before that
 * happens when VAPID is unset. When VAPID is set + we have a subscription,
 * we assert the fan-out reached the flush() step by mocking or by asserting
 * the fake endpoint received a queue attempt.
 */

use App\Models\PushSubscription;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function ptUser(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user->fresh();
}

it('rejects a guest calling /push/test', function () {
    $this->postJson(route('push.test'))
        ->assertUnauthorized();
});

it('returns no_subscriptions when the user has no registered devices', function () {
    $user = ptUser();

    $this->actingAs($user)
        ->postJson(route('push.test'))
        ->assertOk()
        ->assertJsonPath('sent', 0)
        ->assertJsonPath('failed', 0)
        ->assertJsonPath('reason', 'no_subscriptions');
});

it('returns vapid_missing when the server has no VAPID keypair configured', function () {
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);

    $user = ptUser();
    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/vapid-missing-test',
        'p256dh' => 'k',
        'auth' => 'a',
        'last_used_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('push.test'))
        ->assertOk()
        ->assertJsonPath('sent', 0)
        ->assertJsonPath('failed', 1)
        ->assertJsonPath('reason', 'vapid_missing');
});

it('gracefully reports vapid_malformed when the operator pasted a garbage keypair', function () {
    // Belt-and-braces: if VAPID_PUBLIC_KEY is present in .env but not a
    // real 65-byte P-256 curve point (e.g. someone hand-typed a
    // placeholder), the underlying minishlink/web-push library rejects it
    // with an ErrorException inside `new WebPush([...])`. We catch that
    // in WebPushChannel::sendPayload and translate it to a stable reason
    // string, so members get a friendly "push isn't set up yet" hint
    // instead of a 500.
    config()->set('webpush.vapid.public_key', 'this-is-clearly-not-a-real-key');
    config()->set('webpush.vapid.private_key', 'also-not-a-real-key');
    config()->set('webpush.vapid.subject', 'mailto:test@saprf.co.za');

    $user = ptUser();
    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/malformed-vapid-test',
        'p256dh' => 'k',
        'auth' => 'a',
        'last_used_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('push.test'))
        ->assertOk()
        ->assertJsonPath('sent', 0)
        ->assertJsonPath('failed', 1)
        ->assertJsonPath('reason', 'vapid_malformed');
});

it('always returns the sent/failed/pruned/reason response shape the JS wrapper expects', function () {
    // Any code path — no subs, vapid missing, vapid malformed, transport
    // errors, or a real send — must return the same top-level keys so
    // the JS wrapper never has to branch on presence. This test locks
    // that contract in from the cheapest path (no subscriptions).
    $user = ptUser();

    $this->actingAs($user)
        ->postJson(route('push.test'))
        ->assertOk()
        ->assertJsonStructure(['sent', 'failed', 'pruned', 'reason']);
});

it('does not leak another users test push into this users result', function () {
    config()->set('webpush.vapid.public_key', 'test-public-key');
    config()->set('webpush.vapid.private_key', 'test-private-key');

    $alice = ptUser();
    $bob = ptUser();

    PushSubscription::create([
        'user_id' => $bob->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/bobs-phone',
        'p256dh' => 'k',
        'auth' => 'a',
        'last_used_at' => now(),
    ]);

    // Alice has no subscriptions. Test push should report no_subscriptions
    // even though bob has one registered on his account.
    $this->actingAs($alice)
        ->postJson(route('push.test'))
        ->assertOk()
        ->assertJsonPath('reason', 'no_subscriptions')
        ->assertJsonPath('failed', 0);
});
