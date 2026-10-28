<?php

use App\Jobs\SyncImpactLiveScoresJob;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ShooterLog;
use App\Models\Standing;
use App\Models\User;
use App\Services\ImpactScoring\ImpactLiveSyncService;
use App\Services\StandingsCalculationService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true]
    );

    $this->match = liveSyncMatch($this->admin, $this->province);
});

it('pulls exact name matches on match day and leaves everyone else for the director', function () {
    Notification::fake();
    fakeLiveSyncApi();

    $this->match->update(['also_counts_for_provincial' => true]);
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);
    $usersBefore = User::count();

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toBe(3)
        ->and(Score::count())->toBe(1)
        ->and(ScoreImport::count())->toBe(0)
        ->and(User::count())->toBe($usersBefore);

    $score = Score::first();
    expect($score->user_id)->toBe($kevin->id)
        ->and((float) $score->raw_score)->toBe(87.0)
        ->and($score->placement)->toBe(1)
        ->and($score->raw_meta['impact']['shooter_id'])->toBe(1)
        ->and($score->raw_meta['impact']['stages'])->not->toBeEmpty()
        ->and($score->counts_for_season)->toBeFalse()
        ->and($score->provincial_raw_score)->toBeNull()
        ->and($this->match->fresh()->status)->toBe('closed')
        ->and($this->match->fresh()->impact_live_synced_at)->not->toBeNull()
        ->and($this->match->fresh()->impact_live_ended_at)->toBeNull();

    Notification::assertNothingSent();

    $this->get(route('events.show', $this->match))
        ->assertOk()
        ->assertSee('Kevin Goncalves')
        ->assertSee('Incomplete — unverified')
        ->assertSee('https://www.impactscoring.net/match/view?id=11086', false)
        ->assertDontSee('Christiaan Pretorius');
});

it('updates a linked score when the total changes and skips stage calls when it does not', function () {
    fakeLiveSyncApi();
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect(liveSyncStageCalls())->toBe(1);

    fakeLiveSyncApi();
    app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect(liveSyncStageCalls())->toBe(0)
        ->and(Score::count())->toBe(1);

    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 2, 'points' => 9100, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    $score = Score::first();

    expect($result['updated'])->toBe(1)
        ->and($result['created'])->toBe(0)
        ->and(Score::count())->toBe(1)
        ->and((float) $score->raw_score)->toBe(91.0)
        ->and($score->placement)->toBe(2)
        ->and(liveSyncStageCalls())->toBe(1);
});

it('does not overwrite a score the director adjusted', function () {
    fakeLiveSyncApi();
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    Score::create([
        'match_id' => $this->match->id,
        'shooter_name' => 'Kevin Goncalves',
        'user_id' => $kevin->id,
        'division_id' => $this->open->id,
        'day1_raw_score' => 50,
        'placement' => 4,
        'status' => 'valid',
        'match_date' => $this->match->match_date,
        'raw_meta' => [
            'impact' => [
                'shooter_id' => 1,
                'adjusted' => true,
                'adjustment_note' => 'MD correction',
            ],
        ],
    ]);

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($result['held'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and((float) Score::first()->raw_score)->toBe(50.0)
        ->and(liveSyncStageCalls())->toBe(0);
});

it('updates an existing score for that shooter instead of adding a second row', function () {
    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 8700, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    Score::create([
        'match_id' => $this->match->id,
        'shooter_name' => 'Kevin Goncalves',
        'user_id' => $kevin->id,
        'division_id' => $this->open->id,
        'day1_raw_score' => 10,
        'status' => 'valid',
        'match_date' => $this->match->match_date,
    ]);

    app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect(Score::count())->toBe(1)
        ->and((float) Score::first()->raw_score)->toBe(87.0)
        ->and(Score::first()->raw_meta['impact']['shooter_id'])->toBe(1);
});

it('stays quiet off match day, when sync is off, and after Impact marks the match finished', function () {
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    $this->match->update(['match_date' => Carbon::yesterday()->toDateString()]);
    fakeLiveSyncApi();
    $offDay = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect($offDay['skipped'])->toBeTrue()
        ->and(Score::count())->toBe(0);
    Http::assertNothingSent();

    $this->match->update([
        'match_date' => Carbon::today()->toDateString(),
        'impact_live_sync' => false,
    ]);
    fakeLiveSyncApi();
    $disabled = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect($disabled['skipped'])->toBeTrue();
    Http::assertNothingSent();

    $this->match->update(['impact_live_sync' => true]);
    fakeLiveSyncApi(liveSyncPayload([
        'payload' => ['match' => ['matchEnded' => true]],
    ]));
    $ended = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect($ended['ended'])->toBeTrue()
        ->and(Score::count())->toBe(1)
        ->and(Score::first()->counts_for_season)->toBeFalse()
        ->and($this->match->fresh()->status)->toBe('closed')
        ->and($this->match->fresh()->impact_live_ended_at)->not->toBeNull();

    fakeLiveSyncApi();
    $again = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect($again['skipped'])->toBeTrue();
    Http::assertNothingSent();
});

it('keeps live scores unverified until the match director marks them complete', function () {
    Queue::fake();
    fakeLiveSyncApi(liveSyncPayload([
        'payload' => ['match' => ['matchEnded' => true]],
    ]));

    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect(Score::first()->counts_for_season)->toBeFalse()
        ->and(Score::first()->counts_for_log)->toBeFalse();

    $this->get(route('events.show', $this->match))
        ->assertOk()
        ->assertSee('Incomplete — unverified');

    $this->actingAs($this->admin)
        ->post(route('matches.scores.complete', $this->match))
        ->assertRedirect(route('matches.scores', $this->match));

    expect($this->match->fresh()->scoresAreFinalized())->toBeTrue()
        ->and(Score::first()->fresh()->counts_for_season)->toBeTrue()
        ->and(Score::first()->fresh()->counts_for_log)->toBeTrue();

    $this->get(route('events.show', $this->match))
        ->assertOk()
        ->assertDontSee('Incomplete — unverified');

    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'match' => ['matchEnded' => false],
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 9900, 'divisionName' => 'Open'],
            ],
        ],
    ]));
    $afterConfirm = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    expect($afterConfirm['skipped'])->toBeTrue()
        ->and((float) Score::first()->raw_score)->toBe(87.0);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.uncomplete', $this->match))
        ->assertRedirect(route('matches.scores', $this->match));

    expect(Score::first()->fresh()->counts_for_season)->toBeFalse();

    $this->get(route('events.show', $this->match->fresh()))
        ->assertOk()
        ->assertSee('Incomplete — unverified');
});

it('records an Impact outage and leaves the scoreboard alone', function () {
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    Http::fake([
        'app-api.impactscoring.net/*' => Http::response(['errorCode' => 1], 500),
    ]);

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($result['error'])->not->toBeNull()
        ->and(Score::count())->toBe(0)
        ->and($this->match->fresh()->impact_live_error)->not->toBeNull()
        ->and($this->match->fresh()->impact_live_synced_at)->toBeNull();
});

it('saves an Impact results link and the live-sync switch from match settings', function () {
    $this->actingAs($this->admin)
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => 'https://www.impactscoring.net/match/results?id=11086',
            'impact_live_sync' => '1',
        ]))
        ->assertRedirect(route('matches.show', $this->match))
        ->assertSessionHasNoErrors();

    $saved = $this->match->fresh();
    expect($saved->impact_match_id)->toBe(11086)
        ->and($saved->impact_live_sync)->toBeTrue();

    $this->actingAs($this->admin)
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => 'https://www.impactscoring.net/match/view?id=10558',
            'impact_live_sync' => '1',
        ]))
        ->assertRedirect(route('matches.show', $this->match))
        ->assertSessionHasNoErrors();

    expect($this->match->fresh()->impact_match_id)->toBe(10558);

    $this->actingAs($this->admin)
        ->get(route('matches.settings', $this->match))
        ->assertOk()
        ->assertSee('Live scores from Impact')
        ->assertSee('value="10558"', false);

    $this->actingAs($this->admin)
        ->from(route('matches.settings', $this->match))
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => '',
            'impact_live_sync' => '1',
        ]))
        ->assertSessionHasErrors('impact_ref');

    $this->actingAs($this->admin)
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => 'not-a-match',
            'impact_live_sync' => '1',
        ]))
        ->assertSessionHasErrors('impact_ref');
});

it('only queues matches that are on today with live sync still running', function () {
    fakeLiveSyncApi();
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    $yesterday = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Yesterday Club',
        'match_date' => Carbon::yesterday()->toDateString(),
    ]);
    liveSyncRegister($yesterday, $kevin, $this->open);

    $finished = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Already Finished',
        'impact_live_ended_at' => now(),
    ]);

    (new SyncImpactLiveScoresJob)->handle(app(ImpactLiveSyncService::class));

    expect(Score::where('match_id', $this->match->id)->count())->toBe(1)
        ->and(Score::where('match_id', $yesterday->id)->count())->toBe(0)
        ->and(Score::where('match_id', $finished->id)->count())->toBe(0);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->description, SyncImpactLiveScoresJob::class));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone)->toBe('Africa/Johannesburg');
});

it('skips fuzzy names, cancelled entries, and name-only entries without creating anyone', function () {
    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalvs', 'rank' => 1, 'points' => 8700, 'divisionName' => 'Open'],
                ['id' => 2, 'name' => 'Cancelled Shooter', 'rank' => 2, 'points' => 7400, 'divisionName' => 'Open'],
                ['id' => 3, 'name' => 'Walk In Only', 'rank' => 3, 'points' => 7000, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    $usersBefore = User::count();
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    $cancelled = liveSyncMember('Cancelled Shooter');
    liveSyncRegister($this->match, $cancelled, $this->open, [
        'registration_status' => 'cancelled',
    ]);

    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => null,
        'division_id' => $this->open->id,
        'shooter_name' => 'Walk In Only',
        'email' => 'walkin@example.com',
        'membership_fee_category' => 'non_member',
        'fee_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 20,
        'gateway_fee' => 0,
        'md_net_amount' => 30,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($result['created'])->toBe(0)
        ->and(Score::count())->toBe(0)
        ->and(ScoreImport::count())->toBe(0)
        ->and(User::count())->toBe($usersBefore + 2)
        ->and(liveSyncStageCalls())->toBe(0);
});

it('matches a name that only differs by case, spacing, or word order', function () {
    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 1, 'name' => '  KEVIN   goncalves ', 'rank' => 1, 'points' => 8700, 'divisionName' => 'Open'],
                ['id' => 2, 'name' => 'Coverly Justin', 'rank' => 2, 'points' => 5000, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    $kevin = liveSyncMember('Kevin Goncalves');
    $justin = liveSyncMember('Justin Coverly');
    liveSyncRegister($this->match, $kevin, $this->open);
    liveSyncRegister($this->match, $justin, $this->open);

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($result['created'])->toBe(2)
        ->and(Score::count())->toBe(2)
        ->and(Score::where('user_id', $kevin->id)->value('shooter_name'))->toBe('Kevin Goncalves');
});

it('does not pick a shooter when two entries share the exact name', function () {
    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 8700, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    liveSyncRegister($this->match, liveSyncMember('Kevin Goncalves'), $this->open);
    liveSyncRegister($this->match, liveSyncMember('Kevin Goncalves'), $this->open);

    $result = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($result['created'])->toBe(0)
        ->and(Score::count())->toBe(0)
        ->and(liveSyncStageCalls())->toBe(0);
});

it('updates a place change without another stage request, and still saves the total when stages fail', function () {
    $kevin = liveSyncMember('Kevin Goncalves');
    liveSyncRegister($this->match, $kevin, $this->open);

    Score::create([
        'match_id' => $this->match->id,
        'shooter_name' => 'Kevin Goncalves',
        'user_id' => $kevin->id,
        'division_id' => $this->open->id,
        'day1_raw_score' => 87,
        'placement' => 1,
        'status' => 'valid',
        'match_date' => $this->match->match_date,
        'raw_meta' => ['impact' => ['shooter_id' => 1, 'live_sync' => true, 'stages' => [['id' => 1]]]],
    ]);

    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 4, 'points' => 8700, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    $moved = app(ImpactLiveSyncService::class)->sync($this->match->fresh());
    $score = Score::first();

    expect($moved['updated'])->toBe(1)
        ->and($score->placement)->toBe(4)
        ->and((float) $score->raw_score)->toBe(87.0)
        ->and(liveSyncStageCalls())->toBe(0)
        ->and($score->raw_meta['impact']['stages'])->toBe([['id' => 1]]);

    Http::swap(new Factory);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'getLiveScoresForShooter')) {
            return Http::response(['errorCode' => 1], 500);
        }

        if (str_contains($request->url(), 'getLiveScores')) {
            return Http::response(liveSyncPayload([
                'payload' => [
                    'shooters' => [
                        ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 9100, 'divisionName' => 'Open'],
                    ],
                ],
            ]), 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });

    $failedStages = app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    expect($failedStages['updated'])->toBe(1)
        ->and((float) Score::first()->raw_score)->toBe(91.0)
        ->and(Score::first()->raw_meta['impact']['stages'])->toBe([['id' => 1]]);
});

it('pulls through the last day of a multi-day match and ignores every other day', function () {
    $dayTwo = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Still Running',
        'match_date' => Carbon::yesterday()->toDateString(),
        'match_end_date' => Carbon::today()->toDateString(),
    ]);
    $finishedYesterday = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Finished Yesterday',
        'match_date' => Carbon::yesterday()->subDay()->toDateString(),
        'match_end_date' => Carbon::yesterday()->toDateString(),
    ]);
    $tomorrow = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Tomorrow',
        'match_date' => Carbon::tomorrow()->toDateString(),
    ]);
    $draft = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Draft',
        'status' => 'draft',
    ]);
    $cancelled = liveSyncMatch($this->admin, $this->province, [
        'name' => 'Cancelled',
        'status' => 'cancelled',
    ]);

    $due = MatchEvent::dueForImpactLiveSync()->pluck('id');

    expect($due->all())->toContain($this->match->id, $dayTwo->id)
        ->and($due->contains($finishedYesterday->id))->toBeFalse()
        ->and($due->contains($tomorrow->id))->toBeFalse()
        ->and($due->contains($draft->id))->toBeFalse()
        ->and($due->contains($cancelled->id))->toBeFalse()
        ->and($dayTwo->isDueForImpactLiveSync())->toBeTrue()
        ->and($finishedYesterday->isDueForImpactLiveSync())->toBeFalse()
        ->and($tomorrow->isDueForImpactLiveSync())->toBeFalse();
});

it('keeps pulling the other matches when one sync throws', function () {
    $bad = liveSyncMatch($this->admin, $this->province, ['name' => 'Bad Match']);
    $good = liveSyncMatch($this->admin, $this->province, ['name' => 'Good Match']);
    $seen = [];

    $sync = Mockery::mock(ImpactLiveSyncService::class);
    $sync->shouldReceive('sync')->andReturnUsing(function (MatchEvent $match) use ($bad, &$seen) {
        $seen[] = $match->id;
        if ($match->id === $bad->id) {
            throw new RuntimeException('Impact blew up');
        }

        return ['skipped' => true];
    });

    (new SyncImpactLiveScoresJob)->handle($sync);

    expect($seen)->toContain($bad->id, $good->id, $this->match->id)
        ->and($bad->fresh()->impact_live_error)->toContain('Impact blew up')
        ->and($good->fresh()->impact_live_error)->toBeNull()
        ->and($this->match->fresh()->impact_live_error)->toBeNull();
});

it('leaves the Impact link alone when a save does not include it, and restarts only when the link changes', function () {
    $this->match->update([
        'impact_match_id' => 11086,
        'impact_live_sync' => true,
        'impact_live_ended_at' => now(),
        'impact_live_error' => 'stale failure',
    ]);

    $plain = liveSyncForm();
    unset($plain['impact_ref'], $plain['impact_live_sync']);

    $this->actingAs($this->admin)
        ->put(route('matches.update', $this->match), $plain)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $untouched = $this->match->fresh();
    expect($untouched->impact_match_id)->toBe(11086)
        ->and($untouched->impact_live_sync)->toBeTrue()
        ->and($untouched->impact_live_ended_at)->not->toBeNull()
        ->and($untouched->impact_live_error)->toBe('stale failure');

    $this->actingAs($this->admin)
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => 'https://www.impactscoring.net/match/results?id=11086',
            'impact_live_sync' => '0',
        ]))
        ->assertSessionHasNoErrors();

    $paused = $this->match->fresh();
    expect($paused->impact_match_id)->toBe(11086)
        ->and($paused->impact_live_sync)->toBeFalse()
        ->and($paused->impact_live_ended_at)->not->toBeNull();

    $this->actingAs($this->admin)
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => 'https://www.impactscoring.net/match/view?id=10558',
            'impact_live_sync' => '1',
        ]))
        ->assertSessionHasNoErrors();

    $restarted = $this->match->fresh();
    expect($restarted->impact_match_id)->toBe(10558)
        ->and($restarted->impact_live_sync)->toBeTrue()
        ->and($restarted->impact_live_ended_at)->toBeNull()
        ->and($restarted->impact_live_error)->toBeNull();

    $this->actingAs($this->admin)
        ->from(route('matches.settings', $this->match))
        ->put(route('matches.update', $this->match), liveSyncForm([
            'impact_ref' => 'https://www.impactscoring.net/shooter/view?id=10558',
            'impact_live_sync' => '1',
        ]))
        ->assertSessionHasErrors('impact_ref');

    expect($this->match->fresh()->impact_match_id)->toBe(10558);
});

it('keeps an unconfirmed live score off the season and the official log until the director confirms it', function () {
    Queue::fake();
    Notification::fake();

    $this->match->update(['series_level' => 'national']);

    $live = liveSyncMember('Live Season Shooter');
    $manual = liveSyncMember('Already Counted Shooter');
    liveSyncRegister($this->match, $live, $this->open);
    liveSyncRegister($this->match, $manual, $this->open);

    Score::create([
        'match_id' => $this->match->id,
        'shooter_name' => 'Already Counted Shooter',
        'user_id' => $manual->id,
        'division_id' => $this->open->id,
        'day1_raw_score' => 70,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $this->match->match_date,
    ]);

    app(StandingsCalculationService::class)->recalculateForMatch($this->match->fresh());

    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 9, 'name' => 'Live Season Shooter', 'rank' => 1, 'points' => 9000, 'divisionName' => 'Open'],
            ],
        ],
    ]));

    app(ImpactLiveSyncService::class)->sync($this->match->fresh());

    $liveScore = Score::where('user_id', $live->id)->first();
    expect($liveScore)->not->toBeNull()
        ->and($liveScore->counts_for_season)->toBeFalse()
        ->and((bool) ShooterLog::where('score_id', $liveScore->id)->value('counted'))->toBeFalse()
        ->and(Standing::where('user_id', $live->id)->exists())->toBeFalse()
        ->and(Standing::where('user_id', $manual->id)->exists())->toBeTrue();

    $this->get('/standings/prs?season=2026')
        ->assertOk()
        ->assertDontSee('Live Season Shooter')
        ->assertSee('Already Counted Shooter');

    $this->actingAs($this->admin)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('incomplete — unverified', false);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.complete', $this->match))
        ->assertRedirect();

    expect($liveScore->fresh()->counts_for_season)->toBeTrue()
        ->and((bool) ShooterLog::where('score_id', $liveScore->id)->value('counted'))->toBeTrue()
        ->and(Standing::where('user_id', $live->id)->exists())->toBeTrue()
        ->and(Standing::where('user_id', $manual->id)->exists())->toBeTrue();

    $this->get('/standings/prs?season=2026')
        ->assertOk()
        ->assertSee('Live Season Shooter');

    fakeLiveSyncApi(liveSyncPayload([
        'payload' => [
            'shooters' => [
                ['id' => 9, 'name' => 'Live Season Shooter', 'rank' => 1, 'points' => 9900, 'divisionName' => 'Open'],
            ],
        ],
    ]));
    (new SyncImpactLiveScoresJob)->handle(app(ImpactLiveSyncService::class));
    expect((float) $liveScore->fresh()->raw_score)->toBe(90.0);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.uncomplete', $this->match));

    expect($liveScore->fresh()->counts_for_season)->toBeFalse()
        ->and((bool) ShooterLog::where('score_id', $liveScore->id)->value('counted'))->toBeFalse()
        ->and(Standing::where('user_id', $live->id)->exists())->toBeFalse()
        ->and(Standing::where('user_id', $manual->id)->exists())->toBeTrue();
});

function liveSyncMatch(User $owner, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Saturday Club Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'club',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->toDateString(),
        'status' => 'closed',
        'published' => true,
        'created_by' => $owner->id,
        'active_member_fee' => 100,
        'impact_match_id' => 11086,
        'impact_live_sync' => true,
    ], $overrides));
}

function liveSyncMember(string $name): User
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

function liveSyncRegister(MatchEvent $match, User $user, Division $division, array $extra = []): MatchRegistration
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

function liveSyncPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'errorCode' => 0,
        'errorLabel' => 'SUCCESS',
        'payload' => [
            'match' => [
                'id' => 11086,
                'name' => 'Saturday Club Match',
                'matchEnded' => false,
                'matchStarted' => true,
            ],
            'divisions' => [
                ['id' => 1, 'name' => 'Open'],
            ],
            'stages' => [
                ['id' => 28438, 'name' => 'Stage 1'],
            ],
            'categories' => [],
            'shooters' => [
                ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 8700, 'divisionName' => 'Open'],
                ['id' => 2, 'name' => 'Justin Coverly', 'rank' => 2, 'points' => 7400, 'divisionName' => 'Production'],
                ['id' => 3, 'name' => 'Aliza Mey', 'rank' => 3, 'points' => 7000, 'divisionName' => 'Open'],
                ['id' => 4, 'name' => 'Christiaan Pretorius', 'rank' => 4, 'points' => 6300, 'divisionName' => 'Open'],
            ],
        ],
    ], $overrides);
}

function fakeLiveSyncApi(?array $live = null): void
{
    $live ??= liveSyncPayload();

    // Http::fake() appends stubs. A fresh factory so the latest payload wins.
    Http::swap(new Factory);

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
                            'points' => 8700,
                            'misses' => 0,
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

function liveSyncStageCalls(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'getLiveScoresForShooter'))
        ->count();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function liveSyncForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Saturday Club Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'match_date' => Carbon::today()->toDateString(),
        'active_member_fee' => 100,
        'status' => 'closed',
        'impact_ref' => '11086',
        'impact_live_sync' => '1',
    ], $overrides);
}
