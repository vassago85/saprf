<?php

/**
 * MD "scores are complete" workflow + shooter notification pipeline.
 *
 * Covers:
 *   - MD can mark/unmark scores complete on their own match; other MDs cannot.
 *   - Button only appears / only works when at least one score exists.
 *   - NotifyScoresFinalizedJob delivers to:
 *       own-match shooters with notify_own_match_scores on (default),
 *       series subscribers with notify_all_prs_scores / notify_all_pr22_scores,
 *       and de-dupes a shooter who matches both reasons.
 *   - Job is idempotent — re-marking after unmark does NOT re-send.
 *   - Score prefs save endpoint + one-time prompt gating.
 */

use App\Jobs\NotifyScoresFinalizedJob;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\NotificationPreference;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Notifications\ScoresFinalizedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedRoles();
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
});

function makeMatchFor(User $md, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Score Finalisation Test',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->subDay(),
        'status' => 'closed',
        'active_member_fee' => 100,
        'non_member_fee' => 200,
        'created_by' => $md->id,
        'match_director' => $md->name,
        'published' => true,
    ], $overrides));
}

function recordScore(MatchEvent $match, User $shooter, float $raw = 100.0): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'raw_score' => $raw,
        'status' => 'valid',
        'match_date' => $match->match_date,
    ]);
}

// ─── MD controller ────────────────────────────────────────────────────

it('lets the owning MD mark their match scores complete and dispatches the notify job', function () {
    Queue::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province);
    $shooter = User::factory()->create();
    recordScore($match, $shooter);

    $this->actingAs($md)
        ->post(route('matches.scores.complete', $match))
        ->assertRedirect(route('matches.scores', $match))
        ->assertSessionHas('success');

    $match->refresh();
    expect($match->scoresAreFinalized())->toBeTrue()
        ->and($match->scores_finalized_by)->toBe($md->id);

    Queue::assertPushed(NotifyScoresFinalizedJob::class, fn ($job) => $job->matchId === $match->id);
});

it('refuses to mark complete when no scores exist yet', function () {
    Queue::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province);

    $this->actingAs($md)
        ->post(route('matches.scores.complete', $match))
        ->assertRedirect(route('matches.scores', $match))
        ->assertSessionHas('error');

    expect($match->fresh()->scoresAreFinalized())->toBeFalse();
    Queue::assertNotPushed(NotifyScoresFinalizedJob::class);
});

it('forbids a foreign MD from marking another match complete', function () {
    Queue::fake();

    $owningMd = User::factory()->create();
    $owningMd->assignRole('match_director');
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');

    $match = makeMatchFor($owningMd, $this->province);
    recordScore($match, User::factory()->create());

    $this->actingAs($otherMd)
        ->post(route('matches.scores.complete', $match))
        ->assertForbidden();

    expect($match->fresh()->scoresAreFinalized())->toBeFalse();
    Queue::assertNotPushed(NotifyScoresFinalizedJob::class);
});

it('un-marking clears the finalized timestamp but keeps the notified marker', function () {
    Queue::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province, [
        'scores_finalized_at' => now()->subHour(),
        'scores_finalized_by' => $md->id,
        'scores_finalized_notified_at' => now()->subHour(),
    ]);
    recordScore($match, User::factory()->create());

    $this->actingAs($md)
        ->post(route('matches.scores.uncomplete', $match))
        ->assertRedirect(route('matches.scores', $match));

    $match->refresh();
    expect($match->scoresAreFinalized())->toBeFalse()
        ->and($match->scores_finalized_notified_at)->not->toBeNull();
});

it('does not re-send notifications when re-marking a previously notified match', function () {
    Queue::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province, [
        'scores_finalized_notified_at' => now()->subHour(),
    ]);
    recordScore($match, User::factory()->create());

    $this->actingAs($md)
        ->post(route('matches.scores.complete', $match))
        ->assertRedirect(route('matches.scores', $match));

    Queue::assertNotPushed(NotifyScoresFinalizedJob::class);
});

// ─── Audience + notify job ────────────────────────────────────────────

it('notifies own-match shooters whose score row is linked to their account', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province);

    $shooter = User::factory()->create();
    recordScore($match, $shooter);
    // Default prefs row (own-match on, series off).
    NotificationPreference::create([
        'user_id' => $shooter->id,
        'push_enabled' => true,
        'notify_own_match_scores' => true,
        'score_prefs_configured_at' => now(),
    ]);

    $match->forceFill([
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $md->id,
    ])->save();

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertSentTo($shooter, ScoresFinalizedNotification::class, function ($n) {
        return $n->reason() === 'own';
    });

    expect($match->fresh()->scores_finalized_notified_at)->not->toBeNull();
});

it('also notifies registration-only shooters even without a score row yet', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province);

    $entrant = User::factory()->create();
    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $entrant->id,
        'shooter_name' => $entrant->name,
        'email' => $entrant->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 100,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDay(),
    ]);
    recordScore($match, User::factory()->create()); // somebody else actually shot

    $match->forceFill([
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $md->id,
    ])->save();

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertSentTo($entrant, ScoresFinalizedNotification::class);
});

it('skips own-match shooters who explicitly muted the toggle', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province);

    $silent = User::factory()->create();
    recordScore($match, $silent);
    NotificationPreference::create([
        'user_id' => $silent->id,
        'push_enabled' => true,
        'notify_own_match_scores' => false,
        'score_prefs_configured_at' => now(),
    ]);

    $match->forceFill([
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $md->id,
    ])->save();

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertNotSentTo($silent, ScoresFinalizedNotification::class);
});

it('notifies PRS series subscribers for a PRS match they did not shoot', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province, ['match_type' => 'PRS']);
    recordScore($match, User::factory()->create()); // somebody else

    $follower = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $follower->id,
        'push_enabled' => true,
        'notify_own_match_scores' => true,
        'notify_all_prs_scores' => true,
        'notify_all_pr22_scores' => false,
        'score_prefs_configured_at' => now(),
    ]);

    $match->forceFill([
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $md->id,
    ])->save();

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertSentTo($follower, ScoresFinalizedNotification::class, function ($n) {
        return $n->reason() === 'series';
    });
});

it('does not notify PR22 subscribers for a PRS match', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province, ['match_type' => 'PRS']);
    recordScore($match, User::factory()->create());

    $wrongSeries = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $wrongSeries->id,
        'push_enabled' => true,
        'notify_own_match_scores' => false,
        'notify_all_prs_scores' => false,
        'notify_all_pr22_scores' => true,
        'score_prefs_configured_at' => now(),
    ]);

    $match->forceFill([
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $md->id,
    ])->save();

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertNotSentTo($wrongSeries, ScoresFinalizedNotification::class);
});

it('sends exactly one notification to shooters who match both own and series rules', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province, ['match_type' => 'PRS']);

    $both = User::factory()->create();
    recordScore($match, $both);
    NotificationPreference::create([
        'user_id' => $both->id,
        'push_enabled' => true,
        'notify_own_match_scores' => true,
        'notify_all_prs_scores' => true,
        'score_prefs_configured_at' => now(),
    ]);

    $match->forceFill([
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $md->id,
    ])->save();

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertSentToTimes($both, ScoresFinalizedNotification::class, 1);
});

it('is idempotent — rerunning the job after notified_at is set sends nothing', function () {
    Notification::fake();

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = makeMatchFor($md, $this->province, [
        'scores_finalized_at' => now()->subMinute(),
        'scores_finalized_by' => $md->id,
        'scores_finalized_notified_at' => now()->subMinute(),
    ]);

    $shooter = User::factory()->create();
    recordScore($match, $shooter);

    (new NotifyScoresFinalizedJob($match->id))->handle(
        app(\App\Services\ScoreNotificationAudience::class),
        app(\App\Notifications\Channels\WebPushChannel::class),
    );

    Notification::assertNothingSent();
});

// ─── Preferences UI + prompt ──────────────────────────────────────────

it('persists all three score toggles via the profile preferences form', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->put(route('profile.notification-preferences.update'), [
            'push_enabled' => '1',
            'notify_own_match_scores' => '1',
            'notify_all_prs_scores' => '1',
            'notify_all_pr22_scores' => '0',
        ])
        ->assertRedirect(route('profile'));

    $pref = $member->fresh()->notificationPreference;
    expect($pref)->not->toBeNull()
        ->and($pref->notify_own_match_scores)->toBeTrue()
        ->and($pref->notify_all_prs_scores)->toBeTrue()
        ->and($pref->notify_all_pr22_scores)->toBeFalse()
        ->and($pref->score_prefs_configured_at)->not->toBeNull();
});

it('the one-time prompt endpoint stamps score_prefs_configured_at', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->post(route('profile.score-notification-prompt.save'), [
            'notify_own_match_scores' => '1',
        ])
        ->assertRedirect();

    $pref = $member->fresh()->notificationPreference;
    expect($pref)->not->toBeNull()
        ->and($pref->score_prefs_configured_at)->not->toBeNull()
        ->and($pref->notify_own_match_scores)->toBeTrue()
        ->and($pref->notify_all_prs_scores)->toBeFalse();
});
