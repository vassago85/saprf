<?php

use App\Models\Division;
use App\Models\Province;
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

it('refuses the report to a member', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('reports.federation-colours'))
        ->assertForbidden();
});
