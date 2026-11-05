<?php

use App\Models\Division;
use App\Models\FederationColourOverride;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\FederationColoursReport;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->wc = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    $this->open = Division::create([
        'slug' => 'open-colours',
        'name' => 'Open',
        'display_order' => 1,
        'is_active' => true,
    ]);
    $this->factory = Division::create([
        'slug' => 'factory-colours',
        'name' => 'Factory',
        'display_order' => 2,
        'is_active' => true,
    ]);
});

function coloursShooter(string $name, Province $province, string $number): User
{
    $user = User::factory()->create(['name' => $name, 'province_id' => $province->id]);
    giveActiveMembership($user, $number);

    return $user;
}

function coloursStanding(User $user, string $series, Province $province, ?Division $division, float $points, ?int $counted = 3): void
{
    Standing::create([
        'user_id' => $user->id,
        'series' => $series,
        'season' => '2026',
        'province_id' => $province->id,
        'division_id' => $division?->id,
        'points' => $points,
        'rank' => 1,
        'pool_breakdown' => $counted === null ? null : ['scores_counted' => $counted],
    ]);
}

it('keeps open and factory separate and grades against the division province leader', function (string $series) {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    $atNinetyFive = coloursShooter('Ninety Five', $this->gp, 'N5-1');
    $silver = coloursShooter('Silver Shooter', $this->gp, 'SS-1');
    $atEightyFive = coloursShooter('Eighty Five', $this->gp, 'E5-1');
    $bronze = coloursShooter('Bronze Shooter', $this->gp, 'BS-1');
    $below = coloursShooter('Below Shooter', $this->gp, 'BL-1');
    $factoryRival = coloursShooter('Factory Rival', $this->gp, 'FR-1');
    $wcLeader = coloursShooter('Cape Leader', $this->wc, 'CL-1');

    coloursStanding($kevin, $series, $this->gp, $this->open, 200);
    coloursStanding($atNinetyFive, $series, $this->gp, $this->open, 190);
    coloursStanding($silver, $series, $this->gp, $this->open, 189);
    coloursStanding($atEightyFive, $series, $this->gp, $this->open, 170);
    coloursStanding($bronze, $series, $this->gp, $this->open, 160);
    coloursStanding($below, $series, $this->gp, $this->open, 159);
    coloursStanding($kevin, $series, $this->gp, $this->factory, 150, 2);
    coloursStanding($factoryRival, $series, $this->gp, $this->factory, 100);
    coloursStanding($wcLeader, $series, $this->wc, $this->open, 100);
    coloursStanding($kevin, $series, $this->gp, null, 9999);

    $other = $series === 'PRS' ? 'PR22' : 'PRS';
    coloursStanding($kevin, $other, $this->gp, $this->open, 50);

    $report = app(FederationColoursReport::class)->build($series, '2026');

    expect($report['divisions'])->toHaveCount(2)
        ->and($report['divisions'][0]['name'])->toBe('Open')
        ->and($report['divisions'][1]['name'])->toBe('Factory');

    $gpOpen = $report['divisions'][0]['provinces'][0];
    expect($gpOpen['name'])->toBe('Gauteng')
        ->and($gpOpen['leader_name'])->toBe('Kevin Goncalves')
        ->and($gpOpen['leader_points'])->toBe(200.0);

    $byName = collect($gpOpen['rows'])->keyBy('name');
    expect($byName['Kevin Goncalves']['percentage'])->toBe(100.0)
        ->and($byName['Kevin Goncalves']['grade'])->toBe('Gold')
        ->and($byName['Kevin Goncalves']['points'])->toBe(200.0)
        ->and($byName['Kevin Goncalves']['matches_counted'])->toBe(3)
        ->and($byName['Kevin Goncalves']['saprf_number'])->toBe('KG-1');
    expect($byName['Ninety Five']['percentage'])->toBe(95.0)
        ->and($byName['Ninety Five']['grade'])->toBe('Gold');
    expect($byName['Silver Shooter']['percentage'])->toBe(94.5)
        ->and($byName['Silver Shooter']['grade'])->toBe('Silver');
    expect($byName['Eighty Five']['percentage'])->toBe(85.0)
        ->and($byName['Eighty Five']['grade'])->toBe('Silver');
    expect($byName['Bronze Shooter']['percentage'])->toBe(80.0)
        ->and($byName['Bronze Shooter']['grade'])->toBe('Bronze');
    expect($byName['Below Shooter']['percentage'])->toBe(79.5)
        ->and($byName['Below Shooter']['grade'])->toBeNull();
    expect($byName)->not->toHaveKey('Factory Rival');

    $gpFactory = $report['divisions'][1]['provinces'][0];
    $factoryByName = collect($gpFactory['rows'])->keyBy('name');
    expect($factoryByName['Kevin Goncalves']['points'])->toBe(150.0)
        ->and($factoryByName['Kevin Goncalves']['percentage'])->toBe(100.0)
        ->and($factoryByName['Kevin Goncalves']['grade'])->toBe('Gold')
        ->and($factoryByName['Kevin Goncalves']['matches_counted'])->toBe(2);
    expect(number_format($factoryByName['Factory Rival']['percentage'], 2, '.', ''))->toBe('66.67')
        ->and($factoryByName['Factory Rival']['grade'])->toBeNull();

    $wcOpen = collect($report['divisions'][0]['provinces'])->firstWhere('name', 'Western Cape');
    expect($wcOpen['rows'][0]['name'])->toBe('Cape Leader')
        ->and($wcOpen['rows'][0]['percentage'])->toBe(100.0);

    $allPoints = collect($report['divisions'])
        ->flatMap(fn (array $division) => collect($division['provinces'])->flatMap(
            fn (array $province) => collect($province['rows'])->pluck('points')
        ));
    expect($allPoints->contains(fn ($points) => (float) $points === 9999.0))->toBeFalse()
        ->and($allPoints->contains(fn ($points) => (float) $points === 50.0))->toBeFalse();
})->with(['PRS', 'PR22']);

it('gives tied leaders the same rank and 100 percent', function () {
    $ada = coloursShooter('Ada Tied', $this->gp, 'AD-1');
    $bea = coloursShooter('Bea Tied', $this->gp, 'BE-1');
    coloursStanding($ada, 'PR22', $this->gp, $this->open, 100);
    coloursStanding($bea, 'PR22', $this->gp, $this->open, 100);

    $group = app(FederationColoursReport::class)->build('PR22', '2026')['divisions'][0]['provinces'][0];

    expect($group['leader_name'])->toBe('Ada Tied, Bea Tied')
        ->and($group['rows'][0]['rank'])->toBe(1)
        ->and($group['rows'][1]['rank'])->toBe(1)
        ->and($group['rows'][0]['percentage'])->toBe(100.0)
        ->and($group['rows'][1]['percentage'])->toBe(100.0)
        ->and($group['rows'][0]['grade'])->toBe('Gold')
        ->and($group['rows'][1]['grade'])->toBe('Gold');
});

it('shows the report and downloads a csv and a pdf for the selected series', function () {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    coloursStanding($kevin, 'PR22', $this->gp, $this->open, 200);
    coloursStanding($kevin, 'PR22', $this->gp, $this->factory, 150);

    $this->actingAs($this->admin)
        ->get(route('reports.federation-colours', ['series' => 'PR22', 'season' => '2026']))
        ->assertOk()
        ->assertSee('Kevin Goncalves')
        ->assertSee('Open')
        ->assertSee('Factory')
        ->assertSee('Gold')
        ->assertSee('KG-1');

    $csv = $this->actingAs($this->admin)
        ->get(route('reports.federation-colours.csv', ['series' => 'PR22', 'season' => '2026']));
    $csv->assertOk();
    $body = $csv->streamedContent();
    expect($body)->toContain('Kevin Goncalves')
        ->and($body)->toContain('Open')
        ->and($body)->toContain('Factory')
        ->and($body)->toContain('PR22')
        ->and($body)->toContain('KG-1')
        ->and(substr_count($body, 'Kevin Goncalves'))->toBe(2);

    $pdf = $this->actingAs($this->admin)
        ->get(route('reports.federation-colours.pdf', ['series' => 'PR22', 'season' => '2026']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($pdf->headers->get('content-disposition'))->toContain('SAPRF-Federation-Colours-PR22-2026.pdf');
});

function coloursChamps(User $user, string $series, Division $division): void
{
    $match = MatchEvent::create([
        'name' => $series.' SA Champs '.$user->id.' '.$division->id,
        'match_type' => $series,
        'series' => $series,
        'series_level' => 'final',
        'season' => '2026',
        'province_id' => $user->province_id,
        'match_date' => '2026-09-01',
        'status' => 'completed',
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => $user->id,
    ]);

    Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'division_id' => $division->id,
        'raw_score' => 80,
        'normalized_score' => 90,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $match->match_date,
    ]);
}

it('withholds a grade without an sa championships score in that division', function () {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    coloursStanding($kevin, 'PR22', $this->gp, $this->open, 200);
    coloursStanding($kevin, 'PR22', $this->gp, $this->factory, 150);
    coloursChamps($kevin, 'PR22', $this->open);

    $report = app(FederationColoursReport::class)->build('PR22', '2026', ['require_champs' => true]);
    $open = collect($report['divisions'][0]['provinces'][0]['rows'])->firstWhere('name', 'Kevin Goncalves');
    $factory = collect($report['divisions'][1]['provinces'][0]['rows'])->firstWhere('name', 'Kevin Goncalves');

    expect($open['shot_champs'])->toBeTrue()
        ->and($open['grade'])->toBe('Gold')
        ->and($factory['shot_champs'])->toBeFalse()
        ->and($factory['grade'])->toBeNull()
        ->and($factory['percentage'])->toBe(100.0);
});

it('awards a grade without sa championships when a reason is recorded', function () {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    coloursStanding($kevin, 'PR22', $this->gp, $this->factory, 150);

    $this->actingAs($this->admin)
        ->post(route('reports.federation-colours.overrides.store'), [
            'series' => 'PR22',
            'season' => '2026',
            'user_id' => $kevin->id,
            'division_id' => $this->factory->id,
            'reason' => 'Medical withdrawal after the entry closed',
            'require_champs' => '1',
        ])
        ->assertRedirect(route('reports.federation-colours', [
            'series' => 'PR22',
            'season' => '2026',
            'require_champs' => '1',
        ]));

    $row = app(FederationColoursReport::class)->build('PR22', '2026', ['require_champs' => true])['divisions'][0]['provinces'][0]['rows'][0];

    expect($row['grade'])->toBe('Gold')
        ->and($row['shot_champs'])->toBeFalse()
        ->and($row['override_reason'])->toBe('Medical withdrawal after the entry closed');

    $this->actingAs($this->admin)
        ->delete(route('reports.federation-colours.overrides.destroy', $row['override_id']), [
            'series' => 'PR22',
            'season' => '2026',
            'require_champs' => '1',
        ])
        ->assertRedirect();

    expect(FederationColourOverride::query()->count())->toBe(0);

    $cleared = app(FederationColoursReport::class)->build('PR22', '2026', ['require_champs' => true])['divisions'][0]['provinces'][0]['rows'][0];
    expect($cleared['grade'])->toBeNull();
});

it('rejects an override without a real reason', function () {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    coloursStanding($kevin, 'PR22', $this->gp, $this->open, 200);

    $this->actingAs($this->admin)
        ->from(route('reports.federation-colours', ['series' => 'PR22', 'season' => '2026', 'require_champs' => '1']))
        ->post(route('reports.federation-colours.overrides.store'), [
            'series' => 'PR22',
            'season' => '2026',
            'user_id' => $kevin->id,
            'division_id' => $this->open->id,
            'reason' => 'short',
            'require_champs' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('reason');

    expect(FederationColourOverride::query()->count())->toBe(0);
});

it('uses the cutoffs chosen for this report', function () {
    $leader = coloursShooter('Leader Shooter', $this->gp, 'LD-1');
    $edge = coloursShooter('Edge Shooter', $this->gp, 'ED-1');
    coloursStanding($leader, 'PRS', $this->gp, $this->open, 100);
    coloursStanding($edge, 'PRS', $this->gp, $this->open, 70);

    $rows = collect(app(FederationColoursReport::class)->build('PRS', '2026', [
        'gold' => 90,
        'silver' => 80,
        'bronze' => 70,
    ])['divisions'][0]['provinces'][0]['rows'])->keyBy('name');

    expect($rows['Leader Shooter']['grade'])->toBe('Gold')
        ->and($rows['Edge Shooter']['percentage'])->toBe(70.0)
        ->and($rows['Edge Shooter']['grade'])->toBe('Bronze');
});

it('filters to one division or one province without changing the leader percentage', function () {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    $rival = coloursShooter('Open Rival', $this->gp, 'OR-1');
    $cape = coloursShooter('Cape Leader', $this->wc, 'CL-1');
    coloursStanding($kevin, 'PR22', $this->gp, $this->open, 200);
    coloursStanding($rival, 'PR22', $this->gp, $this->open, 160);
    coloursStanding($kevin, 'PR22', $this->gp, $this->factory, 150);
    coloursStanding($cape, 'PR22', $this->wc, $this->open, 100);

    $openOnly = app(FederationColoursReport::class)->build('PR22', '2026', ['division_id' => $this->open->id]);
    expect(collect($openOnly['divisions'])->pluck('name')->all())->toBe(['Open']);
    $gpOpen = collect($openOnly['divisions'][0]['provinces'])->firstWhere('name', 'Gauteng');
    expect(collect($gpOpen['rows'])->firstWhere('name', 'Open Rival')['percentage'])->toBe(80.0)
        ->and(collect($gpOpen['rows'])->firstWhere('name', 'Open Rival')['grade'])->toBe('Bronze');

    $gpOnly = app(FederationColoursReport::class)->build('PR22', '2026', ['province_id' => $this->gp->id]);
    $provinceNames = collect($gpOnly['divisions'])->flatMap(fn (array $division) => collect($division['provinces'])->pluck('name'))->unique()->values()->all();
    expect($provinceNames)->toBe(['Gauteng']);
});

it('refuses the report to a member', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('reports.federation-colours'))
        ->assertForbidden();
});

// ──────────────────────────────────────────────────────────────────────
// top_n filter + summary strip + clickable shooter links
// ──────────────────────────────────────────────────────────────────────

it('trims each province group to top N shooters without re-anchoring the leader percentage', function () {
    // 6 Open shooters in Gauteng: top N=3 should keep ranks 1–3 only, and
    // the top 3 keep their original % against the real province leader
    // (200 pts = 100%). If top_n accidentally re-anchored the baseline on
    // the #3 shooter, #3 would report 100% — the assertion below guards
    // against that regression.
    $leader = coloursShooter('Leader One', $this->gp, 'LD-TOP');
    $second = coloursShooter('Second Two', $this->gp, 'SC-TOP');
    $third = coloursShooter('Third Three', $this->gp, 'TH-TOP');
    $fourth = coloursShooter('Fourth Four', $this->gp, 'FR-TOP');
    $fifth = coloursShooter('Fifth Five', $this->gp, 'FF-TOP');
    $sixth = coloursShooter('Sixth Six', $this->gp, 'SX-TOP');

    coloursStanding($leader, 'PR22', $this->gp, $this->open, 200);
    coloursStanding($second, 'PR22', $this->gp, $this->open, 180);
    coloursStanding($third, 'PR22', $this->gp, $this->open, 160);
    coloursStanding($fourth, 'PR22', $this->gp, $this->open, 140);
    coloursStanding($fifth, 'PR22', $this->gp, $this->open, 120);
    coloursStanding($sixth, 'PR22', $this->gp, $this->open, 100);

    $report = app(FederationColoursReport::class)->build('PR22', '2026', ['top_n' => 3]);
    $gpOpen = $report['divisions'][0]['provinces'][0];

    expect($gpOpen['rows'])->toHaveCount(3)
        ->and($gpOpen['total_shooters'])->toBe(6)
        ->and($gpOpen['shown_rows'])->toBe(3)
        ->and($gpOpen['leader_points'])->toBe(200.0)
        ->and(collect($gpOpen['rows'])->pluck('name')->all())->toBe(['Leader One', 'Second Two', 'Third Three'])
        ->and($gpOpen['rows'][0]['percentage'])->toBe(100.0)
        ->and($gpOpen['rows'][1]['percentage'])->toBe(90.0)
        ->and($gpOpen['rows'][2]['percentage'])->toBe(80.0);
});

it('leaves provinces with fewer than N shooters unchanged', function () {
    // WC only has 1 shooter; top_n = 10 should not pad or error.
    $cape = coloursShooter('Cape Only', $this->wc, 'CO-1');
    coloursStanding($cape, 'PR22', $this->wc, $this->open, 150);

    $report = app(FederationColoursReport::class)->build('PR22', '2026', ['top_n' => 10]);
    $wcOpen = $report['divisions'][0]['provinces'][0];

    expect($wcOpen['rows'])->toHaveCount(1)
        ->and($wcOpen['total_shooters'])->toBe(1)
        ->and($wcOpen['shown_rows'])->toBe(1);
});

it('populates a summary strip with grade counts reflecting the trimmed output', function () {
    // 4 shooters: 2 Gold, 1 Silver, 1 (below Bronze cutoff → no grade).
    // Top N=2 should drop the Silver and no-grade rows from the summary.
    $g1 = coloursShooter('Gold One', $this->gp, 'G1-SUM');
    $g2 = coloursShooter('Gold Two', $this->gp, 'G2-SUM');
    $s1 = coloursShooter('Silver One', $this->gp, 'S1-SUM');
    $x1 = coloursShooter('No Grade', $this->gp, 'X1-SUM');

    coloursStanding($g1, 'PR22', $this->gp, $this->open, 200);
    coloursStanding($g2, 'PR22', $this->gp, $this->open, 195);
    coloursStanding($s1, 'PR22', $this->gp, $this->open, 180);
    coloursStanding($x1, 'PR22', $this->gp, $this->open, 100);

    $full = app(FederationColoursReport::class)->build('PR22', '2026');
    expect($full['summary']['total_rows'])->toBe(4)
        ->and($full['summary']['gold'])->toBe(2)
        ->and($full['summary']['silver'])->toBe(1)
        ->and($full['summary']['bronze'])->toBe(0)
        ->and($full['summary']['divisions'])->toBe(1)
        ->and($full['summary']['provinces'])->toBe(1);

    $topTwo = app(FederationColoursReport::class)->build('PR22', '2026', ['top_n' => 2]);
    expect($topTwo['summary']['total_rows'])->toBe(2)
        ->and($topTwo['summary']['gold'])->toBe(2)
        ->and($topTwo['summary']['silver'])->toBe(0)
        ->and($topTwo['summary']['bronze'])->toBe(0);
});

it('links shooter names to their public profile and honours the top_n querystring', function () {
    $leader = coloursShooter('Linked Leader', $this->gp, 'LL-1');
    $second = coloursShooter('Linked Second', $this->gp, 'LL-2');
    $third = coloursShooter('Linked Third', $this->gp, 'LL-3');
    $fourth = coloursShooter('Linked Fourth', $this->gp, 'LL-4');

    coloursStanding($leader, 'PR22', $this->gp, $this->open, 200);
    coloursStanding($second, 'PR22', $this->gp, $this->open, 180);
    coloursStanding($third, 'PR22', $this->gp, $this->open, 160);
    coloursStanding($fourth, 'PR22', $this->gp, $this->open, 140);

    // top_n=3 is the smallest value on the whitelist in ReportsController::parseTopN.
    $response = $this->actingAs($this->admin)
        ->get(route('reports.federation-colours', ['series' => 'PR22', 'season' => '2026', 'top_n' => 3]))
        ->assertOk()
        ->assertSee('Linked Leader')
        ->assertSee('Linked Second')
        ->assertSee('Linked Third')
        ->assertDontSee('Linked Fourth'); // trimmed by top_n=3

    // Each shown shooter name is wrapped in an anchor pointing at their
    // canonical profile URL. The PR22 season context is preserved so the
    // profile lands directly on the right season view.
    $leaderUrl = route('shooters.show.season', ['saprfNumber' => 'LL-1', 'season' => '2026']);
    $secondUrl = route('shooters.show.season', ['saprfNumber' => 'LL-2', 'season' => '2026']);
    $response->assertSee($leaderUrl, false)
        ->assertSee($secondUrl, false);
});

it('renders a standings sheet with no grades and downloads a standings pdf', function () {
    $kevin = coloursShooter('Kevin Goncalves', $this->gp, 'KG-1');
    coloursStanding($kevin, 'PR22', $this->gp, $this->open, 200);

    $this->actingAs($this->admin)
        ->get(route('reports.federation-colours', ['series' => 'PR22', 'season' => '2026', 'awards' => '0']))
        ->assertOk()
        ->assertSee('Provincial Standings')
        ->assertSee('Kevin Goncalves')
        ->assertSee('Standings PDF')
        ->assertDontSee('Require SA Championships')
        ->assertDontSee('>Gold<', false);

    $pdf = $this->actingAs($this->admin)
        ->get(route('reports.federation-colours.pdf', ['series' => 'PR22', 'season' => '2026', 'awards' => '0']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-disposition'))->toContain('SAPRF-Provincial-Standings-PR22-2026.pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $csv = $this->actingAs($this->admin)
        ->get(route('reports.federation-colours.csv', ['series' => 'PR22', 'season' => '2026', 'awards' => '0']));
    $body = $csv->streamedContent();
    expect($csv->headers->get('content-disposition'))->toContain('SAPRF-Provincial-Standings-PR22-2026.csv')
        ->and($body)->toContain('Kevin Goncalves')
        ->and($body)->not->toContain('Grade');
});

it('ignores an unknown top_n value and falls back to the full list', function () {
    // The controller whitelists top_n to a fixed set (3/5/10/15/20/25/50).
    // An out-of-range value should resolve to null, not an empty report.
    $one = coloursShooter('First', $this->gp, 'FS-NOP');
    $two = coloursShooter('Second', $this->gp, 'SC-NOP');
    coloursStanding($one, 'PR22', $this->gp, $this->open, 100);
    coloursStanding($two, 'PR22', $this->gp, $this->open, 80);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.federation-colours', ['series' => 'PR22', 'season' => '2026', 'top_n' => 99]))
        ->assertOk()
        ->assertSee('First')
        ->assertSee('Second');
});
