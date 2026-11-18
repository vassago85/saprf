<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;
use App\Services\ImpactScoring\SquadRecommendation;
use Carbon\Carbon;
use Database\Seeders\DivisionCategorySeeder;

beforeEach(function () {
    seedRoles();
    $this->seed(DivisionCategorySeeder::class);

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'SA Champs Squadding',
        'match_type' => 'PR22',
        'series_level' => 'national',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 550,
        'non_member_fee' => 750,
        'lapsed_member_fee' => 700,
        'created_by' => $this->md->id,
    ]);
});

function enterRankedShooter(MatchEvent $match, string $name, int $rank, string $divisionSlug = 'open', bool $ranked = true): void
{
    static $saprf = 5000;
    $saprf++;

    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug('.').'@example.test',
        'phone' => '0821234567',
        'email_verified_at' => now(),
    ]);

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => (string) $saprf,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(6),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);

    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'division_id' => Division::where('slug', $divisionSlug)->value('id'),
        'shooter_name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    if (! $ranked) {
        return;
    }

    Standing::create([
        'user_id' => $user->id,
        'series' => $match->series,
        'season' => (string) $match->season,
        'province_id' => null,
        'division_id' => null,
        'points' => 1000 - $rank,
        'rank' => $rank,
    ]);
}

/**
 * @return \Illuminate\Support\Collection<string, array<string, string>>
 */
function squadCsvByName(string $csv): \Illuminate\Support\Collection
{
    $lines = array_values(array_filter(preg_split("/\r?\n/", trim($csv)) ?: []));
    $header = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = array_combine($header, str_getcsv($line));
    }

    return collect($rows)->keyBy(
        fn (array $row) => trim($row['First Name'].' '.$row['Last Name'])
    );
}

/**
 * @param  list<array{number: int, members: list<array{name: string, rank: ?int}>}>  $squads
 * @return list<int|null>
 */
function ranksInSquad(array $squads, int $number): array
{
    $squad = collect($squads)->firstWhere('number', $number);

    return array_map(fn (array $member) => $member['rank'], $squad['members']);
}

/**
 * @param  list<array{number: int, members: list<array{name: string, rank: ?int}>}>  $squads
 */
function squadNumberFor(array $squads, string $name): int
{
    foreach ($squads as $squad) {
        foreach ($squad['members'] as $member) {
            if ($member['name'] === $name) {
                return $squad['number'];
            }
        }
    }

    throw new RuntimeException("{$name} was not squadded");
}

it('splits 55 ranked shooters into five squads of 11 by overall rank', function () {
    for ($rank = 1; $rank <= 55; $rank++) {
        enterRankedShooter($this->match, sprintf('Open %02d', $rank), $rank);
    }

    $result = app(SquadRecommendation::class)->recommend($this->match, 5);

    expect($result['squads'])->toHaveCount(5);
    expect(ranksInSquad($result['squads'], 1))->toBe(range(1, 11));
    expect(ranksInSquad($result['squads'], 5))->toBe(range(45, 55));
    expect(collect($result['squads'])->flatMap(fn (array $squad) => $squad['members']))->toHaveCount(55);
});

it('shares one squad for ladies and juniors and fills it with shooters closest to the middle', function () {
    $ladyRanks = [5, 8, 20, 30, 40, 50];
    $juniorRanks = [6, 25, 45];
    foreach (range(1, 55) as $rank) {
        $slug = 'open';
        $name = sprintf('Open %02d', $rank);
        if (in_array($rank, $ladyRanks, true)) {
            $slug = 'ladies';
            $name = sprintf('Lady %02d', $rank);
        } elseif (in_array($rank, $juniorRanks, true)) {
            $slug = 'junior';
            $name = sprintf('Junior %02d', $rank);
        }
        enterRankedShooter($this->match, $name, $rank, $slug);
    }

    $result = app(SquadRecommendation::class)->recommend($this->match, 5);
    $shared = squadNumberFor($result['squads'], 'Lady 05');

    expect(squadNumberFor($result['squads'], 'Junior 06'))->toBe($shared)
        ->and(ranksInSquad($result['squads'], $shared))->toContain(24, 26)
        ->and(ranksInSquad($result['squads'], $shared))->toHaveCount(11);
});

it('keeps the best ladies when they overflow a squad and leaves a short junior group in the ranking', function () {
    foreach (range(1, 55) as $rank) {
        $slug = $rank <= 14 ? 'ladies' : 'open';
        if ($rank >= 15 && $rank <= 18) {
            $slug = 'junior';
        }
        $name = match ($slug) {
            'ladies' => sprintf('Lady %02d', $rank),
            'junior' => sprintf('Junior %02d', $rank),
            default => sprintf('Open %02d', $rank),
        };
        enterRankedShooter($this->match, $name, $rank, $slug);
    }

    $result = app(SquadRecommendation::class)->recommend($this->match, 5);
    $ladiesSquad = squadNumberFor($result['squads'], 'Lady 01');
    $ladyRanks = ranksInSquad($result['squads'], $ladiesSquad);

    expect($ladyRanks)->toBe(range(1, 11))
        ->and($ladiesSquad)->toBe(1)
        ->and(squadNumberFor($result['squads'], 'Lady 12'))->not->toBe($ladiesSquad)
        ->and(squadNumberFor($result['squads'], 'Junior 15'))->not->toBe($ladiesSquad);
});

it('keeps a mid-pack shared squad where its best rank sits and continues the ranking squads around it', function () {
    foreach (range(1, 12) as $rank) {
        $slug = 'open';
        $name = sprintf('Open %02d', $rank);
        if (in_array($rank, [3, 7, 10], true)) {
            $slug = 'ladies';
            $name = sprintf('Lady %02d', $rank);
        } elseif ($rank === 4) {
            $slug = 'junior';
            $name = sprintf('Junior %02d', $rank);
        }
        enterRankedShooter($this->match, $name, $rank, $slug);
    }

    $result = app(SquadRecommendation::class)->recommend($this->match, 3);

    expect(ranksInSquad($result['squads'], 1))->toBe([1, 2, 5, 6])
        ->and(ranksInSquad($result['squads'], 2))->toBe([3, 4, 7, 10])
        ->and(ranksInSquad($result['squads'], 3))->toBe([8, 9, 11, 12]);
});

it('moves a shooter into another squad and downloads that squad', function () {
    foreach (range(1, 4) as $rank) {
        enterRankedShooter($this->match, sprintf('Open %02d', $rank), $rank);
    }

    $preview = app(SquadRecommendation::class)->recommend($this->match, 2);
    $open01 = collect($preview['squads'])->flatMap(fn (array $squad) => $squad['members'])->firstWhere('name', 'Open 01');
    $placement = $preview['squad_by_registration'];
    $placement[$open01['registration_id']] = 2;

    $download = $this->actingAs($this->md)
        ->post(route('matches.squad-recommendation.export', $this->match), [
            'squads' => 2,
            'placement' => $placement,
        ])
        ->assertOk();

    expect(squadCsvByName($download->streamedContent())['Open 01']['Squad'])->toBe('2');

    $this->actingAs($this->md)
        ->post(route('matches.squad-recommendation.move', $this->match), [
            'squads' => 2,
            'placement' => $placement,
        ])
        ->assertRedirect(route('matches.squad-recommendation', [$this->match, 'squads' => 2]));

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation', [$this->match, 'squads' => 2]))
        ->assertOk()
        ->assertSeeInOrder(['Squad 2', 'Open 01']);

    $saved = $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation.export', [$this->match, 'squads' => 2]))
        ->assertOk();

    expect(squadCsvByName($saved->streamedContent())['Open 01']['Squad'])->toBe('2');

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation', [$this->match, 'squads' => 2, 'fresh' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['Squad 1', 'Open 01']);

    $reset = $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation.export', [$this->match, 'squads' => 2]))
        ->assertOk();

    expect(squadCsvByName($reset->streamedContent())['Open 01']['Squad'])->toBe('1');
});

it('places an unranked shooter after every ranked shooter', function () {
    foreach (range(1, 10) as $rank) {
        enterRankedShooter($this->match, sprintf('Open %02d', $rank), $rank);
    }
    enterRankedShooter($this->match, 'Unranked Shooter', 99, 'open', false);

    $result = app(SquadRecommendation::class)->recommend($this->match, 2);

    expect(squadNumberFor($result['squads'], 'Unranked Shooter'))
        ->toBeGreaterThan(squadNumberFor($result['squads'], 'Open 01'))
        ->and($result['unranked_count'])->toBe(1);
});

it('shows the preview and downloads an Impact file whose squad column matches it', function () {
    foreach (range(1, 4) as $rank) {
        enterRankedShooter($this->match, sprintf('Open %02d', $rank), $rank);
    }

    $preview = $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation', [$this->match, 'squads' => 2]))
        ->assertOk()
        ->assertSee('Squad 1')
        ->assertSee('Open 01')
        ->assertSee('Download for Impact');

    $export = $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation.export', [$this->match, 'squads' => 2]))
        ->assertOk();

    $byName = squadCsvByName($export->streamedContent());
    expect($byName['Open 01']['Squad'])->toBe('1')
        ->and($byName['Open 02']['Squad'])->toBe('1')
        ->and($byName['Open 03']['Squad'])->toBe('2')
        ->and($byName['Open 04']['Squad'])->toBe('2');

    $plain = $this->actingAs($this->md)
        ->get(route('matches.export-impact-scoring', $this->match))
        ->assertOk();
    $plainByName = squadCsvByName($plain->streamedContent());
    expect($plainByName['Open 01']['Squad'])->toBe('');

    $preview->assertSee('Rank 1');
});

it('saves a squad draft and downloads a shooter sheet without contact details', function () {
    foreach (range(1, 4) as $rank) {
        enterRankedShooter($this->match, sprintf('Open %02d', $rank), $rank);
    }

    $open = MatchRegistration::where('match_id', $this->match->id)->where('shooter_name', 'Open 01')->firstOrFail();
    $others = MatchRegistration::where('match_id', $this->match->id)->where('shooter_name', '!=', 'Open 01')->pluck('id');
    $placement = [$open->id => 2];
    foreach ($others as $index => $id) {
        $placement[$id] = $index === 0 ? 1 : 2;
    }

    $this->actingAs($this->md)
        ->post(route('matches.squad-recommendation.draft', $this->match), [
            'squads' => 2,
            'placement' => $placement,
            'impact_email' => [$open->id => 'range.only@example.test'],
        ])
        ->assertRedirect(route('matches.squad-recommendation', [$this->match, 'squads' => 2]))
        ->assertSessionHas('success');

    $draft = $this->match->fresh()->squad_draft;
    $encoded = json_encode($draft);
    expect($encoded)->toContain('Open 01')
        ->and($encoded)->not->toContain('range.only@example.test')
        ->and($encoded)->not->toContain('@example.test')
        ->and($encoded)->not->toContain('0821234567');

    $this->flushSession();

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation', [$this->match, 'squads' => 2]))
        ->assertOk()
        ->assertSeeInOrder(['Squad 2', 'Open 01'])
        ->assertSee('Download saved squad sheet');

    $html = view('matches.squad-sheet-pdf', [
        'match' => $this->match,
        'squads' => $draft['squads'],
        'savedAt' => $draft['saved_at'],
    ])->render();

    expect($html)->toContain('Open 01')
        ->and($html)->toContain('Squad 2')
        ->and($html)->not->toContain('range.only@example.test')
        ->and($html)->not->toContain('open.01@example.test')
        ->and($html)->not->toContain('0821234567');

    $sheet = $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation.pdf', $this->match))
        ->assertOk();

    expect($sheet->headers->get('content-type'))->toContain('pdf')
        ->and($sheet->getContent())->toStartWith('%PDF')
        ->and($sheet->headers->get('content-disposition'))->toContain('sa-champs-squadding-squad-sheet.pdf');

    $current = $this->actingAs($this->md)
        ->post(route('matches.squad-recommendation.pdf', $this->match), [
            'squads' => 2,
            'placement' => $placement,
        ])
        ->assertOk();

    expect($current->getContent())->toStartWith('%PDF');

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation', [$this->match, 'squads' => 2, 'fresh' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['Squad 1', 'Open 01']);

    expect($this->match->fresh()->squad_draft['squads'])->not->toBeEmpty();
});

it('refuses a squad sheet download when nothing has been saved', function () {
    enterRankedShooter($this->match, 'Open 01', 1);

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation.pdf', $this->match))
        ->assertRedirect(route('matches.entries', $this->match))
        ->assertSessionHas('error', 'Save a squad draft before downloading the sheet.');
});

it('rejects a squad count of zero or bigger than the field', function () {
    enterRankedShooter($this->match, 'Open 01', 1);
    enterRankedShooter($this->match, 'Open 02', 2);

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation', [$this->match, 'squads' => 0]))
        ->assertRedirect(route('matches.entries', $this->match))
        ->assertSessionHas('error', 'Enter a squad count between 1 and 2.');

    $this->actingAs($this->md)
        ->get(route('matches.squad-recommendation.export', [$this->match, 'squads' => 9]))
        ->assertRedirect(route('matches.entries', $this->match))
        ->assertSessionHas('error', 'Enter a squad count between 1 and 2.');
});
