<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Services\PostMatchReport;
use App\Services\StandingsCalculationService;

beforeEach(function () {
    seedRoles();

    $this->gp = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->wc = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $this->mp = Province::create(['name' => 'Mpumalanga', 'abbreviation' => 'MP']);

    $this->open = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);
    $this->ladies = Division::create(['slug' => 'ladies', 'name' => 'Ladies', 'display_order' => 2]);
    $this->factory = Division::create(['slug' => 'factory', 'name' => 'Factory', 'display_order' => 3]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'scoring_mode' => QualificationRule::MODE_NATIONAL_SERIES_2027,
        'min_out_of_province_matches' => 1,
        'best_of_count' => 3,
        'total_qualifying_matches' => 4,
        'created_by' => $this->admin->id,
    ]);
});

function pmrShooter(string $name, Province $province): User
{
    $user = User::factory()->create(['name' => $name, 'province_id' => $province->id]);
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-PMR-'.$user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    return $user;
}

function pmrMatch(string $name, string $level, string $date, ?Province $province = null, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => $name,
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => $level,
        'season' => '2026',
        'match_date' => $date,
        'province_id' => $province?->id,
        'status' => 'completed',
        'created_by' => test()->admin->id,
        'published' => true,
    ], $overrides));
}

function pmrScore(MatchEvent $match, User $user, float $day1, ?float $day2 = null, ?int $divisionId = null): Score
{
    return Score::create([
        'match_id' => $match->id,
        'shooter_name' => $user->name,
        'user_id' => $user->id,
        'division_id' => $divisionId,
        'day1_raw_score' => $day1,
        'day2_raw_score' => $day2,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $match->match_date->toDateString(),
    ]);
}

function pmrRebuild(): void
{
    $service = app(StandingsCalculationService::class);
    foreach (MatchEvent::all() as $match) {
        $service->calculateMatchRankings($match);
    }
    $service->recalculateSeasonStandings('PRS', '2026');
}

it('refuses to build a report for a non-championship match', function () {
    $national = pmrMatch('GP National', 'national', '2026-07-11', test()->gp);

    expect(fn () => app(PostMatchReport::class)->build($national))
        ->toThrow(InvalidArgumentException::class);
});

it('redirects a non-championship match URL back to the overview', function () {
    $national = pmrMatch('GP National', 'national', '2026-07-11', test()->gp);

    $this->actingAs($this->admin)
        ->get(route('matches.post-match-report', $national))
        ->assertRedirect(route('matches.overview', $national))
        ->assertSessionHas('error');
});

it('lists the top 5 per division in year-points order, including only shooters who shot the championship', function () {
    $champs = pmrMatch('Centrefire GP 2-Day Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $national = pmrMatch('Centrefire WC National', 'national', '2026-08-08', $this->wc);
    $other = pmrMatch('Centrefire MP National', 'national', '2026-07-11', $this->mp);

    // One baseline per match to set 100% ceiling. Six real Open shooters
    // (Open 01 … Open 06) — only the top 5 should land in the report.
    $baselineChamps = User::factory()->create(['name' => 'Baseline Champ']);
    $baselineNat = User::factory()->create(['name' => 'Baseline Nat']);
    $baselineOther = User::factory()->create(['name' => 'Baseline Other']);
    pmrScore($champs, $baselineChamps, 100.0, null, test()->ladies->id);
    pmrScore($national, $baselineNat, 100.0, null, test()->factory->id);
    pmrScore($other, $baselineOther, 100.0, null, test()->factory->id);

    $openShooters = collect(range(1, 6))->map(function (int $i) use ($champs, $national, $other) {
        $name = sprintf('Open %02d', $i);
        $user = pmrShooter($name, test()->gp);

        // Shooter 1 scores the best, shooter 6 the worst — and the per-shot
        // delta stays big enough that champs (×2) dominates ties on the
        // year total.
        $champRaw = 100 - ($i - 1) * 5;
        $nationalRaw = 100 - ($i - 1) * 3;
        $otherRaw = 100 - ($i - 1) * 4;

        pmrScore($champs, $user, $champRaw, null, test()->open->id);
        pmrScore($national, $user, $nationalRaw, null, test()->open->id);
        pmrScore($other, $user, $otherRaw, null, test()->open->id);

        return $user;
    });

    // One Ladies shooter who did shoot the championship.
    $ladies = pmrShooter('Ladies Winner', test()->gp);
    pmrScore($champs, User::factory()->create(['name' => 'Ladies Top Raw']), 100.0, null, test()->ladies->id);
    pmrScore($champs, $ladies, 80.0, null, test()->ladies->id);

    // A Ladies shooter who did NOT shoot the championship but has a big
    // year total — must NOT appear in the report.
    $skipper = pmrShooter('Skipped Champs', test()->gp);
    pmrScore($national, $skipper, 100.0, null, test()->ladies->id);

    pmrRebuild();

    $report = app(PostMatchReport::class)->build($champs->fresh());

    expect($report['series'])->toBe('PRS')
        ->and($report['season'])->toBe('2026')
        ->and($report['has_standings'])->toBeTrue();

    $divisions = collect($report['divisions']);
    $openBlock = $divisions->firstWhere('division.id', test()->open->id);
    $ladiesBlock = $divisions->firstWhere('division.id', test()->ladies->id);

    expect($openBlock)->not->toBeNull()
        ->and(count($openBlock['top5']))->toBe(5)
        ->and($openBlock['shooters_total'])->toBe(6)
        ->and(collect($openBlock['top5'])->pluck('user.name')->all())
            ->toBe(['Open 01', 'Open 02', 'Open 03', 'Open 04', 'Open 05'])
        ->and($openBlock['winner']['user']->name)->toBe('Open 01')
        ->and($openBlock['winner']['today_points'])->toBeGreaterThan(0.0)
        ->and($openBlock['year_max'])->toBe(500);

    expect($ladiesBlock)->not->toBeNull()
        ->and(collect($ladiesBlock['top5'])->pluck('user.name')->all())->toBe(['Ladies Winner'])
        ->and(collect($ladiesBlock['top5'])->pluck('user.name')->doesntContain('Skipped Champs'))->toBeTrue();
});

it('breaks down the counted nationals and today championship contribution per shooter', function () {
    $champs = pmrMatch('Centrefire GP 2-Day Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $n1 = pmrMatch('Centrefire WC National', 'national', '2026-05-16', $this->wc);
    $n2 = pmrMatch('Centrefire LP National', 'national', '2026-08-08', $this->mp);

    $hero = pmrShooter('Year Winner', $this->gp);

    pmrScore($champs, User::factory()->create(['name' => 'Champ top']), 100.0, null, test()->open->id);
    pmrScore($champs, $hero, 100.0, null, test()->open->id);

    pmrScore($n1, User::factory()->create(['name' => 'N1 top']), 100.0, null, test()->open->id);
    pmrScore($n1, $hero, 85.0, null, test()->open->id);

    pmrScore($n2, User::factory()->create(['name' => 'N2 top']), 100.0, null, test()->open->id);
    pmrScore($n2, $hero, 90.0, null, test()->open->id);

    pmrRebuild();

    $report = app(PostMatchReport::class)->build($champs->fresh());
    $block = collect($report['divisions'])->firstWhere('division.id', test()->open->id);
    $winnerRow = collect($block['top5'])->firstWhere('user.id', $hero->id);

    expect($winnerRow)->not->toBeNull()
        ->and($winnerRow['today_points'])->toBe(200.0)
        ->and($winnerRow['today_match_pct'])->toBe(100.0)
        ->and($winnerRow['year_points'])->toBe(375.0)
        ->and(collect($winnerRow['counted_regulars'])->pluck('match_name')->all())
            ->toEqual(['Centrefire LP National', 'Centrefire WC National'])
        ->and(collect($winnerRow['counted_regulars'])->pluck('pct')->all())
            ->toEqual([90.0, 85.0]);
});

it('renders the HTML report with every division top-5 shooter', function () {
    $champs = pmrMatch('Centrefire SA Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $national = pmrMatch('Centrefire WC National', 'national', '2026-08-08', $this->wc);

    $openWinner = pmrShooter('Open Year Winner', $this->gp);
    $ladiesWinner = pmrShooter('Ladies Year Winner', $this->gp);

    pmrScore($champs, User::factory()->create(['name' => 'Open baseline']), 100.0, null, test()->open->id);
    pmrScore($champs, $openWinner, 90.0, null, test()->open->id);
    pmrScore($champs, User::factory()->create(['name' => 'Ladies baseline']), 100.0, null, test()->ladies->id);
    pmrScore($champs, $ladiesWinner, 70.0, null, test()->ladies->id);

    pmrScore($national, User::factory()->create(['name' => 'Nat baseline']), 100.0, null, test()->open->id);
    pmrScore($national, $openWinner, 88.0, null, test()->open->id);

    pmrRebuild();

    $this->actingAs($this->admin)
        ->get(route('matches.post-match-report', $champs))
        ->assertOk()
        ->assertSee('Championship report · PRS 2026')
        ->assertSee('Centrefire SA Championship')
        ->assertSee('Open Year Winner')
        ->assertSee('Ladies Year Winner')
        ->assertSee('Centrefire WC National')
        ->assertSee('Division Winner');
});

it('redirects the annual standings report to the championship for that series and season', function () {
    $champs = pmrMatch('Centrefire SA Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $member = pmrShooter('Open Winner', $this->gp);
    pmrScore($champs, User::factory()->create(['name' => 'Baseline']), 100.0, null, test()->open->id);
    pmrScore($champs, $member, 85.0, null, test()->open->id);
    pmrRebuild();

    $this->actingAs($this->admin)
        ->get(route('reports.annual-standings', ['series' => 'PRS', 'season' => '2026']))
        ->assertRedirect(route('matches.post-match-report', $champs));
});

it('shows the missing champs page when no final has been staged for the season', function () {
    pmrMatch('GP National', 'national', '2026-07-11', $this->gp);

    $this->actingAs($this->admin)
        ->get(route('reports.annual-standings', ['series' => 'PRS', 'season' => '2026']))
        ->assertOk()
        ->assertSee('No PRS championship is set up for 2026');
});

it('picks the latest final when more than one championship exists in a season', function () {
    pmrMatch('Early final', 'final', '2026-03-22', $this->gp);
    $later = pmrMatch('SA Championship late', 'final', '2026-11-22', $this->gp);

    $this->actingAs($this->admin)
        ->get(route('reports.annual-standings', ['series' => 'PRS', 'season' => '2026']))
        ->assertRedirect(route('matches.post-match-report', $later));
});

it('refuses the report page to a member who cannot view the match', function () {
    $champs = pmrMatch('Centrefire SA Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('matches.post-match-report', $champs))
        ->assertForbidden();
});

it('downloads the championship report as a PDF with the expected filename', function () {
    $champs = pmrMatch('Centrefire SA Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $openWinner = pmrShooter('Open Year Winner', $this->gp);
    pmrScore($champs, User::factory()->create(['name' => 'Baseline']), 100.0, null, test()->open->id);
    pmrScore($champs, $openWinner, 90.0, null, test()->open->id);

    pmrRebuild();

    $response = $this->actingAs($this->admin)
        ->get(route('matches.post-match-report.pdf', $champs));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('SAPRF-prs-2026-Championship-Report-2026-11-22.pdf');
    // DomPDF streams raw PDF bytes; the header is a cheap sanity check
    // that we got a real PDF out and not an HTML error page.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('redirects the PDF route back to the overview when the match is not a championship', function () {
    $national = pmrMatch('GP National', 'national', '2026-07-11', test()->gp);

    $this->actingAs($this->admin)
        ->get(route('matches.post-match-report.pdf', $national))
        ->assertRedirect(route('matches.overview', $national))
        ->assertSessionHas('error');
});

it('refuses the PDF route to a member who cannot view the match', function () {
    $champs = pmrMatch('Centrefire SA Championship', 'final', '2026-11-22', $this->gp, [
        'match_end_date' => '2026-11-22',
    ]);
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('matches.post-match-report.pdf', $champs))
        ->assertForbidden();
});
