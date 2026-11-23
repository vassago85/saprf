<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\MatchSurveyRespondent;
use App\Models\MatchSurveyResponse;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Notifications\MatchSurveyInvitationNotification;
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

it('does not let the match director view survey results', function () {
    $match = surveyMatch();

    /** @var User $md */
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $match->forceFill(['created_by' => $md->id])->save();

    $user = surveyEntrant($match);
    $this->actingAs($user)
        ->post(route('matches.survey.store', $match), surveyAnswersPayload());

    $this->actingAs($md)
        ->get(route('matches.survey.results', $match))
        ->assertForbidden();

    $this->actingAs($md)
        ->post(route('matches.survey.invite', $match))
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
