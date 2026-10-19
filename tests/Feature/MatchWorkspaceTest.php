<?php

/**
 * Match workspace: six tabs, one match, one layout.
 *
 * Covers:
 *   - auth: own-MD can hit every tab, foreign MD gets 403, members 302
 *   - stage resolver: Draft → EntriesOpen → EntriesClosed → Scores →
 *                     Results → Payout, driven by existing columns
 *   - entry counts: a single service decides what "counted" means and
 *                   every surface agrees
 *   - legacy URL redirects: /matches/{id}/edit → /settings,
 *                           /financials/match/{id} → /finances
 */

use App\Enums\MatchStage;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\User;
use App\Services\MatchFinanceService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    foreach (['developer', 'exco', 'chair', 'provincial_admin', 'iprf_selector'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
});

/**
 * Match owned by the given user, parameters mimicking a published provincial
 * match that is open for entries.
 */
function workspaceMatchOwnedBy(User $creator, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Workspace Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
        'match_director' => $creator->name,
        'published' => true,
    ], $overrides));
}

// ─── Tab routing ──────────────────────────────────────────────────────

it('shows every workspace tab to the owning match director', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    foreach (['overview', 'entries', 'scores', 'finances', 'messages', 'settings'] as $tab) {
        $this->actingAs($md)
            ->get(route("matches.{$tab}", $match))
            ->assertOk();
    }
});

it('refuses every workspace tab to another match director', function () {
    $mine = User::factory()->create();
    $mine->assignRole('match_director');

    $theirs = User::factory()->create();
    $theirs->assignRole('match_director');

    $match = workspaceMatchOwnedBy($theirs, $this->province);

    foreach (['overview', 'entries', 'scores', 'finances', 'messages', 'settings'] as $tab) {
        $this->actingAs($mine)
            ->get(route("matches.{$tab}", $match))
            ->assertForbidden();
    }
});

it('redirects an ordinary member away from the match workspace', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    // The `role:` middleware on the whole workspace group kicks members
    // out before the controller is reached. The exact landing page
    // (abort(403) vs redirect to events) depends on the role middleware
    // config — we only care that the member is NOT served the workspace.
    $response = $this->actingAs($member)->get(route('matches.overview', $match));
    expect($response->getStatusCode())->not->toBe(200);
});

// ─── Legacy URL redirects ─────────────────────────────────────────────

it('redirects /matches/{id}/edit into the settings tab', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    $this->actingAs($md)
        ->get("/matches/{$match->id}/edit")
        ->assertRedirect(route('matches.settings', $match));
});

it('redirects /financials/match/{id} into the finances tab', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    $this->actingAs($md)
        ->get("/financials/match/{$match->id}")
        ->assertRedirect(route('matches.finances', $match));
});

// ─── Stage resolver ───────────────────────────────────────────────────

it('resolves Draft stage for a draft match', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province, ['status' => 'draft']);

    expect($match->currentStage())->toBe(MatchStage::Draft);
});

it('resolves EntriesOpen for a published open match with no scores', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    expect($match->currentStage())->toBe(MatchStage::EntriesOpen);
});

it('resolves EntriesClosed when the match status is closed', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province, ['status' => 'closed']);

    expect($match->currentStage())->toBe(MatchStage::EntriesClosed);
});

it('resolves Scores when at least one score import exists but no scores published', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province, ['status' => 'closed']);

    ScoreImport::create([
        'match_id' => $match->id,
        'uploaded_by' => $md->id,
        'source_type' => 'impact',
        'original_filename' => 'results.csv',
        'import_status' => 'queued',
    ]);

    expect($match->fresh()->currentStage())->toBe(MatchStage::Scores);
});

it('resolves Results once scores exist', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province, ['status' => 'closed']);

    $shooter = User::factory()->create();
    Score::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'raw_score' => 100,
        'adjusted_score' => 100,
        'match_date' => $match->match_date,
    ]);

    expect($match->fresh()->currentStage())->toBe(MatchStage::Results);
});

it('resolves Payout once the match is completed', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province, ['status' => 'completed']);

    expect($match->currentStage())->toBe(MatchStage::Payout);
});

it('resolves Payout as soon as an MD payout has been raised', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    Payout::create([
        'reference' => Payout::generateReference(),
        'payee_type' => 'match_director',
        'payee_user_id' => $md->id,
        'match_id' => $match->id,
        'gross_amount' => 0,
        'fees_deducted' => 0,
        'net_amount' => 0,
        'status' => 'pending',
        'created_by' => $md->id,
    ]);

    expect($match->fresh()->currentStage())->toBe(MatchStage::Payout);
});

// ─── Entry count agreement ────────────────────────────────────────────

it('agrees on how many entries a match has across workspace surfaces', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match = workspaceMatchOwnedBy($md, $this->province);

    // Three paid, one pending, one waitlisted, one cancelled.
    // "counted" should be 5 (everything that isn't cancelled).
    // "paid" should be 3.
    // "recipients" should be 4 (confirmed + waitlisted).
    foreach (range(1, 3) as $_) {
        MatchRegistration::create([
            'match_id' => $match->id,
            'user_id' => User::factory()->create()->id,
            'shooter_name' => fake()->name(),
            'fee_amount' => 250,
            'saprf_fee' => 50,
            'platform_fee' => 50,
            'gateway_fee' => 15,
            'surcharge_amount' => 0,
            'md_net_amount' => 135,
            'membership_fee_category' => 'active_member',
            'payment_status' => 'paid',
            'registration_status' => 'confirmed',
            'registered_at' => now(),
        ]);
    }

    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Pending Pat',
        'fee_amount' => 250,
        'membership_fee_category' => 'active_member',
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Waiting Wendy',
        'fee_amount' => 250,
        'membership_fee_category' => 'active_member',
        'payment_status' => 'pending',
        'registration_status' => 'waitlisted',
        'registered_at' => now(),
    ]);

    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Cancelled Cal',
        'fee_amount' => 250,
        'membership_fee_category' => 'active_member',
        'payment_status' => 'refunded',
        'registration_status' => 'cancelled',
        'registered_at' => now()->subDays(7),
        'cancelled_at' => now(),
    ]);

    $counts = app(MatchFinanceService::class)->entryCounts($match);

    expect($counts['counted'])->toBe(5)
        ->and($counts['paid'])->toBe(3)
        ->and($counts['recipients'])->toBe(4)
        ->and($counts['waitlisted'])->toBe(1)
        ->and($counts['pending_payment'])->toBe(2);
});

// ─── MD sidebar visibility ────────────────────────────────────────────

it('hides Registrations, Score imports and Scores from a pure match director', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');

    $labels = sidebarLabels($md, 'admin');

    expect($labels)->toContain('Matches')
        ->and($labels)->toContain('My payouts')
        ->and($labels)->not->toContain('Registrations')
        ->and($labels)->not->toContain('Score imports')
        ->and($labels)->not->toContain('Scores')
        ->and($labels)->not->toContain('Venues')
        ->and($labels)->not->toContain('Standings')
        ->and($labels)->not->toContain('Events');
});

it('keeps admin-level roles seeing the cross-match lists', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $labels = sidebarLabels($admin, 'admin');

    expect($labels)->toContain('Registrations')
        ->and($labels)->toContain('Score imports')
        ->and($labels)->toContain('Scores')
        ->and($labels)->toContain('Venues');
});
