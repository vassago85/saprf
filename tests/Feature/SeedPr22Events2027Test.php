<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;

function seedPr22CalendarProvinces(): void
{
    foreach ([
        ['name' => 'Free State', 'abbreviation' => 'FS'],
        ['name' => 'Gauteng', 'abbreviation' => 'GP'],
        ['name' => 'Limpopo', 'abbreviation' => 'LP'],
        ['name' => 'Mpumalanga', 'abbreviation' => 'MP'],
        ['name' => 'Western Cape', 'abbreviation' => 'WC'],
    ] as $province) {
        Province::create($province);
    }
}

it('seeds the provisional 2027 rimfire calendar and leaves existing rows alone', function () {
    seedPr22CalendarProvinces();
    $owner = User::factory()->create();
    $division = Division::create([
        'name' => 'Open',
        'slug' => 'open',
        'is_active' => true,
    ]);

    $this->artisan('pr22:seed-2027-events')->assertSuccessful();

    $matches = MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->orderBy('match_date')->get();

    expect($matches)->toHaveCount(9)
        ->and($matches->every(fn (MatchEvent $match) => $match->series === 'PR22'
            && $match->status === 'closed'
            && $match->published === true
            && $match->active_member_fee === null
            && $match->created_by === $owner->id
            && $match->match_director === 'To be confirmed'
            && $match->registration_status === 'setup_incomplete'))->toBeTrue();

    $balmoral = $matches->firstWhere('name', 'PR22 2027 National - Balmoral');
    expect($balmoral->match_date->toDateString())->toBe('2027-02-21')
        ->and($balmoral->series_level)->toBe('national')
        ->and($balmoral->province->abbreviation)->toBe('MP')
        ->and($balmoral->match_end_date)->toBeNull()
        ->and($balmoral->divisions->pluck('id')->all())->toContain($division->id);

    $capeTown = $matches->where('name', 'PR22 2027 National - Cape Town')->values();
    expect($capeTown)->toHaveCount(2)
        ->and($capeTown[0]->match_date->toDateString())->toBe('2027-04-11')
        ->and($capeTown[1]->match_date->toDateString())->toBe('2027-07-24')
        ->and($capeTown[0]->province->abbreviation)->toBe('WC');

    $gauteng = $matches->firstWhere('name', 'PR22 2027 National - Gauteng');
    expect($gauteng->venue_name)->toBeNull()
        ->and($gauteng->match_date->toDateString())->toBe('2027-06-13')
        ->and($gauteng->description)->toContain('Venue still to be confirmed.');

    $champs = $matches->firstWhere('name', 'PR22 SA Champs 2027');
    expect($champs->series_level)->toBe('final')
        ->and($champs->match_date->toDateString())->toBe('2027-11-06')
        ->and($champs->match_end_date->toDateString())->toBe('2027-11-07')
        ->and($champs->province->abbreviation)->toBe('WC');

    $balmoral->update(['venue_name' => 'Edited range', 'status' => 'open']);

    $this->artisan('pr22:seed-2027-events')->assertSuccessful();

    expect(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->count())->toBe(9)
        ->and($balmoral->fresh()->venue_name)->toBe('Edited range')
        ->and($balmoral->fresh()->status)->toBe('open');
});

it('prints the 2027 rimfire calendar without writing on a dry run', function () {
    seedPr22CalendarProvinces();
    User::factory()->create();

    $this->artisan('pr22:seed-2027-events', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('would create')
        ->expectsOutputToContain('Dry run.');

    expect(MatchEvent::query()->count())->toBe(0);
});
