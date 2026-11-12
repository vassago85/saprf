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
            && $match->registration_status === 'setup_incomplete'))->toBeTrue()
        ->and($matches->reject(fn (MatchEvent $match) => $match->name === 'PR22 2027 National - Legends')
            ->every(fn (MatchEvent $match) => $match->match_director === 'To be confirmed'))->toBeTrue();

    $sungiti = $matches->firstWhere('name', 'PR22 2027 National - Sungiti');
    expect($sungiti->match_date->toDateString())->toBe('2027-02-21')
        ->and($sungiti->venue_name)->toBe('Sungiti')
        ->and($sungiti->city)->toBe('Sungiti')
        ->and($sungiti->venue_location)->toBe('Sungiti, Mpumalanga')
        ->and($sungiti->series_level)->toBe('national')
        ->and($sungiti->province->abbreviation)->toBe('MP')
        ->and($sungiti->match_end_date)->toBeNull()
        ->and($sungiti->divisions->pluck('id')->all())->toContain($division->id);

    $rassie = $matches->firstWhere('name', 'PR22 2027 National - Rassie');
    expect($rassie->match_date->toDateString())->toBe('2027-03-20')
        ->and($rassie->venue_name)->toBe('Rassie')
        ->and($rassie->province->abbreviation)->toBe('LP');

    $capeTown = $matches->where('name', 'PR22 2027 National - Cape Town')->values();
    expect($capeTown)->toHaveCount(2)
        ->and($capeTown[0]->match_date->toDateString())->toBe('2027-04-11')
        ->and($capeTown[1]->match_date->toDateString())->toBe('2027-07-31')
        ->and($capeTown[0]->province->abbreviation)->toBe('WC');

    $legends = $matches->firstWhere('name', 'PR22 2027 National - Legends');
    expect($legends->match_date->toDateString())->toBe('2027-06-19')
        ->and($legends->venue_name)->toBe('Legends Adventure Farm')
        ->and($legends->province->abbreviation)->toBe('GP')
        ->and($legends->match_director)->toBe('Stelios Christofi')
        ->and($legends->description)->not->toContain('Venue still to be confirmed.');

    $leopards = $matches->firstWhere('name', "PR22 2027 National - Leopard's Valley");
    expect($leopards->match_date->toDateString())->toBe('2027-08-21')
        ->and($leopards->venue_name)->toBe("Leopard's Valley")
        ->and($leopards->province->abbreviation)->toBe('GP');

    $champs = $matches->firstWhere('name', 'PR22 SA Champs 2027');
    expect($champs->series_level)->toBe('final')
        ->and($champs->match_date->toDateString())->toBe('2027-11-06')
        ->and($champs->match_end_date->toDateString())->toBe('2027-11-07')
        ->and($champs->province->abbreviation)->toBe('WC');

    $sungiti->update(['venue_name' => 'Edited range', 'status' => 'open']);

    $this->artisan('pr22:seed-2027-events')->assertSuccessful();

    expect(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->count())->toBe(9)
        ->and($sungiti->fresh()->venue_name)->toBe('Edited range')
        ->and($sungiti->fresh()->status)->toBe('open');
});

it('renames an untouched 21 February Balmoral placeholder to Sungiti', function () {
    seedPr22CalendarProvinces();
    $owner = User::factory()->create();
    $mp = Province::where('abbreviation', 'MP')->first();

    $existing = MatchEvent::create([
        'name' => 'PR22 2027 National - Balmoral',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2027',
        'province_id' => $mp->id,
        'venue_name' => 'Balmoral',
        'venue_location' => 'Balmoral, Mpumalanga',
        'city' => 'Balmoral',
        'match_date' => '2027-02-21',
        'status' => 'closed',
        'created_by' => $owner->id,
        'match_director' => 'To be confirmed',
        'active_member_fee' => null,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'published' => true,
        'description' => 'Provisional 2027 PR22 calendar entry. Entries are not open. The date and venue may still change.',
    ]);
    $previousSlug = $existing->slug;

    $this->artisan('pr22:seed-2027-events', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('would rename');

    expect($existing->fresh()->name)->toBe('PR22 2027 National - Balmoral')
        ->and($existing->fresh()->slug)->toBe($previousSlug);

    $this->artisan('pr22:seed-2027-events')
        ->assertSuccessful()
        ->expectsOutputToContain('renamed');

    $existing->refresh();

    expect($existing->name)->toBe('PR22 2027 National - Sungiti')
        ->and($existing->venue_name)->toBe('Sungiti')
        ->and($existing->city)->toBe('Sungiti')
        ->and($existing->venue_location)->toBe('Sungiti, Mpumalanga')
        ->and($existing->slug)->not->toBe($previousSlug)
        ->and($existing->match_date->toDateString())->toBe('2027-02-21')
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->whereDate('match_date', '2027-02-21')->count())->toBe(1)
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->count())->toBe(9);
});

it('leaves a 21 February Balmoral match an admin has already edited', function () {
    seedPr22CalendarProvinces();
    $owner = User::factory()->create();
    $mp = Province::where('abbreviation', 'MP')->first();

    $existing = MatchEvent::create([
        'name' => 'PR22 2027 National - Balmoral',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2027',
        'province_id' => $mp->id,
        'venue_name' => 'Balmoral',
        'city' => 'Balmoral',
        'match_date' => '2027-02-21',
        'status' => 'open',
        'created_by' => $owner->id,
        'match_director' => 'To be confirmed',
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'published' => true,
    ]);

    $this->artisan('pr22:seed-2027-events')->assertSuccessful();

    expect($existing->fresh()->name)->toBe('PR22 2027 National - Balmoral')
        ->and($existing->fresh()->status)->toBe('open')
        ->and(MatchEvent::query()->where('name', 'PR22 2027 National - Sungiti')->count())->toBe(0)
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->count())->toBe(9);
});

it('moves the March, June, July, and August nationals onto the revised dates', function () {
    seedPr22CalendarProvinces();
    $owner = User::factory()->create();
    $provinces = Province::all()->keyBy('abbreviation');

    $rows = [
        ['PR22 2027 National - Klipskuur Warrior', 'GP', 'Klipskuur Warrior', 'Klipskuur', '2027-03-14'],
        ['PR22 2027 National - Gauteng', 'GP', null, 'Gauteng', '2027-06-13'],
        ['PR22 2027 National - Cape Town', 'WC', 'Cape Town', 'Cape Town', '2027-07-24'],
        ['PR22 2027 National - Rassie', 'LP', 'Rassie', 'Rassie', '2027-08-15'],
    ];

    $existing = collect($rows)->map(fn (array $row) => MatchEvent::create([
        'name' => $row[0],
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2027',
        'province_id' => $provinces->get($row[1])->id,
        'venue_name' => $row[2],
        'city' => $row[3],
        'match_date' => $row[4],
        'status' => 'closed',
        'created_by' => $owner->id,
        'match_director' => 'To be confirmed',
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'published' => true,
        'description' => 'Provisional 2027 PR22 calendar entry. Entries are not open. The date and venue may still change. Venue still to be confirmed.',
    ]));
    $julySlug = $existing[2]->slug;

    $this->artisan('pr22:seed-2027-events', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('would rename');

    expect($existing[0]->fresh()->match_date->toDateString())->toBe('2027-03-14');

    $this->artisan('pr22:seed-2027-events')->assertSuccessful();

    expect($existing[0]->fresh()->name)->toBe('PR22 2027 National - Rassie')
        ->and($existing[0]->fresh()->match_date->toDateString())->toBe('2027-03-20')
        ->and($existing[0]->fresh()->venue_name)->toBe('Rassie')
        ->and($existing[0]->fresh()->province->abbreviation)->toBe('LP')
        ->and($existing[1]->fresh()->name)->toBe('PR22 2027 National - Legends')
        ->and($existing[1]->fresh()->match_date->toDateString())->toBe('2027-06-19')
        ->and($existing[1]->fresh()->venue_name)->toBe('Legends Adventure Farm')
        ->and($existing[1]->fresh()->match_director)->toBe('Stelios Christofi')
        ->and($existing[1]->fresh()->description)->not->toContain('Venue still to be confirmed.')
        ->and($existing[2]->fresh()->name)->toBe('PR22 2027 National - Cape Town')
        ->and($existing[2]->fresh()->match_date->toDateString())->toBe('2027-07-31')
        ->and($existing[2]->fresh()->slug)->toBe($julySlug)
        ->and($existing[3]->fresh()->name)->toBe("PR22 2027 National - Leopard's Valley")
        ->and($existing[3]->fresh()->match_date->toDateString())->toBe('2027-08-21')
        ->and($existing[3]->fresh()->venue_name)->toBe("Leopard's Valley")
        ->and($existing[3]->fresh()->province->abbreviation)->toBe('GP')
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->count())->toBe(9)
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->whereDate('match_date', '2027-03-14')->exists())->toBeFalse()
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->whereDate('match_date', '2027-06-13')->exists())->toBeFalse()
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->whereDate('match_date', '2027-07-24')->exists())->toBeFalse()
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->whereDate('match_date', '2027-08-15')->exists())->toBeFalse();
});

it('leaves an opened 14 March Klipskuur national on its original date', function () {
    seedPr22CalendarProvinces();
    $owner = User::factory()->create();

    $existing = MatchEvent::create([
        'name' => 'PR22 2027 National - Klipskuur Warrior',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2027',
        'province_id' => Province::where('abbreviation', 'GP')->first()->id,
        'venue_name' => 'Klipskuur Warrior',
        'city' => 'Klipskuur',
        'match_date' => '2027-03-14',
        'status' => 'open',
        'created_by' => $owner->id,
        'match_director' => 'To be confirmed',
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'published' => true,
    ]);

    $this->artisan('pr22:seed-2027-events')->assertSuccessful();

    expect($existing->fresh()->name)->toBe('PR22 2027 National - Klipskuur Warrior')
        ->and($existing->fresh()->match_date->toDateString())->toBe('2027-03-14')
        ->and($existing->fresh()->status)->toBe('open')
        ->and(MatchEvent::query()->where('name', 'PR22 2027 National - Rassie')->whereDate('match_date', '2027-03-20')->count())->toBe(0)
        ->and(MatchEvent::query()->where('season', '2027')->where('match_type', 'PR22')->count())->toBe(9);
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
