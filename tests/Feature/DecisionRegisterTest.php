<?php

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceType;
use App\Enums\DecisionStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Jobs\ResolveAudienceJob;
use App\Jobs\SendAnnouncementChunkJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\AuditLog;
use App\Models\Decision;
use App\Models\DecisionMember;
use App\Models\DecisionTally;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use App\Services\SettingsService;
use App\Support\DecisionReactionPresenter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    seedRoles();
});

function decisionExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

function decisionMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');
    giveActiveMembership($user);

    return $user->fresh();
}

function publishDecision(?User $creator = null, array $overrides = []): Decision
{
    $creator ??= decisionExco();

    $decision = Decision::create(array_merge([
        'title' => 'Sample committee decision',
        'body' => 'The committee resolved to adopt the proposal.',
        'reference' => 'ExCo, 11 Nov 2026',
        'status' => DecisionStatus::Published,
        'published_at' => now(),
        'created_by' => $creator->id,
    ], $overrides));

    DecisionTally::create([
        'decision_id' => $decision->id,
        'likes' => 0,
        'dislikes' => 0,
        'neutrals' => 0,
    ]);

    return $decision->fresh(['tally']);
}

function seedTally(Decision $decision, int $likes, int $dislikes, int $neutrals = 0): void
{
    $decision->tally->update([
        'likes' => $likes,
        'dislikes' => $dislikes,
        'neutrals' => $neutrals,
    ]);
}

it('sends guests to login and only shows published decisions to logged-in members', function () {
    $published = publishDecision();
    $draft = Decision::create([
        'title' => 'Secret draft',
        'body' => 'Not for the public.',
        'status' => DecisionStatus::Draft,
        'published_at' => null,
        'created_by' => $published->created_by,
    ]);
    DecisionTally::create(['decision_id' => $draft->id, 'likes' => 0, 'dislikes' => 0, 'neutrals' => 0]);

    $this->get(route('decisions.index'))
        ->assertRedirect(route('login'));

    $member = decisionMember();

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Sample committee decision')
        ->assertDontSee('Secret draft')
        ->assertSee('name="reaction"', false);
});

it('lets an active member react once and never stores the direction', function () {
    $decision = publishDecision();
    $member = decisionMember();

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('success', 'You have reacted.');

    expect($decision->fresh()->tally->likes)->toBe(1)
        ->and($decision->fresh()->tally->dislikes)->toBe(0)
        ->and($decision->fresh()->tally->neutrals)->toBe(0)
        ->and(DecisionMember::query()->where('decision_id', $decision->id)->where('member_id', $member->id)->exists())->toBeTrue()
        ->and(Schema::getColumnListing('decision_members'))->not->toContain('reaction')
        ->and(Schema::getColumnListing('decision_members'))->not->toContain('created_at');

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'dislike'])
        ->assertRedirect(route('decisions.index'));

    expect($decision->fresh()->tally->likes)->toBe(1)
        ->and($decision->fresh()->tally->dislikes)->toBe(0)
        ->and($decision->fresh()->tally->neutrals)->toBe(0);

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('You have reacted')
        ->assertDontSee('name="reaction"', false);
});

it('refuses free and lapsed members without writing rows', function () {
    $decision = publishDecision();

    $free = User::factory()->create(['email_verified_at' => now()]);
    $free->assignRole('member');
    Membership::create([
        'user_id' => $free->id,
        'saprf_number' => 'T-FREE01',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $this->actingAs($free)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Only active paid-up members may vote')
        ->assertDontSee('name="reaction"', false);

    $this->actingAs($free)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('error', 'Only active paid-up members may vote.');

    expect(DecisionMember::query()->count())->toBe(0)
        ->and($decision->fresh()->tally->likes)->toBe(0);

    $lapsed = User::factory()->create(['email_verified_at' => now()]);
    $lapsed->assignRole('member');
    Membership::create([
        'user_id' => $lapsed->id,
        'saprf_number' => 'T-LAPSE1',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->subDays(10)->toDateString(),
    ]);

    $this->actingAs($lapsed)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('error');

    expect(DecisionMember::query()->count())->toBe(0)
        ->and($decision->fresh()->tally->likes)->toBe(0);
});

it('closes reactions after the configured window (default 7 days)', function () {
    $decision = publishDecision(overrides: [
        'published_at' => now()->subDays(8),
    ]);
    $member = decisionMember();

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('error');

    expect($decision->fresh()->tally->likes)->toBe(0);

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Feedback window closed');
});

it('lets exco change the reaction window length', function () {
    $exco = decisionExco();
    $decision = publishDecision($exco, [
        'published_at' => now()->subDays(8),
    ]);
    $member = decisionMember();

    $this->actingAs($exco)
        ->post(route('exco.decisions.visibility'), [
            'decision_reaction_visibility' => 'public',
            'decision_reaction_window_days' => 14,
        ])
        ->assertRedirect(route('exco.decisions.index'));

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('success', 'You have reacted.');

    expect($decision->fresh()->tally->likes)->toBe(1);
});

it('keeps the grey threshold until 10 reactions then shows the split', function () {
    $decision = publishDecision();
    seedTally($decision, 7, 2);
    $member = decisionMember();

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Not enough reactions yet')
        ->assertSee('(9 of 10)', false)
        ->assertDontSee('% for');

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'));

    expect($decision->fresh()->totalReactions())->toBe(10);

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('10 reactions')
        ->assertSee('8 for (80%)')
        ->assertSee('2 against (20%)')
        ->assertSee('0 neutral (0%)')
        ->assertDontSee('Not enough reactions yet');
});

it('records a neutral reaction and shows it in the three-way split', function () {
    $decision = publishDecision();
    seedTally($decision, 4, 3, 2);

    $member = decisionMember();
    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'neutral'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('success', 'You have reacted.');

    expect($decision->fresh()->tally->likes)->toBe(4)
        ->and($decision->fresh()->tally->dislikes)->toBe(3)
        ->and($decision->fresh()->tally->neutrals)->toBe(3)
        ->and($decision->fresh()->totalReactions())->toBe(10);

    // 4/10, 3/10, 3/10 → 40% / 30% / 30%
    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('4 for (40%)')
        ->assertSee('3 against (30%)')
        ->assertSee('3 neutral (30%)')
        ->assertSee('You have reacted');
});

it('shows the Neutral button to members who have not yet reacted', function () {
    $decision = publishDecision();
    $member = decisionMember();

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('value="neutral"', false)
        ->assertSee('🤷');
});

it('hides the split until the viewer reacts when visibility is after_reaction', function () {
    app(SettingsService::class)->set(
        SettingsService::DECISION_REACTION_VISIBILITY,
        DecisionReactionPresenter::VISIBILITY_AFTER_REACTION,
    );

    $decision = publishDecision();
    seedTally($decision, 7, 3);

    $member = decisionMember();

    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('results shown after you react')
        ->assertDontSee('% for');

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'dislike'])
        ->assertRedirect(route('decisions.index'));

    // 7 for + 4 against + 0 neutral after this reaction → 64% / 36% / 0%.
    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('7 for (64%)')
        ->assertSee('4 against (36%)')
        ->assertSee('0 neutral (0%)');
});

it('blocks non-exco from the admin screens and lets exco publish', function () {
    Queue::fake();

    // No paid membership — paid members with an incomplete SASCOC profile
    // are redirected by EnsureProfileComplete before the role gate runs.
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('exco.decisions.index'))
        ->assertForbidden();

    $exco = decisionExco();

    $this->actingAs($exco)
        ->post(route('exco.decisions.store'), [
            'title' => 'New public decision',
            'body' => 'Resolved accordingly.',
            'reference' => 'ExCo test',
            'publish' => '1',
        ])
        ->assertRedirect(route('exco.decisions.index'))
        ->assertSessionHas('success', 'Decision published and emailed to active members.');

    $decision = Decision::query()->where('title', 'New public decision')->first();

    expect($decision)->not->toBeNull()
        ->and($decision->isPublished())->toBeTrue()
        ->and($decision->tally)->not->toBeNull()
        ->and($decision->announcement_id)->not->toBeNull();

    $this->actingAs($exco)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('New public decision');
});

it('emails every paid-up member through the announcement pipeline on publish and never double-sends', function () {
    Queue::fake();
    Notification::fake();

    // ExCo publisher has no membership row so EnsureProfileComplete does
    // not bounce them to /profile mid-request.
    $exco = decisionExco();

    $paidA = decisionMember();
    $paidB = decisionMember();

    $free = User::factory()->create(['email_verified_at' => now()]);
    $free->assignRole('member');
    Membership::create([
        'user_id' => $free->id,
        'saprf_number' => 'T-FREE99',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $lapsed = User::factory()->create(['email_verified_at' => now()]);
    $lapsed->assignRole('member');
    Membership::create([
        'user_id' => $lapsed->id,
        'saprf_number' => 'T-LAPSE9',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->subDays(10)->toDateString(),
    ]);

    $this->actingAs($exco)
        ->post(route('exco.decisions.store'), [
            'title' => 'Broadcast decision',
            'body' => 'Members should hear about this.',
            'reference' => 'ExCo, 12 Nov 2026',
            'publish' => '1',
        ])
        ->assertRedirect(route('exco.decisions.index'));

    // HTTP path must not send mail inline — only queue ResolveAudienceJob.
    Notification::assertNothingSent();
    Queue::assertPushed(ResolveAudienceJob::class, 1);

    $decision = Decision::query()->where('title', 'Broadcast decision')->firstOrFail();
    $announcement = Announcement::query()->findOrFail($decision->announcement_id);

    expect($announcement->category)->toBe(AnnouncementCategory::AgmGovernance)
        ->and($announcement->status)->toBe(AnnouncementStatus::Sending)
        ->and($announcement->deliver_via)->toEqualCanonicalizing([
            DeliveryChannel::Database->value,
            DeliveryChannel::Mail->value,
            DeliveryChannel::WebPush->value,
        ])
        ->and($announcement->audiences()->first()->type)->toBe(AudienceType::ActiveMembers)
        ->and($announcement->body)->toContain('Members should hear about this.')
        ->and($announcement->body)->toContain(route('decisions.index').'#decision-'.$decision->id)
        ->and($announcement->title)->toBe('Decision Register: Broadcast decision');

    // Draft save must not email.
    Queue::fake();
    $this->actingAs($exco)
        ->post(route('exco.decisions.store'), [
            'title' => 'Quiet draft',
            'body' => 'Not ready.',
            'publish' => '0',
        ])
        ->assertRedirect(route('exco.decisions.index'));
    Queue::assertNothingPushed();
    expect(Decision::query()->where('title', 'Quiet draft')->value('announcement_id'))->toBeNull();

    // Run the audience freeze for the published decision.
    Queue::fake();
    (new ResolveAudienceJob($announcement->id))
        ->handle(app(\App\Services\Announcements\AnnouncementPublisher::class));

    $announcement->refresh();
    $recipientIds = AnnouncementRecipient::query()
        ->where('announcement_id', $announcement->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    $expectedPaid = collect([$paidA->id, $paidB->id])->sort()->values()->all();

    expect($announcement->status)->toBe(AnnouncementStatus::Sent)
        ->and($recipientIds)->toBe($expectedPaid)
        ->and($recipientIds)->not->toContain($exco->id)
        ->and($recipientIds)->not->toContain($free->id)
        ->and($recipientIds)->not->toContain($lapsed->id);

    foreach ($expectedPaid as $userId) {
        $mailDelivery = AnnouncementDelivery::query()
            ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
            ->where('announcement_recipients.announcement_id', $announcement->id)
            ->where('announcement_recipients.user_id', $userId)
            ->where('announcement_deliveries.channel', DeliveryChannel::Mail->value)
            ->select('announcement_deliveries.*')
            ->first();

        expect($mailDelivery)->not->toBeNull()
            ->and($mailDelivery->status)->toBe(DeliveryStatus::Queued);
    }

    Notification::fake();
    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        $expectedPaid,
    ))->handle(app(SettingsService::class), app(\App\Notifications\Channels\WebPushChannel::class));

    Notification::assertSentTo($paidA, FederationAnnouncementNotification::class);
    Notification::assertSentTo($paidB, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($exco, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($free, FederationAnnouncementNotification::class);
    Notification::assertNotSentTo($lapsed, FederationAnnouncementNotification::class);

    // Editing a published decision must not fire another broadcast.
    Queue::fake();
    $this->actingAs($exco)
        ->put(route('exco.decisions.update', $decision), [
            'title' => 'Broadcast decision (clarified)',
            'body' => 'Members should hear about this. Clarified.',
            'reference' => 'ExCo, 12 Nov 2026',
            'publish' => '0',
        ])
        ->assertRedirect(route('exco.decisions.index'));

    Queue::assertNothingPushed();
    expect(Announcement::query()->where('title', 'like', 'Decision Register:%')->count())->toBe(1);

    // Publishing the same draft a second time via the publish action is also
    // a no-op on email once announcement_id is set.
    $draft = Decision::query()->where('title', 'Quiet draft')->firstOrFail();
    Queue::fake();
    $this->actingAs($exco)
        ->post(route('exco.decisions.publish', $draft))
        ->assertRedirect(route('exco.decisions.index'));
    Queue::assertPushed(ResolveAudienceJob::class, 1);

    $publishedAt = $draft->fresh()->published_at->copy();
    Queue::fake();
    $this->actingAs($exco)
        ->post(route('exco.decisions.publish', $draft->fresh()))
        ->assertRedirect(route('exco.decisions.index'))
        ->assertSessionHas('success', 'Decision is already published.');

    Queue::assertNothingPushed();
    expect($draft->fresh()->published_at->equalTo($publishedAt))->toBeTrue()
        ->and(Announcement::query()->where('title', 'like', 'Decision Register:%')->count())->toBe(2);
});

it('respects normal AGM/governance mute prefs for email but still archives in-app', function () {
    Queue::fake();

    $exco = decisionExco();
    $member = decisionMember();

    NotificationPreference::create([
        'user_id' => $member->id,
        'muted_email_categories' => [AnnouncementCategory::AgmGovernance->value],
        'push_enabled' => true,
    ]);

    $this->actingAs($exco)
        ->post(route('exco.decisions.store'), [
            'title' => 'Muted category decision',
            'body' => 'Still lands in the archive.',
            'publish' => '1',
        ])
        ->assertRedirect();

    $decision = Decision::query()->where('title', 'Muted category decision')->firstOrFail();
    $announcement = $decision->announcement;

    (new ResolveAudienceJob($announcement->id))
        ->handle(app(\App\Services\Announcements\AnnouncementPublisher::class));

    Notification::fake();
    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(\App\Notifications\Channels\WebPushChannel::class));

    Notification::assertNotSentTo($member, FederationAnnouncementNotification::class);

    $delivery = AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->where('announcement_recipients.user_id', $member->id)
        ->where('announcement_deliveries.channel', DeliveryChannel::Mail->value)
        ->select('announcement_deliveries.*')
        ->first();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->error)->toContain('muted');

    expect(AnnouncementRecipient::query()
        ->where('announcement_id', $announcement->id)
        ->where('user_id', $member->id)
        ->exists())->toBeTrue();
});

it('writes no audit log for a reaction and refuses impersonation', function () {
    $decision = publishDecision();
    $member = decisionMember();
    $developer = User::factory()->create(['email_verified_at' => now()]);
    $developer->assignRole(['developer', 'member']);

    AuditLog::query()->delete();

    $this->actingAs($member)
        ->post(route('decisions.react', $decision), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'));

    expect(AuditLog::query()->count())->toBe(0);

    $other = publishDecision();
    $this->actingAs($member)
        ->withSession([
            'impersonator_id' => $developer->id,
            'impersonator_name' => $developer->name,
        ])
        ->post(route('decisions.react', $other), ['reaction' => 'like'])
        ->assertRedirect(route('decisions.index'))
        ->assertSessionHas('error');

    expect($other->fresh()->tally->likes)->toBe(0)
        ->and(DecisionMember::query()->where('decision_id', $other->id)->exists())->toBeFalse();
});

it('shows Decisions in the nav only when logged in and omits it from the sitemap', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee(route('decisions.index'), false);

    $member = decisionMember();
    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Decision Register')
        ->assertSee(route('decisions.index'), false);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertDontSee(route('decisions.index'), false);
});

it('publishes selected drafts in one digest announcement', function () {
    Queue::fake();
    Notification::fake();

    $exco = decisionExco();
    $drafts = collect([
        ['title' => 'Batch alpha', 'body' => 'Alpha body', 'reference' => 'ExCo A'],
        ['title' => 'Batch beta', 'body' => 'Beta body', 'reference' => 'ExCo B'],
        ['title' => 'Batch gamma', 'body' => 'Gamma body'],
    ])->map(function (array $data) use ($exco) {
        $decision = Decision::create([
            ...$data,
            'status' => DecisionStatus::Draft,
            'published_at' => null,
            'created_by' => $exco->id,
        ]);
        DecisionTally::create([
            'decision_id' => $decision->id,
            'likes' => 0,
            'dislikes' => 0,
            'neutrals' => 0,
        ]);

        return $decision;
    });

    $this->actingAs($exco)
        ->post(route('exco.decisions.publish-batch'), [
            'decision_ids' => $drafts->pluck('id')->all(),
        ])
        ->assertRedirect(route('exco.decisions.index'))
        ->assertSessionHas('success', '3 decisions published in one email to active members.');

    Notification::assertNothingSent();
    Queue::assertPushed(ResolveAudienceJob::class, 1);

    $fresh = Decision::query()->whereIn('id', $drafts->pluck('id'))->get();
    expect($fresh)->toHaveCount(3)
        ->and($fresh->every(fn (Decision $d) => $d->isPublished()))->toBeTrue()
        ->and($fresh->pluck('announcement_id')->unique()->count())->toBe(1);

    $announcement = Announcement::query()->findOrFail($fresh->first()->announcement_id);
    expect($announcement->title)->toBe('Decision Register: 3 new decisions')
        ->and($announcement->body)->toContain('Batch alpha')
        ->and($announcement->body)->toContain('Batch beta')
        ->and($announcement->body)->toContain('Batch gamma')
        ->and($announcement->body)->toContain(route('decisions.index').'#decision-'.$fresh->first()->id)
        ->and($announcement->body)->not->toContain('Alpha body')
        ->and($announcement->audiences()->first()->type)->toBe(AudienceType::ActiveMembers);

    // Re-posting the same IDs must not create another announcement.
    Queue::fake();
    $this->actingAs($exco)
        ->post(route('exco.decisions.publish-batch'), [
            'decision_ids' => $drafts->pluck('id')->all(),
        ])
        ->assertRedirect(route('exco.decisions.index'))
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
    expect(Announcement::query()->where('title', 'like', 'Decision Register:%')->count())->toBe(1);
});

it('paginates the public Decision Register', function () {
    $exco = decisionExco();
    for ($i = 1; $i <= 13; $i++) {
        $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        Decision::create([
            'title' => "Paged decision {$n}",
            'body' => "Body {$n}",
            'status' => DecisionStatus::Published,
            'published_at' => now()->subMinutes(13 - $i),
            'created_by' => $exco->id,
        ])->tally()->create(['likes' => 0, 'dislikes' => 0, 'neutrals' => 0]);
    }

    $member = decisionMember();
    $this->actingAs($member)
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Paged decision 13')
        ->assertDontSee('Paged decision 01');

    $this->actingAs($member)
        ->get(route('decisions.index', ['page' => 2]))
        ->assertOk()
        ->assertSee('Paged decision 01');
});
