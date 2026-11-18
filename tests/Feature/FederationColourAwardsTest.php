<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\FederationColourAwards;
use Illuminate\Support\Str;

beforeEach(function () {
    seedRoles();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->wc = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    $this->open = Division::create([
        'slug' => 'open-awards',
        'name' => 'Open',
        'display_order' => 1,
        'is_active' => true,
    ]);
    $this->factory = Division::create([
        'slug' => 'factory-awards',
        'name' => 'Factory',
        'display_order' => 2,
        'is_active' => true,
    ]);
});

it('awards colours from the national series log against the top score in that division', function () {
    $leader = colourAwardShooter('National Leader', $this->gp);
    $gold = colourAwardShooter('Ninety Five', $this->wc);
    $silver = colourAwardShooter('Ninety Flat', $this->gp);
    $bronze = colourAwardShooter('Eighty Five', $this->wc);
    $none = colourAwardShooter('Below Colour', $this->gp);
    $justUnderSilver = colourAwardShooter('Just Under Silver', $this->gp);
    $justUnderGold = colourAwardShooter('Just Under Gold', $this->wc);

    foreach ([$leader, $gold, $silver, $bronze, $none, $justUnderSilver, $justUnderGold] as $shooter) {
        colourAwardQualifyingNational($shooter, 'PRS', $this->open);
    }

    colourAwardLog($leader, 'PRS', $this->open, 200);
    colourAwardLog($gold, 'PRS', $this->open, 190);
    colourAwardLog($justUnderGold, 'PRS', $this->open, 189.98);
    colourAwardLog($silver, 'PRS', $this->open, 180);
    colourAwardLog($justUnderSilver, 'PRS', $this->open, 179.98);
    colourAwardLog($bronze, 'PRS', $this->open, 170);
    colourAwardLog($none, 'PRS', $this->open, 169.98);

    $rows = colourAwardRows('PRS', $this->open->id);

    expect($rows['National Leader']['raw_score'])->toBe(200.0)
        ->and($rows['National Leader']['weighted_percentage'])->toBe(100.0)
        ->and($rows['National Leader']['award'])->toBe('Gold')
        ->and($rows['National Leader']['rank'])->toBe(1)
        ->and($rows['National Leader']['division'])->toBe('Open');
    expect($rows['Ninety Five']['weighted_percentage'])->toBe(95.0)
        ->and($rows['Ninety Five']['award'])->toBe('Gold');
    expect($rows['Just Under Gold']['weighted_percentage'])->toBe(94.99)
        ->and($rows['Just Under Gold']['award'])->toBe('Silver');
    expect($rows['Ninety Flat']['weighted_percentage'])->toBe(90.0)
        ->and($rows['Ninety Flat']['award'])->toBe('Silver');
    expect($rows['Just Under Silver']['weighted_percentage'])->toBe(89.99)
        ->and($rows['Just Under Silver']['award'])->toBe('Bronze');
    expect($rows['Eighty Five']['weighted_percentage'])->toBe(85.0)
        ->and($rows['Eighty Five']['award'])->toBe('Bronze');
    expect($rows['Below Colour']['weighted_percentage'])->toBe(84.99)
        ->and($rows['Below Colour']['award'])->toBeNull();
});

it('keeps a tied top score as the reference and gives both shooters gold', function () {
    $ada = colourAwardShooter('Ada Tied', $this->gp);
    $bea = colourAwardShooter('Bea Tied', $this->wc);
    colourAwardQualifyingNational($ada, 'PR22', $this->open);
    colourAwardQualifyingNational($bea, 'PR22', $this->open);
    colourAwardLog($ada, 'PR22', $this->open, 150);
    colourAwardLog($bea, 'PR22', $this->open, 150);

    $division = colourAwardDivision('PR22', $this->open->id);

    expect($division['reference_score'])->toBe(150.0)
        ->and($division['rows'])->toHaveCount(2)
        ->and($division['rows'][0]['rank'])->toBe(1)
        ->and($division['rows'][1]['rank'])->toBe(1)
        ->and($division['rows'][0]['weighted_percentage'])->toBe(100.0)
        ->and($division['rows'][1]['weighted_percentage'])->toBe(100.0)
        ->and($division['rows'][0]['award'])->toBe('Gold')
        ->and($division['rows'][1]['award'])->toBe('Gold');
});

it('measures each division against its own national leader', function () {
    $openLeader = colourAwardShooter('Open Leader', $this->gp);
    $factoryLeader = colourAwardShooter('Factory Leader', $this->wc);
    colourAwardQualifyingNational($openLeader, 'PRS', $this->open);
    colourAwardQualifyingNational($factoryLeader, 'PRS', $this->factory);
    colourAwardLog($openLeader, 'PRS', $this->open, 400);
    colourAwardLog($factoryLeader, 'PRS', $this->factory, 100);

    $factory = colourAwardRows('PRS', $this->factory->id);

    expect($factory['Factory Leader']['weighted_percentage'])->toBe(100.0)
        ->and($factory['Factory Leader']['award'])->toBe('Gold')
        ->and($factory)->not->toHaveKey('Open Leader');
});

it('qualifies a shooter from day 1 of the SA Championships even when their home province differs from the champs venue', function () {
    // Live champs scenario: day 1 of a 2-day WC-hosted champs. Scores live
    // on the day-1 provincial sibling of the `final`, not on the final yet.
    $wcHost = colourAwardShooter('Live Host', $this->wc);
    $gpTraveller = colourAwardShooter('Live Traveller', $this->gp);
    $regularSibling = colourAwardShooter('Regular Sibling', $this->gp);

    colourAwardChampsDay1Sibling($wcHost, 'PRS', $this->open, $this->wc);
    colourAwardChampsDay1Sibling($gpTraveller, 'PRS', $this->open, $this->wc);
    // Day-1 sibling of a regular two-day NATIONAL (not a final) hosted in WC.
    // The GP shooter here must still be excluded — the home-province check
    // only lifts for champs siblings.
    colourAwardDayOneSibling($regularSibling, 'PRS', $this->open, $this->wc);

    colourAwardLog($wcHost, 'PRS', $this->open, 200);
    colourAwardLog($gpTraveller, 'PRS', $this->open, 190);
    colourAwardLog($regularSibling, 'PRS', $this->open, 180);

    $division = colourAwardDivision('PRS', $this->open->id);
    $names = collect($division['rows'])->pluck('name')->all();

    expect($names)->toBe(['Live Host', 'Live Traveller'])
        ->and($division['rows'][1]['weighted_percentage'])->toBe(95.0)
        ->and($division['rows'][1]['award'])->toBe('Gold');
});

it('qualifies a shooter who shot the SA Championships even when their home province differs from the champs venue', function () {
    $wcHost = colourAwardShooter('WC Host', $this->wc);
    $gpTraveller = colourAwardShooter('GP Traveller', $this->gp);
    $didNotShootChamps = colourAwardShooter('No Champs', $this->gp);

    // WC shooter qualifies the old way: shot the national-as-provincial at home.
    colourAwardQualifyingNational($wcHost, 'PRS', $this->open);

    // GP shooter travelled to Champs (held in WC) — must now qualify.
    colourAwardChamps($gpTraveller, 'PRS', $this->open, $this->wc);

    // GP shooter who only has a national log but never shot champs AND the
    // national-as-provincial wasn't in their home province: still excluded.
    // (No qualifying match created for didNotShootChamps.)

    colourAwardLog($wcHost, 'PRS', $this->open, 200);
    colourAwardLog($gpTraveller, 'PRS', $this->open, 190);
    colourAwardLog($didNotShootChamps, 'PRS', $this->open, 180);

    $division = colourAwardDivision('PRS', $this->open->id);
    $names = collect($division['rows'])->pluck('name')->all();

    expect($names)->toBe(['WC Host', 'GP Traveller'])
        ->and($division['rows'][1]['weighted_percentage'])->toBe(95.0)
        ->and($division['rows'][1]['award'])->toBe('Gold');
});

it('leaves out anyone who is not a paid-up member or has not shot their provincial national', function () {
    $eligible = colourAwardShooter('Eligible Shooter', $this->gp);
    $lapsed = colourAwardShooter('Lapsed Leader', $this->gp, paid: false);
    $away = colourAwardShooter('Away National', $this->gp);
    $twoDayOnly = colourAwardShooter('Two Day Only', $this->wc);
    $sibling = colourAwardShooter('Day One Sibling', $this->wc);
    $provincialLog = colourAwardShooter('Provincial Log Only', $this->gp);

    colourAwardQualifyingNational($eligible, 'PRS', $this->open);
    colourAwardQualifyingNational($lapsed, 'PRS', $this->open);
    colourAwardQualifyingNational($away, 'PRS', $this->open, $this->wc);
    colourAwardTwoDayNational($twoDayOnly, 'PRS', $this->open);
    colourAwardDayOneSibling($sibling, 'PRS', $this->open);

    colourAwardLog($lapsed, 'PRS', $this->open, 200);
    colourAwardLog($eligible, 'PRS', $this->open, 180);
    colourAwardLog($away, 'PRS', $this->open, 190);
    colourAwardLog($twoDayOnly, 'PRS', $this->open, 200);
    colourAwardLog($sibling, 'PRS', $this->open, 170);
    colourAwardLog($provincialLog, 'PRS', $this->open, 200, $this->gp->id);
    colourAwardLog($eligible, 'PR22', $this->open, 200);

    $division = colourAwardDivision('PRS', $this->open->id);
    $names = collect($division['rows'])->pluck('name')->all();

    expect($division['reference_score'])->toBe(200.0)
        ->and($names)->toBe(['Eligible Shooter', 'Day One Sibling'])
        ->and($division['rows'][0]['weighted_percentage'])->toBe(90.0)
        ->and($division['rows'][0]['award'])->toBe('Silver')
        ->and($division['rows'][0]['rank'])->toBe(1)
        ->and($division['rows'][1]['weighted_percentage'])->toBe(85.0)
        ->and($division['rows'][1]['award'])->toBe('Bronze');
});

it('shows the awards list and downloads one division as csv', function () {
    $shooter = colourAwardShooter('Csv Shooter', $this->gp, number: 'CSV-1');
    colourAwardQualifyingNational($shooter, 'PR22', $this->open);
    colourAwardLog($shooter, 'PR22', $this->open, 100);

    $this->actingAs($this->admin)
        ->get(route('reports.federation-colour-awards', ['series' => 'PR22', 'season' => '2026']))
        ->assertOk()
        ->assertSee('Csv Shooter')
        ->assertSee('Open')
        ->assertSee('Gold')
        ->assertSee('CSV-1')
        ->assertDontSee('Province');

    $csv = $this->actingAs($this->admin)
        ->get(route('reports.federation-colour-awards.csv', [
            'series' => 'PR22',
            'season' => '2026',
            'division' => $this->open->id,
        ]));
    $csv->assertOk();
    $body = $csv->streamedContent();

    expect($csv->headers->get('content-disposition'))->toContain('SAPRF-Federation-Colour-Awards-PR22-2026.csv')
        ->and($body)->toContain('Csv Shooter')
        ->and($body)->toContain('Open')
        ->and($body)->toContain('CSV-1')
        ->and($body)->toContain('Gold')
        ->and($body)->not->toContain('Factory');

    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('reports.federation-colour-awards'))
        ->assertForbidden();
});

it('shows the earned colour and series on the public profile', function () {
    $shooter = colourAwardShooter('Profile Shooter', $this->gp, number: 'PRO-1');
    colourAwardQualifyingNational($shooter, 'PRS', $this->open);
    colourAwardQualifyingNational($shooter, 'PR22', $this->factory);
    colourAwardLog($shooter, 'PRS', $this->open, 200);
    colourAwardLog($shooter, 'PR22', $this->factory, 100);
    colourAwardLog($shooter, 'PRS', $this->factory, 80);

    $below = colourAwardShooter('No Colour', $this->wc, number: 'PRO-2');
    colourAwardQualifyingNational($below, 'PRS', $this->open);
    colourAwardLog($below, 'PRS', $this->open, 100);

    $this->get(route('shooters.show.season', ['saprfNumber' => 'PRO-1', 'season' => '2026']))
        ->assertOk()
        ->assertSee('Federation Colour')
        ->assertSee('Gold')
        ->assertSee('Centrefire')
        ->assertSee('PR22')
        ->assertSee('Open')
        ->assertSee('Factory');

    $this->get(route('shooters.show.season', ['saprfNumber' => 'PRO-2', 'season' => '2026']))
        ->assertOk()
        ->assertDontSee('Federation Colour');
});

function colourAwardShooter(string $name, Province $province, bool $paid = true, ?string $number = null): User
{
    $user = User::factory()->create([
        'name' => $name,
        'province_id' => $province->id,
    ]);

    if ($paid) {
        giveActiveMembership($user, $number);
    } else {
        Membership::create([
            'user_id' => $user->id,
            'saprf_number' => $number ?? ('L-'.strtoupper(Str::random(6))),
            'membership_type' => 'paid',
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => now()->subYears(2)->toDateString(),
            'expiry_date' => now()->subYear()->toDateString(),
        ]);
    }

    return $user;
}

function colourAwardQualifyingNational(User $shooter, string $series, Division $division, ?Province $matchProvince = null): void
{
    $match = colourAwardMatch($shooter, $series, 'national', $matchProvince ?? $shooter->province);
    colourAwardScore($shooter, $match, $division);
}

function colourAwardChamps(User $shooter, string $series, Division $division, Province $venue): void
{
    $match = colourAwardMatch($shooter, $series, 'final', $venue, [
        'name' => $shooter->name.' Champs',
    ]);
    colourAwardScore($shooter, $match, $division);
}

function colourAwardChampsDay1Sibling(User $shooter, string $series, Division $division, Province $venue): void
{
    // Live day 1 of a 2-day champs: the `final` exists but has no scores yet;
    // scores are written to its provincial sibling in the same venue.
    $final = colourAwardMatch($shooter, $series, 'final', $venue, [
        'name' => $shooter->name.' Two Day Champs',
        'match_end_date' => '2026-06-02',
    ]);
    $sibling = colourAwardMatch($shooter, $series, 'provincial', $venue, [
        'name' => $shooter->name.' Champs Day 1',
        'source_national_match_id' => $final->id,
    ]);
    colourAwardScore($shooter, $sibling, $division);
}

function colourAwardTwoDayNational(User $shooter, string $series, Division $division): void
{
    $match = colourAwardMatch($shooter, $series, 'national', $shooter->province, [
        'match_end_date' => '2026-06-02',
    ]);
    colourAwardScore($shooter, $match, $division);
}

function colourAwardDayOneSibling(User $shooter, string $series, Division $division, ?Province $venue = null): void
{
    $venue = $venue ?? $shooter->province;
    $national = colourAwardMatch($shooter, $series, 'national', $venue, [
        'name' => $shooter->name.' Two Day',
        'match_end_date' => '2026-06-02',
    ]);
    $sibling = colourAwardMatch($shooter, $series, 'provincial', $venue, [
        'name' => $shooter->name.' Day 1',
        'source_national_match_id' => $national->id,
    ]);
    colourAwardScore($shooter, $sibling, $division);
}

function colourAwardMatch(User $owner, string $series, string $level, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => $owner->name.' '.$level,
        'match_type' => $series,
        'series' => $series,
        'series_level' => $level,
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => '2026-06-01',
        'status' => 'completed',
        'published' => true,
        'created_by' => $owner->id,
    ], $overrides));
}

function colourAwardScore(User $shooter, MatchEvent $match, Division $division): void
{
    Score::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'division_id' => $division->id,
        'raw_score' => 80,
        'normalized_score' => 80,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $match->match_date,
    ]);
}

function colourAwardLog(User $shooter, string $series, Division $division, float $points, ?int $provinceId = null): void
{
    Standing::create([
        'user_id' => $shooter->id,
        'series' => $series,
        'season' => '2026',
        'province_id' => $provinceId,
        'division_id' => $division->id,
        'points' => $points,
        'rank' => 1,
    ]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function colourAwardRows(string $series, int $divisionId): array
{
    return collect(colourAwardDivision($series, $divisionId)['rows'])->keyBy('name')->all();
}

/**
 * @return array<string, mixed>
 */
function colourAwardDivision(string $series, int $divisionId): array
{
    $report = app(FederationColourAwards::class)->build($series, '2026');

    return collect($report['divisions'])->firstWhere('id', $divisionId);
}
