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
use App\Services\ImpactScoring\ImpactScoringClient;
use Carbon\Carbon;
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

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($live) {
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
        ->and($client->parseMatchId('11086'))->toBe(11086);
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

    $preview = app(\App\Services\ImpactScoring\ImpactLiveImportService::class)
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
});
