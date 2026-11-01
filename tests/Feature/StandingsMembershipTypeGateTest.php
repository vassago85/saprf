<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP'],
    );

    $this->open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true],
    );

    $this->matchDate = Carbon::today()->subDays(14);

    $this->match = MatchEvent::create([
        'name' => 'Full-member-only standings regression',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => $this->matchDate,
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    // Lazy helper: hand it a user, a membership_type, and a raw score, get
    // back a persisted score with the right status so the standings builder
    // treats it as "would otherwise count".
    $this->rankableScore = function (string $name, string $membershipType, float $rawScore): Score {
        $user = User::factory()->create([
            'name' => $name,
            'province_id' => $this->province->id,
        ]);

        Membership::create([
            'user_id' => $user->id,
            'saprf_number' => 'SAPRF-GATE-'.$user->id,
            'membership_type' => $membershipType,
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => $this->matchDate->copy()->subMonth(),
            'expiry_date' => $this->matchDate->copy()->addYear(),
        ]);

        return Score::create([
            'match_id' => $this->match->id,
            'user_id' => $user->id,
            'shooter_name' => $name,
            'division_id' => $this->open->id,
            'raw_score' => $rawScore,
            'status' => 'valid',
            'is_member' => true,
            'counts_for_season' => true,
            'match_date' => $this->matchDate,
        ]);
    };
});

it('ranks full federation members on the season standings', function () {
    $paid = ($this->rankableScore)('Paid Pete', 'paid', 95);
    $full = ($this->rankableScore)('Full Fiona', 'full', 90);
    $junior = ($this->rankableScore)('Junior Jade', 'junior', 85);

    app(StandingsCalculationService::class)->recalculateForMatch($this->match);

    $userIds = Standing::where('series', 'PR22')
        ->where('season', '2026')
        ->pluck('user_id')
        ->unique()
        ->all();

    expect($userIds)->toContain($paid->user_id, $full->user_id, $junior->user_id);
});

it('keeps associates and other non-full tiers out of standings even when the score is valid', function () {
    $full = ($this->rankableScore)('Full Fiona', 'full', 90);
    $associate = ($this->rankableScore)('Associate Alvin', 'associate', 95);
    $club = ($this->rankableScore)('Club Cassie', 'club', 92);

    expect($associate->fresh()->status)->toBe('valid')
        ->and($club->fresh()->status)->toBe('valid');

    app(StandingsCalculationService::class)->recalculateForMatch($this->match);

    $userIds = Standing::where('series', 'PR22')
        ->where('season', '2026')
        ->pluck('user_id')
        ->unique()
        ->all();

    expect($userIds)->toContain($full->user_id)
        ->and($userIds)->not->toContain($associate->user_id)
        ->and($userIds)->not->toContain($club->user_id);
});

it('removes a previously-ranked shooter whose membership_type is downgraded on the next rebuild', function () {
    $wasFull = ($this->rankableScore)('Downgraded Dave', 'full', 90);

    app(StandingsCalculationService::class)->recalculateForMatch($this->match);
    expect(Standing::where('user_id', $wasFull->user_id)->exists())->toBeTrue();

    // Later, admin reclassifies him as an associate (club member).
    Membership::where('user_id', $wasFull->user_id)
        ->update(['membership_type' => 'associate']);

    app(StandingsCalculationService::class)->recalculateForMatch($this->match);

    expect(Standing::where('user_id', $wasFull->user_id)->exists())->toBeFalse();
});

it('still shows non-full shooters on the match page even though they are gated out of standings', function () {
    ($this->rankableScore)('Full Fiona', 'full', 90);
    $associate = ($this->rankableScore)('Associate Alvin', 'associate', 95);

    // Match-page results are read directly from the scores table and are
    // unaffected by the standings builder, so the associate must still appear
    // on the match's results.
    $response = $this->get(route('events.show', $this->match))
        ->assertOk()
        ->assertSee('Associate Alvin')
        ->assertSee('Full Fiona');

    expect($associate->fresh()->status)->toBe('valid');
});

it('also gates PRS annual-log standings on membership_type', function () {
    $ruleAuthor = User::factory()->create();

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => 'best_n_plus_champs',
        'best_of_count' => 3,
        'created_by' => $ruleAuthor->id,
    ]);

    $nationalMatch = MatchEvent::create([
        'name' => 'PRS National Smokescreen',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => $this->matchDate,
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => User::factory()->create()->id,
    ]);

    // Rebind the helper to the PRS national match for this test only.
    $buildPrsScore = function (string $name, string $membershipType, float $rawScore) use ($nationalMatch): Score {
        $user = User::factory()->create([
            'name' => $name,
            'province_id' => $this->province->id,
        ]);

        Membership::create([
            'user_id' => $user->id,
            'saprf_number' => 'SAPRF-PRS-'.$user->id,
            'membership_type' => $membershipType,
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => $this->matchDate->copy()->subMonth(),
            'expiry_date' => $this->matchDate->copy()->addYear(),
        ]);

        return Score::create([
            'match_id' => $nationalMatch->id,
            'user_id' => $user->id,
            'shooter_name' => $name,
            'division_id' => $this->open->id,
            'raw_score' => $rawScore,
            'status' => 'valid',
            'is_member' => true,
            'counts_for_season' => true,
            'match_date' => $this->matchDate,
        ]);
    };

    $full = $buildPrsScore('PRS Full Member', 'full', 90);
    $associate = $buildPrsScore('PRS Associate', 'associate', 95);

    app(StandingsCalculationService::class)->recalculateForMatch($nationalMatch);

    $userIds = Standing::where('series', 'PRS')
        ->where('season', '2026')
        ->pluck('user_id')
        ->unique()
        ->all();

    expect($userIds)->toContain($full->user_id)
        ->and($userIds)->not->toContain($associate->user_id);
});
