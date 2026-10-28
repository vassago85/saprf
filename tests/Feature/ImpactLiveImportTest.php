<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\User;
use App\Notifications\ImpactImportCollectionReportNotification;
use App\Services\ImpactScoring\ImpactLiveImportService;
use App\Services\ImpactScoring\ImpactScoringClient;
use App\Services\ImpactScoring\ImpactStageDays;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    foreach (['open', 'factory', 'senior', 'junior', 'ladies'] as $i => $slug) {
        Division::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'display_order' => $i + 1, 'is_active' => true]
        );
    }

    $this->match = MatchEvent::create([
        'name' => 'Warrior Grind Provincial',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'published',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ]);

    $this->open = Division::where('slug', 'open')->first();
    $this->factory = Division::where('slug', 'factory')->first();
    $this->ladies = Division::where('slug', 'ladies')->first();
    $this->junior = Division::where('slug', 'junior')->first();
});

function impactLivePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'errorCode' => 0,
        'errorLabel' => 'SUCCESS',
        'payload' => [
            'match' => [
                'id' => 11086,
                'name' => 'SAPRF Provincial Warrior Grind 2.0',
                'matchEnded' => true,
                'matchStarted' => true,
            ],
            'divisions' => [
                ['id' => 1, 'name' => 'Open'],
                ['id' => 2, 'name' => 'Production'],
            ],
            'stages' => [
                ['id' => 28438, 'name' => 'Stage 1'],
                ['id' => 28439, 'name' => 'Stage 2'],
            ],
            'categories' => [],
            'shooters' => [
                [
                    'id' => 1,
                    'name' => 'Kevin Goncalves',
                    'rank' => 1,
                    'points' => 8700,
                    'divisionName' => 'Open',
                ],
                [
                    'id' => 2,
                    'name' => 'Justin Coverly',
                    'rank' => 2,
                    'points' => 7400,
                    'divisionName' => 'Production',
                ],
                [
                    'id' => 3,
                    'name' => 'Aliza Mey',
                    'rank' => 3,
                    'points' => 7000,
                    'divisionName' => 'Open',
                ],
                [
                    'id' => 4,
                    'name' => 'Christiaan Pretorius',
                    'rank' => 4,
                    'points' => 6300,
                    'divisionName' => 'Open',
                ],
            ],
        ],
    ], $overrides);
}

function fakeImpactApi(?array $live = null): void
{
    $live ??= impactLivePayload();

    Http::fake(function (Request $request) use ($live) {
        $url = $request->url();
        if (str_contains($url, '/rest/website/getLiveScoresForShooter')) {
            return Http::response([
                'errorCode' => 0,
                'payload' => [
                    'stages' => [
                        [
                            'id' => 28438,
                            'name' => 'Stage 1',
                            'points' => 1200,
                            'misses' => 0,
                            'shotsProgressCount' => 12,
                            'maxShots' => 12,
                            'completed' => true,
                        ],
                        [
                            'id' => 28439,
                            'name' => 'Stage 2',
                            'points' => 800,
                            'misses' => 4,
                            'shotsProgressCount' => 12,
                            'maxShots' => 12,
                            'completed' => true,
                        ],
                    ],
                ],
            ], 200);
        }
        if (str_contains($url, '/rest/website/getLiveScores')) {
            return Http::response($live, 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });
}

function impactMakeMember(string $name): User
{
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    Membership::create([
        'user_id' => $user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    return $user;
}

function impactRegisterShooter(MatchEvent $match, User $user, Division $division, array $extra = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'division_id' => $division->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 20,
        'gateway_fee' => 0,
        'md_net_amount' => 30,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $extra));
}

it('parses an Impact results URL into a match id', function () {
    $client = app(ImpactScoringClient::class);

    expect($client->parseMatchId('https://www.impactscoring.net/match/results?id=11086'))->toBe(11086)
        ->and($client->parseMatchId('https://www.impactscoring.net/match/view?id=10558'))->toBe(10558)
        ->and($client->parseMatchId('https://www.impactscoring.net/match/view/?id=10558'))->toBe(10558)
        ->and($client->parseMatchId('http://impactscoring.net/match/view?id=10558&tab=scores'))->toBe(10558)
        ->and($client->parseMatchId('https://www.impactscoring.net/match/results/11086'))->toBe(11086)
        ->and($client->parseMatchId('  10558  '))->toBe(10558)
        ->and($client->parseMatchId('11086'))->toBe(11086);
});

it('rejects an Impact link that is not the match itself', function (string $input) {
    $client = app(ImpactScoringClient::class);

    expect(fn () => $client->parseMatchId($input))->toThrow(RuntimeException::class);
})->with([
    'shooter page' => 'https://www.impactscoring.net/shooter/view?id=10558',
    'stage page' => 'https://www.impactscoring.net/stage/view?id=10558',
    'another site' => 'https://example.com/match/view?id=10558',
    'empty' => '',
    'words' => 'not-a-match',
]);

it('previews a match from the Impact match page url', function () {
    fakeImpactApi();

    $preview = app(ImpactLiveImportService::class)->preview(
        $this->match,
        'https://www.impactscoring.net/match/view?id=11086',
    );

    expect($preview['impact_match_id'])->toBe(11086)
        ->and($preview['shooters'])->not->toBeEmpty();
});

it('previews Impact shooters and prefers registration divisions (Production→Factory fallback)', function () {
    fakeImpactApi();

    $kevin = impactMakeMember('Kevin Goncalves');
    $justin = impactMakeMember('Justin Coverly');
    $aliza = impactMakeMember('Aliza Mey');
    $chris = impactMakeMember('Chris Pretorius');

    impactRegisterShooter($this->match, $kevin, $this->open);
    impactRegisterShooter($this->match, $justin, $this->factory);
    impactRegisterShooter($this->match, $aliza, $this->ladies, [
        'registration_status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
    impactRegisterShooter($this->match, $chris, $this->open);

    $this->actingAs($this->admin)
        ->postJson(route('score-imports.impact.preview'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('preview.impact_match_name', 'SAPRF Provincial Warrior Grind 2.0')
        ->assertJsonPath('preview.summary.exact', 3)
        ->assertJsonPath('preview.summary.fuzzy', 1);

    $preview = app(ImpactLiveImportService::class)
        ->preview($this->match, '11086');

    $byName = collect($preview['shooters'])->keyBy('impact_name');

    expect($byName['Kevin Goncalves']['division_slug'])->toBe('open')
        ->and($byName['Justin Coverly']['division_slug'])->toBe('factory')
        ->and($byName['Aliza Mey']['division_slug'])->toBe('ladies')
        ->and($byName['Aliza Mey']['division_source'])->toBe('registration')
        ->and($byName['Christiaan Pretorius']['match_status'])->toBe('fuzzy');
});

it('imports Impact scores using registration divisions and confirmed fuzzy links', function () {
    fakeImpactApi();
    Notification::fake();

    $kevin = impactMakeMember('Kevin Goncalves');
    $justin = impactMakeMember('Justin Coverly');
    $aliza = impactMakeMember('Aliza Mey');
    $chris = impactMakeMember('Chris Pretorius');

    impactRegisterShooter($this->match, $kevin, $this->open);
    impactRegisterShooter($this->match, $justin, $this->factory);
    impactRegisterShooter($this->match, $aliza, $this->ladies, [
        'registration_status' => 'pending',
        'payment_status' => 'unpaid',
        'fee_amount' => 100,
    ]);
    $chrisReg = impactRegisterShooter($this->match, $chris, $this->open);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => 'https://www.impactscoring.net/match/results?id=11086',
            'replace_existing' => 1,
            'links' => [
                '4' => 'reg:'.$chrisReg->id,
            ],
        ])
        ->assertRedirect();

    expect(ScoreImport::where('source_type', 'impact_live')->count())->toBe(1)
        ->and(Score::where('match_id', $this->match->id)->count())->toBe(4);

    $alizaScore = Score::where('match_id', $this->match->id)->where('user_id', $aliza->id)->first();
    expect($alizaScore)->not->toBeNull()
        ->and($alizaScore->division?->slug)->toBe('ladies')
        ->and((float) $alizaScore->raw_score)->toBe(70.0);

    $justinScore = Score::where('match_id', $this->match->id)->where('user_id', $justin->id)->first();
    expect($justinScore->division?->slug)->toBe('factory');

    $chrisScore = Score::where('match_id', $this->match->id)->where('user_id', $chris->id)->first();
    expect($chrisScore)->not->toBeNull()
        ->and((float) $chrisScore->raw_score)->toBe(63.0);

    Notification::assertSentTo($this->admin, ImpactImportCollectionReportNotification::class);
});

it('builds a Day 1 provincial score from selected Impact stages on a 2-day national', function () {
    fakeImpactApi();
    Notification::fake();

    $national = MatchEvent::create([
        'name' => 'PR22 Nationals 2026',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->subDay()->toDateString(),
        'match_end_date' => Carbon::yesterday()->toDateString(),
        'status' => 'published',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ]);

    $kevin = impactMakeMember('Kevin Goncalves');
    impactRegisterShooter($national, $kevin, $this->open);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $national->id,
            'impact_ref' => '11086',
            'score_scope' => 'day1',
            'day1_stage_ids' => [28438],
            'replace_existing' => 1,
            'links' => [],
        ])
        ->assertRedirect();

    $sibling = $national->fresh()->provincialDay1Match;
    expect($sibling)->not->toBeNull();

    $score = Score::where('match_id', $sibling->id)->where('user_id', $kevin->id)->first();
    expect($score)->not->toBeNull()
        ->and((float) $score->raw_score)->toBe(12.0)
        ->and($score->raw_meta['impact']['day1_stage_ids'])->toBe([28438]);
});

it('treats unlabelled Impact stages as day 1 when a later day is named', function () {
    $days = ImpactStageDays::idsByDay([
        ['id' => 28932, 'name' => 'stage 1 '],
        ['id' => 28942, 'name' => 'stage 10'],
        ['id' => 28943, 'name' => 'Day 2 Stage 1'],
        ['id' => 28948, 'name' => 'Day 2 Stage 6'],
    ]);

    expect($days[1])->toBe([28932, 28942])
        ->and($days[2])->toBe([28943, 28948]);
});

it('sums each Day 1 stage when the Impact day total is zero', function () {
    Notification::fake();

    $live = impactLivePayload();
    $live['payload']['match']['name'] = 'Darling Steel Valley 2026';
    $live['payload']['stages'] = [
        ['id' => 1, 'name' => 'stage 1'],
        ['id' => 2, 'name' => 'stage 2'],
        ['id' => 3, 'name' => 'Day 2 Stage 1'],
    ];
    $live['payload']['shooters'] = [[
        'id' => 1,
        'name' => 'Kevin Goncalves',
        'rank' => 1,
        'points' => 0,
        'divisionName' => 'Open',
    ]];

    Http::fake(function (Request $request) use ($live) {
        $url = $request->url();
        if (str_contains($url, '/rest/website/getLiveScoresForShooter')) {
            return Http::response([
                'errorCode' => 0,
                'payload' => [
                    'stages' => [
                        ['id' => 1, 'name' => 'stage 1', 'points' => 600, 'misses' => 0, 'shotsProgressCount' => 8, 'maxShots' => 8, 'completed' => true],
                        ['id' => 2, 'name' => 'stage 2', 'points' => 800, 'misses' => 0, 'shotsProgressCount' => 8, 'maxShots' => 8, 'completed' => true],
                        ['id' => 3, 'name' => 'Day 2 Stage 1', 'points' => 1000, 'misses' => 0, 'shotsProgressCount' => 10, 'maxShots' => 10, 'completed' => true],
                    ],
                ],
            ], 200);
        }
        if (str_contains($url, '/rest/website/getLiveScores')) {
            return Http::response($live, 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });

    $national = MatchEvent::create([
        'name' => 'Darling Steel Valley PR22',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->subDay()->toDateString(),
        'match_end_date' => Carbon::yesterday()->toDateString(),
        'status' => 'published',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ]);

    $kevin = impactMakeMember('Kevin Goncalves');
    impactRegisterShooter($national, $kevin, $this->open);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $national->id,
            'impact_ref' => '11188',
            'score_scope' => 'day1',
            'replace_existing' => 1,
            'links' => [],
            'score_overrides' => [
                '1' => ['day1_impacts' => 0],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sibling = $national->fresh()->provincialDay1Match;
    $score = Score::where('match_id', $sibling->id)->where('user_id', $kevin->id)->first();

    expect($score)->not->toBeNull()
        ->and((float) $score->raw_score)->toBe(14.0)
        ->and($score->raw_meta['impact']['day1_stage_ids'])->toBe([1, 2])
        ->and($score->raw_meta['impact']['adjusted'])->toBeFalse();

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $national->id,
            'impact_ref' => '11188',
            'score_scope' => 'day1',
            'day1_stage_ids' => [1, 2],
            'replace_existing' => 1,
            'links' => [],
            'score_overrides' => [
                '1' => ['day1_impacts' => 0, 'note' => 'did not shoot'],
            ],
        ])
        ->assertRedirect();

    $score = Score::where('match_id', $sibling->id)->where('user_id', $kevin->id)->first();
    expect((float) $score->raw_score)->toBe(0.0)
        ->and($score->raw_meta['impact']['adjusted'])->toBeTrue();
});

it('adds every stage when the Impact match total is zero', function () {
    Notification::fake();

    $live = impactLivePayload();
    $live['payload']['shooters'] = [[
        'id' => 1,
        'name' => 'Kevin Goncalves',
        'rank' => 1,
        'points' => 0,
        'divisionName' => 'Open',
    ]];

    Http::fake(function (Request $request) use ($live) {
        $url = $request->url();
        if (str_contains($url, '/rest/website/getLiveScoresForShooter')) {
            return Http::response([
                'errorCode' => 0,
                'payload' => [
                    'stages' => [
                        ['id' => 28438, 'name' => 'Stage 1', 'points' => 1200, 'misses' => 0, 'shotsProgressCount' => 12, 'maxShots' => 12, 'completed' => true],
                        ['id' => 28439, 'name' => 'Stage 2', 'points' => 800, 'misses' => 0, 'shotsProgressCount' => 12, 'maxShots' => 12, 'completed' => true],
                    ],
                ],
            ], 200);
        }
        if (str_contains($url, '/rest/website/getLiveScores')) {
            return Http::response($live, 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });

    $kevin = impactMakeMember('Kevin Goncalves');
    impactRegisterShooter($this->match, $kevin, $this->open);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11188',
            'replace_existing' => 1,
            'links' => [],
        ])
        ->assertRedirect();

    $score = Score::where('match_id', $this->match->id)->where('user_id', $kevin->id)->first();
    expect($score)->not->toBeNull()
        ->and((float) $score->raw_score)->toBe(20.0);
});

it('accepts an MD override of Impact hits before import and records the adjustment', function () {
    fakeImpactApi();
    Notification::fake();

    $kevin = impactMakeMember('Kevin Goncalves');
    impactRegisterShooter($this->match, $kevin, $this->open);

    // Impact has 87; shooter claims 88 — MD adjusts on preview before confirm.
    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => [],
            'score_overrides' => [
                '1' => [
                    'impacts' => 88,
                    'note' => 'adjusted from Impact',
                ],
            ],
        ])
        ->assertRedirect();

    $score = Score::where('match_id', $this->match->id)->where('user_id', $kevin->id)->first();
    expect($score)->not->toBeNull()
        ->and((float) $score->raw_score)->toBe(88.0)
        ->and((float) $score->raw_meta['impact']['total_impacts'])->toBe(87.0)
        ->and($score->raw_meta['impact']['adjusted'])->toBeTrue()
        ->and((float) $score->raw_meta['impact']['adjusted_total_impacts'])->toBe(88.0)
        ->and($score->raw_meta['impact']['adjustment_note'])->toBe('adjusted from Impact');
});

it('skips unconfirmed fuzzy matches on import', function () {
    fakeImpactApi();
    Notification::fake();

    $kevin = impactMakeMember('Kevin Goncalves');
    impactRegisterShooter($this->match, $kevin, $this->open);
    // Chris exists on entry list but fuzzy link not confirmed
    $chris = impactMakeMember('Chris Pretorius');
    impactRegisterShooter($this->match, $chris, $this->open);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => [],
        ])
        ->assertRedirect();

    expect(Score::where('match_id', $this->match->id)->where('user_id', $chris->id)->exists())->toBeFalse()
        ->and(Score::where('match_id', $this->match->id)->where('user_id', $kevin->id)->exists())->toBeTrue();

    $import = ScoreImport::where('source_type', 'impact_live')->latest('id')->first();
    $reasons = collect($import->raw_payload['audit'] ?? [])->pluck('reason');
    expect($reasons)->toContain('skipped_fuzzy');
});

it('imports assign-later as an invalid stub until linked to a real registration', function () {
    Notification::fake();

    $stephanus = impactMakeMember('Stephanus Janse van Rensburg');
    $senior = Division::where('slug', 'senior')->first();
    $reg = impactRegisterShooter($this->match, $stephanus, $senior);

    // Single Impact shooter — different display name from the registration.
    $payload = impactLivePayload();
    $payload['payload']['shooters'] = [
        [
            'id' => 28,
            'name' => 'Steven van Rensburg',
            'rank' => 28,
            'points' => 5200,
            'divisionName' => 'Open',
        ],
    ];
    fakeImpactApi($payload);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => [
                '28' => 'assign_later',
            ],
        ])
        ->assertRedirect(route('score-imports.show', ScoreImport::latest('id')->first()));

    $score = Score::where('match_id', $this->match->id)->first();
    expect($score)->not->toBeNull()
        ->and($score->shooter_name)->toBe('Steven van Rensburg')
        ->and($score->status)->toBe('invalid')
        ->and($score->is_member)->toBeFalse()
        ->and($score->raw_meta['impact']['needs_member_link'])->toBeTrue()
        ->and((float) $score->raw_score)->toBe(52.0);

    $import = ScoreImport::where('source_type', 'impact_live')->latest('id')->first();
    expect(collect($import->raw_payload['audit'])->pluck('reason'))->toContain('assign_later');

    Notification::assertSentTo($this->admin, ImpactImportCollectionReportNotification::class);

    $this->actingAs($this->admin)
        ->post(route('scores.link-impact-member', $score), [
            'registration_id' => $reg->id,
        ])
        ->assertRedirect();

    $score->refresh();
    expect($score->user_id)->toBe($stephanus->id)
        ->and($score->division?->slug)->toBe('senior')
        ->and($score->raw_meta['impact']['needs_member_link'])->toBeFalse()
        ->and($score->status)->toBe('valid')
        ->and($score->is_member)->toBeTrue()
        // Impact display name is kept even though the entry is Stephanus.
        ->and($score->shooter_name)->toBe('Steven van Rensburg')
        ->and($score->raw_meta['impact']['linked_entry_name'])->toBe('Stephanus Janse van Rensburg')
        ->and($score->raw_meta['impact']['kept_impact_name'])->toBeTrue();
});

it('keeps the Impact name when linking Kaden to a Karen entry', function () {
    Notification::fake();

    $karen = impactMakeMember('Karen Watridge');
    $reg = impactRegisterShooter($this->match, $karen, $this->junior);

    $payload = impactLivePayload();
    $payload['payload']['shooters'] = [
        [
            'id' => 41,
            'name' => 'Kaden Watridge',
            'rank' => 41,
            'points' => 3800,
            'divisionName' => 'Open',
        ],
    ];
    fakeImpactApi($payload);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => ['41' => 'assign_later'],
        ])
        ->assertRedirect();

    $score = Score::where('match_id', $this->match->id)->first();

    $this->actingAs($this->admin)
        ->post(route('scores.link-impact-member', $score), [
            'registration_id' => $reg->id,
        ])
        ->assertRedirect();

    $score->refresh();
    expect($score->user_id)->toBe($karen->id)
        ->and($score->shooter_name)->toBe('Kaden Watridge')
        ->and($score->division?->slug)->toBe('junior')
        ->and($score->raw_meta['impact']['linked_entry_name'])->toBe('Karen Watridge')
        ->and($score->raw_meta['impact']['kept_impact_name'])->toBeTrue()
        ->and($score->status)->toBe('valid');
});

it('links an assign-later score to a non-member entry and keeps them non-counting', function () {
    Notification::fake();

    $nonMember = User::factory()->create([
        'name' => 'Jacobus Janse van Rensburg',
        'email_verified_at' => now(),
    ]);
    // No membership row → non_member after link.
    $reg = impactRegisterShooter($this->match, $nonMember, $this->junior, [
        'membership_fee_category' => 'non_member',
        'fee_amount' => 150,
        'payment_status' => 'unpaid',
        'registration_status' => 'confirmed',
    ]);

    $payload = impactLivePayload();
    $payload['payload']['shooters'] = [
        [
            'id' => 27,
            'name' => 'Jacobus J van Rensburg',
            'rank' => 27,
            'points' => 5300,
            'divisionName' => 'Open',
        ],
    ];
    fakeImpactApi($payload);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => ['27' => 'assign_later'],
        ])
        ->assertRedirect();

    $score = Score::where('match_id', $this->match->id)->first();
    expect($score->raw_meta['impact']['needs_member_link'])->toBeTrue();

    $this->actingAs($this->admin)
        ->get(route('score-imports.show', ScoreImport::latest('id')->first()))
        ->assertOk()
        ->assertSee('Link to entry')
        ->assertSee('Non-member');

    $this->actingAs($this->admin)
        ->post(route('scores.link-impact-member', $score), [
            'registration_id' => $reg->id,
        ])
        ->assertRedirect();

    $score->refresh();
    expect($score->user_id)->toBe($nonMember->id)
        ->and($score->division?->slug)->toBe('junior')
        ->and($score->raw_meta['impact']['needs_member_link'])->toBeFalse()
        ->and($score->status)->toBe('non_member')
        ->and($score->is_member)->toBeFalse()
        // Keep Impact spelling/name; entry was the longer registration name.
        ->and($score->shooter_name)->toBe('Jacobus J van Rensburg');
});

it('links an assign-later score to a name-only non-member walk-in entry', function () {
    Notification::fake();

    $reg = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => null,
        'division_id' => $this->junior->id,
        'shooter_name' => 'Kaden Watridge',
        'email' => null,
        'membership_fee_category' => 'non_member',
        'fee_amount' => 150,
        'saprf_fee' => 0,
        'platform_fee' => 0,
        'gateway_fee' => 0,
        'md_net_amount' => 0,
        'payment_status' => 'unpaid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $payload = impactLivePayload();
    $payload['payload']['shooters'] = [
        [
            'id' => 40,
            'name' => 'Kaden Watridge',
            'rank' => 40,
            'points' => 4000,
            'divisionName' => 'Open',
        ],
    ];
    fakeImpactApi($payload);

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => ['40' => 'assign_later'],
        ])
        ->assertRedirect();

    $score = Score::where('match_id', $this->match->id)->first();
    $stubId = $score->user_id;

    $this->actingAs($this->admin)
        ->post(route('scores.link-impact-member', $score), [
            'registration_id' => $reg->id,
        ])
        ->assertRedirect();

    $score->refresh();
    expect($score->user_id)->toBe($stubId)
        ->and($score->shooter_name)->toBe('Kaden Watridge')
        ->and($score->division?->slug)->toBe('junior')
        ->and($score->raw_meta['impact']['needs_member_link'])->toBeFalse()
        ->and($score->raw_meta['impact']['linked_name_only_entry'])->toBeTrue()
        ->and($score->status)->toBe('non_member');
});

it('exposes Impact import on the match page and shows the audit after import', function () {
    fakeImpactApi();
    Notification::fake();

    $kevin = impactMakeMember('Kevin Goncalves');
    impactRegisterShooter($this->match, $kevin, $this->open, [
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'fee_amount' => 100,
    ]);

    $this->actingAs($this->admin)
        ->get(route('matches.impact-import', $this->match))
        ->assertOk()
        ->assertSee('Import from Impact')
        ->assertSee('Assign later');

    // The "Import from Impact" entry point moved from the old show page
    // onto the new Scores tab of the match workspace.
    $this->actingAs($this->admin)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Import from Impact');

    $response = $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->match->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => [],
        ]);

    $import = ScoreImport::where('source_type', 'impact_live')->latest('id')->first();
    $response->assertRedirect(route('score-imports.show', $import));

    $this->actingAs($this->admin)
        ->get(route('score-imports.show', $import))
        ->assertOk()
        ->assertSee('Post-import audit')
        ->assertSee('Unpaid / pending');
});
