<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Jobs\SendClosedMatchSurveyReportsJob;
use App\Models\MatchSurveyRespondent;
use App\Models\MatchSurveyResponse;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Notifications\MatchSurveyInvitationNotification;
use App\Notifications\MatchSurveyReportReadyNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );
});

/**
 * Baseline completed provincial that the survey is applicable to.
 */
function surveyMatch(array $overrides = []): MatchEvent
{
    /** @var User $admin */
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return MatchEvent::create(array_merge([
        'name' => 'Test Provincial',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => (string) now()->year,
        'province_id' => Province::query()->value('id'),
        'match_date' => now()->subDays(1)->toDateString(),
        'match_end_date' => now()->subDays(1)->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $admin->id,
        'active_member_fee' => 250,
        'non_member_fee' => 400,
        'lapsed_member_fee' => 300,
    ], $overrides));
}

function surveyEntrant(MatchEvent $match, array $overrides = []): User
{
    /** @var User $user */
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');
    giveActiveMembership($user);

    MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'registered_at' => now()->subDays(10),
    ], $overrides));

    return $user->fresh();
}

function surveyAnswersPayload(array $overrides = []): array
{
    return array_merge([
        // 13 ratings on the 1–10 WhatsApp-approved scorecard.
        'comms_before'   => '8',
        'zero_range'     => '7',
        'matchbook'      => '9',
        'briefings'      => '8',
        'stage_design'   => '10',
        'target_sizes'   => '7',
        'difficulty'     => '8',
        'range_officers' => '9',
        'timekeeping'    => '6',
        'catering'       => '8',
        'toilets'        => '5',
        'prize_giving'   => '9',
        'trophies'       => '8',
        'highlight'      => 'The stage design was superb.',
        'improvement'    => 'Shorten the lunch break.',
        'other_comments' => 'Thanks for the hard work from the MD team.',
    ], $overrides);
}

it('does not offer the survey on a club match', function () {
    $match = surveyMatch([
        'series_level' => 'club_only',
        'series' => 'PR22',
    ]);
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->get(route('matches.survey.show', $match))
        ->assertRedirect(route('events.show', $match))
        ->assertSessionHas('error');

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload())
        ->assertRedirect();

    expect(MatchSurveyResponse::count())->toBe(0);
    expect(MatchSurveyRespondent::count())->toBe(0);
});

it('does not offer the survey while the match is still in draft or open', function () {
    $match = surveyMatch(['status' => 'open']);
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->get(route('matches.survey.show', $match))
        ->assertRedirect(route('events.show', $match));

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload())
        ->assertRedirect();

    expect(MatchSurveyResponse::count())->toBe(0);
});

it('403s a user who neither entered nor shot the match', function () {
    $match = surveyMatch();
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $outsider->assignRole('member');
    giveActiveMembership($outsider);

    $this->actingAs($outsider)
        ->get(route('matches.survey.show', $match))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload())
        ->assertRedirect(); // bounced by the service, lands back on form with error

    expect(MatchSurveyResponse::count())->toBe(0);
});

it('lets a walk-in shooter (score linked by user_id, no registration) answer', function () {
    $match = surveyMatch();

    /** @var User $walkIn */
    $walkIn = User::factory()->create(['email_verified_at' => now()]);
    $walkIn->assignRole('member');
    giveActiveMembership($walkIn);

    Score::create([
        'match_id' => $match->id,
        'user_id' => $walkIn->id,
        'shooter_name' => $walkIn->name,
        'raw_score' => 50,
        'status' => 'valid',
        'match_date' => $match->match_date,
    ]);

    $this->actingAs($walkIn)
        ->get(route('matches.survey.show', $match))
        ->assertOk();

    $this->actingAs($walkIn)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload())
        ->assertRedirect(route('events.show', $match));

    expect(MatchSurveyResponse::where('match_id', $match->id)->count())->toBe(1);
});

it('writes one anonymous answer row with no user_id and a matching respondent row', function () {
    $match = surveyMatch();
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload())
        ->assertRedirect(route('events.show', $match));

    $response = MatchSurveyResponse::first();
    expect($response)->not->toBeNull()
        ->and($response->match_id)->toBe($match->id)
        ->and($response->answers)->toHaveKey('stage_design', 10)
        ->and($response->answers)->toHaveKey('zero_range', 7)
        ->and($response->answers)->toHaveKey('highlight', 'The stage design was superb.')
        ->and($response->answers)->toHaveKey('other_comments', 'Thanks for the hard work from the MD team.');

    // The response row must not carry a user_id column — anonymity depends
    // on this. A quick fillable check guards against someone later adding
    // a user_id column by habit.
    expect($response->getAttributes())->not->toHaveKey('user_id');

    // The respondent row proves participation and nothing else.
    $respondent = MatchSurveyRespondent::where('match_id', $match->id)->first();
    expect($respondent)->not->toBeNull()
        ->and($respondent->user_id)->toBe($user->id)
        ->and($respondent->getAttributes())->not->toHaveKey('created_at');
});

it('rejects a second submit from the same shooter without writing a second answer row', function () {
    $match = surveyMatch();
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    // Second attempt should not add another response row.
    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload([
            'stage_design' => '1',
            'improvement' => 'Different answer.',
        ]));

    expect(MatchSurveyResponse::where('match_id', $match->id)->count())->toBe(1);
    expect(MatchSurveyRespondent::where('match_id', $match->id)->count())->toBe(1);

    // Showing the form again lands on the "already answered" redirect.
    $this->actingAs($user)
        ->get(route('matches.survey.show', $match))
        ->assertRedirect(route('events.show', $match))
        ->assertSessionHas('success');
});

it('rejects submits once the window has closed', function () {
    $match = surveyMatch([
        // 10 days ago — window closes 5 days after match_end_date, so by
        // now it is firmly closed.
        'match_date' => now()->subDays(10)->toDateString(),
        'match_end_date' => now()->subDays(10)->toDateString(),
    ]);
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->get(route('matches.survey.show', $match))
        ->assertRedirect(route('events.show', $match));

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    expect(MatchSurveyResponse::count())->toBe(0);
});

it('lets staff (Exco / Owner / Admin / Developer) view aggregated results', function () {
    $match = surveyMatch();
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    foreach (['exco', 'owner', 'admin', 'developer'] as $role) {
        $staff = User::factory()->create();
        $staff->assignRole([$role, 'member']);

        $this->actingAs($staff)
            ->get(route('matches.survey.results', $match))
            ->assertOk()
            ->assertSee('Shooter feedback scorecard')
            ->assertSee('The stage design was superb.');
    }
});

it('lets the match director read the results page and fire the invite for their own match', function () {
    Notification::fake();

    $match = surveyMatch();

    /** @var User $md */
    $md = User::factory()->create(['email_verified_at' => now()]);
    $md->assignRole('match_director');
    $match->forceFill(['created_by' => $md->id])->save();

    $entrant = surveyEntrant($match);
    $this->actingAs($entrant)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    // Results page: MD can read it (the summary + PDF reach them anyway
    // when the window closes, so blocking the in-app view would just
    // frustrate the MD).
    $this->actingAs($md)
        ->get(route('matches.survey.results', $match))
        ->assertOk()
        ->assertSee('Shooter feedback scorecard');

    // Invite: MD can trigger it. The notification fake confirms the
    // entrant actually got the invite — we don't just trust the redirect.
    $this->actingAs($md)
        ->post(route('matches.survey.invite', $match))
        ->assertRedirect(route('matches.survey.results', $match))
        ->assertSessionHas('success');

    Notification::assertSentTo($entrant, MatchSurveyInvitationNotification::class);
    expect($match->fresh()->survey_invites_sent_at)->not->toBeNull();
});

it('blocks a match director from reading another MD\'s survey results', function () {
    $match = surveyMatch();

    // Owner of `match` is someone else.
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');
    $match->forceFill(['created_by' => $otherMd->id])->save();

    $outsiderMd = User::factory()->create();
    $outsiderMd->assignRole('match_director');

    $this->actingAs($outsiderMd)
        ->get(route('matches.survey.results', $match))
        ->assertForbidden();

    $this->actingAs($outsiderMd)
        ->post(route('matches.survey.invite', $match))
        ->assertForbidden();
});

it('blocks a plain member from the results page and from the invite endpoint', function () {
    $match = surveyMatch();

    $plain = User::factory()->create();
    $plain->assignRole('member');

    $this->actingAs($plain)
        ->get(route('matches.survey.results', $match))
        ->assertForbidden();

    $this->actingAs($plain)
        ->post(route('matches.survey.invite', $match))
        ->assertForbidden();
});

it('lets any logged-in member view the match-agnostic specimen questionnaire', function () {
    // Specimen is intentionally open to any authenticated SAPRF member
    // so a match director (or anyone else) can see what the form looks
    // like without needing to be set as created_by on a specific match.
    $plain = User::factory()->create(['email_verified_at' => now()]);
    $plain->assignRole('member');

    $this->actingAs($plain)
        ->get(route('survey.specimen'))
        ->assertOk()
        ->assertSee('Sample questionnaire')
        ->assertSee('Pre-match communication')       // first rating
        ->assertSee('What was your favourite aspect') // Q14
        ->assertDontSee('Submit feedback');           // no real CTA

    // Guest: bounced to login, not 200. Flush the Pest auth state first
    // because the earlier actingAs($plain) is still carried on the TestCase.
    auth()->logout();
    $this->get(route('survey.specimen'))
        ->assertRedirect(route('login'));
});

it('lets the match director and staff preview the shooter form, and blocks everyone else', function () {
    $match = surveyMatch();

    // MD on their own match
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match->forceFill(['created_by' => $md->id])->save();

    $this->actingAs($md)
        ->get(route('matches.survey.preview', $match))
        ->assertOk()
        ->assertSee('Preview mode')
        ->assertSee('Pre-match communication')         // first rating label
        ->assertSee('What was your favourite aspect')  // text question 14
        ->assertDontSee('Submit feedback');            // real CTA replaced

    // Exco
    $exco = User::factory()->create();
    $exco->assignRole('exco');
    $this->actingAs($exco)
        ->get(route('matches.survey.preview', $match))
        ->assertOk()
        ->assertSee('Preview mode');

    // A match director who does NOT own this match
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');
    $this->actingAs($otherMd)
        ->get(route('matches.survey.preview', $match))
        ->assertForbidden();

    // Plain member
    $plain = User::factory()->create();
    $plain->assignRole('member');
    $this->actingAs($plain)
        ->get(route('matches.survey.preview', $match))
        ->assertForbidden();
});

it('sends invitation emails once and marks the match so a second invite is blocked', function () {
    Notification::fake();

    $match = surveyMatch();
    $a = surveyEntrant($match);
    $b = surveyEntrant($match);

    $exco = User::factory()->create();
    $exco->assignRole(['exco', 'member']);

    $this->actingAs($exco)
        ->post(route('matches.survey.invite', $match))
        ->assertRedirect(route('matches.survey.results', $match))
        ->assertSessionHas('success');

    Notification::assertSentTo($a, MatchSurveyInvitationNotification::class);
    Notification::assertSentTo($b, MatchSurveyInvitationNotification::class);
    expect($match->fresh()->survey_invites_sent_at)->not->toBeNull();

    // Second attempt should be rejected.
    $this->actingAs($exco)
        ->post(route('matches.survey.invite', $match))
        ->assertRedirect(route('matches.survey.results', $match))
        ->assertSessionHas('error');
});

it('validates the answer payload — missing required fields are rejected and no row is written', function () {
    $match = surveyMatch();
    $user = surveyEntrant($match);

    // The improvement question is required; drop it and a rating to make
    // sure both the text-field and rating-row validations fire.
    $payload = surveyAnswersPayload();
    unset($payload['improvement'], $payload['stage_design']);

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), $payload)
        ->assertSessionHasErrors(['improvement', 'stage_design']);

    expect(MatchSurveyResponse::count())->toBe(0);
});

it('accepts a submission without the optional other_comments field', function () {
    $match = surveyMatch();
    $user = surveyEntrant($match);

    $payload = surveyAnswersPayload(['other_comments' => '']);

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), $payload)
        ->assertRedirect(route('events.show', $match));

    $response = MatchSurveyResponse::where('match_id', $match->id)->first();
    expect($response)->not->toBeNull()
        ->and($response->answers['other_comments'] ?? null)->toBe('');
});

it('rejects out-of-range ratings (0 and 11)', function () {
    $match = surveyMatch();
    $user = surveyEntrant($match);

    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload([
            'stage_design' => '0',
            'target_sizes' => '11',
        ]))
        ->assertSessionHasErrors(['stage_design', 'target_sizes']);

    expect(MatchSurveyResponse::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────
// SendClosedMatchSurveyReportsJob — the daily sweep that emails the
// aggregated PDF to the MD + exco / owner / admin once the window has
// closed. The PDF is generated inline by DomPDF, so these tests also
// catch any Blade syntax errors in the report template.
// ──────────────────────────────────────────────────────────────────────

it('does not email the closing report while the survey window is still open', function () {
    Notification::fake();

    // Match from yesterday — window closes 5 days after match_end_date,
    // so the sweep must skip it.
    $match = surveyMatch();

    $md = User::factory()->create(['email_verified_at' => now()]);
    $md->assignRole('match_director');
    $match->forceFill(['created_by' => $md->id])->save();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole('exco');

    (new SendClosedMatchSurveyReportsJob)->handle(app(\App\Services\MatchSurveyService::class));

    Notification::assertNothingSent();
    expect($match->fresh()->survey_report_sent_at)->toBeNull();
});

it('emails the closing report once the window has closed and marks the match so a re-run is a no-op', function () {
    Notification::fake();

    // Match 10 days ago — window closed 5 days ago.
    $match = surveyMatch([
        'match_date' => now()->subDays(10)->toDateString(),
        'match_end_date' => now()->subDays(10)->toDateString(),
    ]);

    $md = User::factory()->create(['email_verified_at' => now()]);
    $md->assignRole('match_director');
    $match->forceFill(['created_by' => $md->id])->save();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole('exco');

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    // Seed one reply so the PDF has something to render.
    $shooter = surveyEntrant($match);
    $this->actingAs($shooter)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    (new SendClosedMatchSurveyReportsJob)->handle(app(\App\Services\MatchSurveyService::class));

    Notification::assertSentTo($md, MatchSurveyReportReadyNotification::class);
    Notification::assertSentTo($exco, MatchSurveyReportReadyNotification::class);
    Notification::assertSentTo($admin, MatchSurveyReportReadyNotification::class);
    expect($match->fresh()->survey_report_sent_at)->not->toBeNull();

    // Re-run: second sweep must be a no-op because survey_report_sent_at
    // is now set. Clearing the fake and running again is the cleanest way
    // to check "no new notifications".
    Notification::fake();
    (new SendClosedMatchSurveyReportsJob)->handle(app(\App\Services\MatchSurveyService::class));
    Notification::assertNothingSent();
});

// ──────────────────────────────────────────────────────────────────────
// Public shooter-feedback summary on events.show. Numbers only; never
// comments. Only shown once the window has closed AND at least one
// shooter has replied.
// ──────────────────────────────────────────────────────────────────────

it('does not show the public summary while the window is open', function () {
    $match = surveyMatch(); // match from yesterday → window open
    $entrant = surveyEntrant($match);
    $this->actingAs($entrant)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    $summary = app(\App\Services\MatchSurveyService::class)->publicSummary($match->fresh());
    expect($summary)->toBeNull();

    // And the public events.show page renders without the summary block.
    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertDontSee('Shooter feedback')
        ->assertDontSee('What the shooters said');
});

it('shows the public summary once the window has closed, with no comments leaked', function () {
    // Start with the match in its open window so the shooter can actually
    // submit, then shuffle the dates backwards so the window has closed by
    // the time we ask for the public summary.
    $match = surveyMatch();
    $entrant = surveyEntrant($match);

    $this->actingAs($entrant)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload([
            'highlight' => 'THIS_IS_A_COMMENT_SIGNATURE_X',
            'improvement' => 'ANOTHER_SIGNATURE_Y',
            'other_comments' => 'A_THIRD_SIGNATURE_Z',
        ]))
        ->assertRedirect();

    $match->update([
        'match_date' => now()->subDays(10)->toDateString(),
        'match_end_date' => now()->subDays(10)->toDateString(),
    ]);

    $summary = app(\App\Services\MatchSurveyService::class)->publicSummary($match->fresh());
    expect($summary)->not->toBeNull()
        ->and($summary['responded'])->toBe(1)
        ->and($summary['overall_average'])->not->toBeNull()
        ->and($summary['categories'])->not->toBeEmpty();

    // Public summary structure must not expose comments by any key name.
    $flat = json_encode($summary);
    expect($flat)->not->toContain('SIGNATURE')
        ->and($flat)->not->toContain('comment')
        ->and($flat)->not->toContain('highlight')
        ->and($flat)->not->toContain('improvement');

    // Rendered page shows the headline, hides the comments.
    auth()->logout();
    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertSee('What the shooters said')
        ->assertSee('Shooter feedback')
        ->assertDontSee('THIS_IS_A_COMMENT_SIGNATURE_X')
        ->assertDontSee('ANOTHER_SIGNATURE_Y')
        ->assertDontSee('A_THIRD_SIGNATURE_Z');
});

it('does not show the public summary when the window closed with zero replies', function () {
    $match = surveyMatch([
        'match_date' => now()->subDays(10)->toDateString(),
        'match_end_date' => now()->subDays(10)->toDateString(),
    ]);
    // Create an entrant but no survey response — window closed, zero replies.
    surveyEntrant($match);

    $summary = app(\App\Services\MatchSurveyService::class)->publicSummary($match->fresh());
    expect($summary)->toBeNull();
});

it('deduplicates the recipient list so an MD who is also exco only gets the closing report once', function () {
    Notification::fake();

    $match = surveyMatch([
        'match_date' => now()->subDays(10)->toDateString(),
        'match_end_date' => now()->subDays(10)->toDateString(),
    ]);

    // One person wearing two hats: match director AND exco.
    $hybrid = User::factory()->create(['email_verified_at' => now()]);
    $hybrid->assignRole(['match_director', 'exco']);
    $match->forceFill(['created_by' => $hybrid->id])->save();

    (new SendClosedMatchSurveyReportsJob)->handle(app(\App\Services\MatchSurveyService::class));

    Notification::assertSentToTimes($hybrid, MatchSurveyReportReadyNotification::class, 1);
});
