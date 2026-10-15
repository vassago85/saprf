<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\QualificationService;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use App\Support\DocumentSearch;
use Illuminate\Support\Carbon;

/**
 * 2027 national series final ranking (docs/rules/national-series-2027.md):
 *   best 3 nationals (max 2 from the home province) + 2-day championships
 *   (each day weighted separately, max 200) = max 500.
 */

function ns27Match(string $name, string $level, string $date, ?Province $province = null, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => $name,
        'match_type' => 'PRS',
        'series_level' => $level,
        'series' => 'PRS',
        'season' => '2027',
        'match_date' => $date,
        'province_id' => $province?->id,
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500,
        'published' => true,
    ], $overrides));
}

function ns27Score(MatchEvent $match, User $user, float $day1, ?float $day2 = null, ?int $divisionId = null, array $extra = []): Score
{
    return Score::create(array_merge([
        'match_id' => $match->id,
        'shooter_name' => $user->name,
        'user_id' => $user->id,
        'day1_raw_score' => $day1,
        'day2_raw_score' => $day2,
        'division_id' => $divisionId,
        'status' => 'valid',
        'counts_for_season' => true,
        'match_date' => $match->match_date->toDateString(),
    ], $extra));
}

function ns27Recalc(): void
{
    $service = app(StandingsCalculationService::class);
    foreach (MatchEvent::all() as $match) {
        $service->calculateMatchRankings($match);
    }
    $service->recalculateSeasonStandings('PRS', '2027');
}

function ns27Overall(User $user): Standing
{
    return Standing::where('user_id', $user->id)
        ->where('series', 'PRS')->where('season', '2027')
        ->whereNull('province_id')->whereNull('division_id')
        ->firstOrFail();
}

/** A national where $shooter scores exactly $pct (a fresh rival sets the 100 baseline). */
function ns27National(string $name, string $date, Province $province, User $shooter, float $pct, ?int $divisionId = null): MatchEvent
{
    $match = ns27Match($name, 'national', $date, $province);
    ns27Score($match, User::factory()->create(), 100.0, null, $divisionId);
    ns27Score($match, $shooter, $pct, null, $divisionId);

    return $match;
}

beforeEach(function () {
    $this->gp = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->wc = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $this->open = Division::create(['slug' => 'open', 'name' => 'Open']);
    $this->factory = Division::create(['slug' => 'factory', 'name' => 'Factory']);

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2027',
        'scoring_mode' => QualificationRule::MODE_NATIONAL_SERIES_2027,
        'min_out_of_province_matches' => 1,
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'created_by' => User::factory()->create()->id,
    ]);
});

it('counts the best 3 nationals with no more than 2 from the home province', function () {
    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    ns27National('GP1', '2027-02-01', $this->gp, $shooter, 100);
    ns27National('GP2', '2027-03-01', $this->gp, $shooter, 95);
    ns27National('GP3', '2027-04-01', $this->gp, $shooter, 90);
    ns27National('WC1', '2027-05-01', $this->wc, $shooter, 80);
    ns27National('WC2', '2027-06-01', $this->wc, $shooter, 70);

    ns27Recalc();
    $pb = ns27Overall($shooter)->pool_breakdown;

    // 100 + 95 (home cap reached, GP3's 90 skipped) + 80 (best away).
    expect((float) ns27Overall($shooter)->points)->toBe(275.0)
        ->and(collect($pb['regular'])->pluck('pct')->all())->toEqual([100.0, 95.0, 80.0])
        ->and($pb['home_counted'])->toBe(2)
        ->and($pb['out_of_province_counted'])->toBe(1)
        ->and($pb['selection_eligible'])->toBeTrue()
        ->and($pb['max'])->toBe(500);
});

it('lets all 3 counting nationals come from outside the home province', function () {
    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    ns27National('WC1', '2027-02-01', $this->wc, $shooter, 90);
    ns27National('WC2', '2027-03-01', $this->wc, $shooter, 85);
    ns27National('WC3', '2027-04-01', $this->wc, $shooter, 80);

    ns27Recalc();

    expect((float) ns27Overall($shooter)->points)->toBe(255.0)
        ->and(ns27Overall($shooter)->pool_breakdown['selection_eligible'])->toBeTrue();
});

it('keeps an all-home shooter on the log but marks them not eligible for selection', function () {
    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    ns27National('GP1', '2027-02-01', $this->gp, $shooter, 100);
    ns27National('GP2', '2027-03-01', $this->gp, $shooter, 90);
    ns27National('GP3', '2027-04-01', $this->gp, $shooter, 80);

    ns27Recalc();
    $standing = ns27Overall($shooter);

    expect((float) $standing->points)->toBe(190.0)
        ->and($standing->pool_breakdown['regular_counted'])->toBe(2)
        ->and($standing->pool_breakdown['selection_eligible'])->toBeFalse()
        ->and($standing->pool_breakdown['selection_note'])->toContain('home province');
});

it('weights each championship day against the highest raw score of that day', function () {
    $a = User::factory()->create(['province_id' => $this->gp->id]);
    $b = User::factory()->create(['province_id' => $this->gp->id]);

    $champs = ns27Match('SA Champs', 'final', '2027-11-01', $this->wc);
    // Both total 80 raw, but day maxima are 50 (day 1) and 40 (day 2).
    ns27Score($champs, $a, 50.0, 30.0);
    ns27Score($champs, $b, 40.0, 40.0);

    ns27Recalc();

    $aChamps = ns27Overall($a)->pool_breakdown['champs'];
    $bChamps = ns27Overall($b)->pool_breakdown['champs'];

    expect($aChamps['day1_pct'])->toEqual(100.0)
        ->and($aChamps['day2_pct'])->toEqual(75.0)
        ->and((float) ns27Overall($a)->points)->toBe(175.0)
        ->and($bChamps['day1_pct'])->toEqual(80.0)
        ->and($bChamps['day2_pct'])->toEqual(100.0)
        ->and((float) ns27Overall($b)->points)->toBe(180.0)
        ->and(ns27Overall($b)->rank)->toBe(1)
        ->and(ns27Overall($b)->pool_breakdown['champs_max'])->toBe(200);
});

it('falls back to the match percentage for both days when no day split was captured', function () {
    $shooter = User::factory()->create(['province_id' => $this->gp->id]);

    $champs = ns27Match('SA Champs', 'final', '2027-11-01', $this->wc);
    ns27Score($champs, User::factory()->create(), 100.0);
    ns27Score($champs, $shooter, 80.0);

    ns27Recalc();
    $pb = ns27Overall($shooter)->pool_breakdown;

    expect($pb['champs']['days_split'])->toBeFalse()
        ->and($pb['champs_pct'])->toEqual(160.0);
});

it('breaks ties on points with the higher championship score', function () {
    $s1 = User::factory()->create(['province_id' => $this->gp->id]);
    $s2 = User::factory()->create(['province_id' => $this->gp->id]);

    $national = ns27Match('WC1', 'national', '2027-03-01', $this->wc);
    ns27Score($national, $s1, 100.0);
    ns27Score($national, $s2, 50.0);

    $champs = ns27Match('SA Champs', 'final', '2027-11-01', $this->wc);
    ns27Score($champs, User::factory()->create(), 100.0, 100.0);
    ns27Score($champs, $s1, 50.0, 50.0);  // 100 champs → 200 total
    ns27Score($champs, $s2, 75.0, 75.0);  // 150 champs → 200 total

    ns27Recalc();

    expect((float) ns27Overall($s1)->points)->toBe(200.0)
        ->and((float) ns27Overall($s2)->points)->toBe(200.0)
        ->and(ns27Overall($s2)->rank)->toBeLessThan(ns27Overall($s1)->rank);
});

it('breaks a full tie with the tiebreak stage: more impacts, then the faster time', function () {
    $slow = User::factory()->create(['province_id' => $this->gp->id]);
    $fast = User::factory()->create(['province_id' => $this->gp->id]);
    $more = User::factory()->create(['province_id' => $this->gp->id]);
    $none = User::factory()->create(['province_id' => $this->gp->id]);
    $none2 = User::factory()->create(['province_id' => $this->gp->id]);

    $champs = ns27Match('SA Champs', 'final', '2027-11-01', $this->wc);
    ns27Score($champs, User::factory()->create(), 100.0, 100.0);
    ns27Score($champs, $slow, 60.0, 60.0, null, ['tiebreak_impacts' => 5, 'tiebreak_time_seconds' => 41.2]);
    ns27Score($champs, $fast, 60.0, 60.0, null, ['tiebreak_impacts' => 5, 'tiebreak_time_seconds' => 38.9]);
    ns27Score($champs, $more, 60.0, 60.0, null, ['tiebreak_impacts' => 6, 'tiebreak_time_seconds' => 59.0]);
    ns27Score($champs, $none, 50.0, 50.0);
    ns27Score($champs, $none2, 50.0, 50.0);

    ns27Recalc();

    expect(ns27Overall($more)->rank)->toBe(2)
        ->and(ns27Overall($fast)->rank)->toBe(3)
        ->and(ns27Overall($slow)->rank)->toBe(4)
        // Nothing separates these two, so they share a rank.
        ->and(ns27Overall($none)->rank)->toBe(5)
        ->and(ns27Overall($none2)->rank)->toBe(5);
});

it('carries Factory results into Open for a shooter who moved up, but not the other way', function () {
    $movedUp = User::factory()->create(['province_id' => $this->gp->id]);
    $movedDown = User::factory()->create(['province_id' => $this->gp->id]);

    $n1 = ns27Match('WC1', 'national', '2027-03-01', $this->wc);
    ns27Score($n1, User::factory()->create(), 100.0, null, $this->open->id);
    ns27Score($n1, $movedUp, 90.0, null, $this->factory->id);
    ns27Score($n1, $movedDown, 90.0, null, $this->open->id);

    $n2 = ns27Match('WC2', 'national', '2027-04-01', $this->wc);
    ns27Score($n2, User::factory()->create(), 100.0, null, $this->open->id);
    ns27Score($n2, $movedUp, 80.0, null, $this->open->id);
    ns27Score($n2, $movedDown, 80.0, null, $this->factory->id);

    ns27Recalc();

    $points = fn (User $user, Division $division) => (float) Standing::where('user_id', $user->id)
        ->where('series', 'PRS')->where('season', '2027')
        ->whereNull('province_id')->where('division_id', $division->id)
        ->value('points');

    expect($points($movedUp, $this->open))->toBe(170.0)
        ->and($points($movedUp, $this->factory))->toBe(90.0)
        ->and($points($movedDown, $this->factory))->toBe(80.0);
});

it('renders the 500-point breakdown, championship days and selection note on the profile and log', function () {
    $shooter = User::factory()->create(['name' => 'Home Only', 'province_id' => $this->gp->id]);

    ns27National('GP1', '2027-02-01', $this->gp, $shooter, 100, $this->open->id);
    ns27National('GP2', '2027-03-01', $this->gp, $shooter, 90, $this->open->id);
    $champs = ns27Match('SA Champs', 'final', '2027-11-01', $this->wc);
    ns27Score($champs, $shooter, 50.0, 40.0, $this->open->id);

    ns27Recalc();

    $this->followingRedirects()->get('/standings/2027/shooter/'.$shooter->id)
        ->assertOk()
        ->assertSee('Best 3 nationals + 2-day championships (out of 500)')
        ->assertSee('Max 2 from your home province')
        ->assertSee('Day 1 100.00%')
        ->assertSee('Not yet eligible for selection');

    $this->get('/standings?series=PRS&season=2027')
        ->assertOk()
        ->assertSee('Not selection eligible');
});

it('requires 1 national and 1 provincial before entering the championships', function () {
    seedRoles();
    $champs = ns27Match('SA Champs', 'final', Carbon::today()->addMonth()->toDateString(), $this->wc, [
        'status' => 'open',
        'match_director' => 'Jane Director',
        'active_member_fee' => 0,
    ]);
    $champs->divisions()->attach($this->open->id);

    $shooter = User::factory()->create(['email_verified_at' => now(), 'province_id' => $this->gp->id]);
    $shooter->assignRole('member');

    ns27National('WC1', '2027-03-01', $this->wc, $shooter, 90);

    $this->actingAs($shooter)
        ->post(route('events.register.store', $champs), ['division_id' => $this->open->id])
        ->assertSessionHas('error');
    expect(MatchRegistration::where('user_id', $shooter->id)->count())->toBe(0);

    $provincial = ns27Match('GP Prov', 'provincial', '2027-04-01', $this->gp);
    ns27Score($provincial, $shooter, 70.0, null, null, ['status' => 'non_member']);

    expect(app(QualificationService::class)->championshipEntryStatus($shooter, $champs)['eligible'])->toBeTrue();

    $this->actingAs($shooter)
        ->post(route('events.register.store', $champs), ['division_id' => $this->open->id]);
    expect(MatchRegistration::where('user_id', $shooter->id)->count())->toBe(1);
});

it('does not gate championships under any other scoring mode', function () {
    QualificationRule::where('season', '2027')->update(['scoring_mode' => 'best_n_plus_champs']);
    $champs = ns27Match('SA Champs', 'final', '2027-11-01', $this->wc);

    expect(app(QualificationService::class)->championshipEntryStatus(User::factory()->create(), $champs))
        ->toMatchArray(['applies' => false, 'eligible' => true]);
});

it('keeps the rules document parked until it is published', function () {
    seedRoles();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->get(route('rules.national-series-2027'))->assertNotFound();
    $this->get(route('documents.index'))->assertDontSee(route('rules.national-series-2027'), false);
    expect(collect((new DocumentSearch)->search('tiebreak stage'))->pluck('doc_url'))
        ->not->toContain(route('rules.national-series-2027'));

    $this->actingAs($admin)
        ->get(route('rules.national-series-2027'))
        ->assertOk()
        ->assertSee('Draft · not yet published')
        ->assertSee('No more than 2 may come from their home province', false);
});

it('shows the rules document publicly once published', function () {
    app(SettingsService::class)->set(SettingsService::NATIONAL_SERIES_2027_PUBLISHED, '1');

    $this->get(route('rules.national-series-2027'))
        ->assertOk()
        ->assertSee('highest raw score of the day) × 100', false)
        ->assertDontSee('not yet published');
    $this->get(route('documents.index'))->assertSee('href="'.route('rules.national-series-2027').'"', false);
    expect(collect((new DocumentSearch)->search('tiebreak stage'))->pluck('doc_url'))
        ->toContain(route('rules.national-series-2027'));
});

it('activates the 2027 rules for both disciplines from the command', function () {
    QualificationRule::where('season', '2027')->delete();

    $this->artisan('saprf:national-series-2027', ['action' => 'activate', '--force' => true])->assertSuccessful();

    foreach (['PRS', 'PR22'] as $series) {
        $rule = QualificationRule::where('series', $series)->where('season', '2027')->firstOrFail();
        expect($rule->isNationalSeries2027())->toBeTrue()
            ->and($rule->best_of_count)->toEqual(3)
            ->and($rule->min_out_of_province_matches)->toEqual(1);
    }

    $this->artisan('saprf:national-series-2027', ['action' => 'publish'])->assertSuccessful();
    expect(app(SettingsService::class)->nationalSeries2027Published())->toBeTrue();
});
