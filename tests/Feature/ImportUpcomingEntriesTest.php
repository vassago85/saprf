<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use App\Services\UpcomingEntriesImporter;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    foreach (['open' => 'Open', 'factory' => 'Factory', 'junior' => 'Junior'] as $slug => $name) {
        Division::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
    }

    // The match starts owned by an admin; the MD phase should re-own it.
    $this->owner = User::factory()->create(['name' => 'Site Admin']);
    $this->owner->assignRole('admin');

    // An existing platform match the sheet should resolve to by name + date.
    $this->match = MatchEvent::create([
        'name' => 'Rimfire PR22 GP Provincial',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => '2026-08-15',
        'status' => 'open',
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'published' => true,
        'created_by' => $this->owner->id,
    ]);

    // Existing entrant (matched by SAPRF number).
    $this->russell = User::factory()->create(['name' => 'Russell Ferreira']);
    Membership::create([
        'user_id' => $this->russell->id,
        'saprf_number' => '474',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subMonths(2),
        'expiry_date' => now()->addYear(),
    ]);

    // Existing user who is the match director (resolved by name).
    $this->clive = User::factory()->create(['name' => 'Clive Mey']);

    $this->dataset = [
        'matches' => [[
            'old_event_id' => 229,
            'name' => 'Rimfire PR22 GP Provincial',
            'match_type' => 'PR22',
            'series_level' => 'provincial',
            'league' => 'SAPRF PR22 Provincial Series 2026',
            'match_date' => '2026-08-15',
            'match_end_date' => null,
            'venue' => 'Hippo Creek',
            'province' => 'Gauteng',
            'match_director' => 'Clive Mey',
            'match_director_contact' => 'Clive Mey +27 83 701 4859',
            'entry_fee' => 550,
            'junior_fee' => 350,
            'event_url' => '',
        ]],
        'entrants' => [
            [
                'old_event_id' => 229, 'saprf_number' => '474', 'name' => 'Russell Ferreira',
                'division' => 'factory', 'membership_type' => 'full', 'fee' => 550,
            ],
            [
                'old_event_id' => 229, 'saprf_number' => '9999', 'name' => 'New Person',
                'division' => 'open', 'membership_type' => 'full', 'fee' => 550,
            ],
            [
                'old_event_id' => 229, 'saprf_number' => '8888', 'name' => 'Young Gun',
                'division' => 'junior', 'membership_type' => 'full', 'fee' => 350,
            ],
        ],
    ];

    $this->phases = ['fees' => true, 'md' => true, 'registrations' => true];
});

function runImport(array $dataset, array $phases): array
{
    return app(UpcomingEntriesImporter::class)->run($dataset, ['matches' => [], 'directors' => []], $phases);
}

it('resolves the match by name and date', function () {
    $report = runImport($this->dataset, $this->phases);

    expect($report['matches'][0]['status'])->toBe('matched')
        ->and($report['matches'][0]['match_id'])->toBe($this->match->id);
});

it('sets the match entry fee from the sheet', function () {
    runImport($this->dataset, $this->phases);

    expect((float) $this->match->refresh()->active_member_fee)->toBe(550.0);
});

it('assigns the match director role and ownership', function () {
    runImport($this->dataset, $this->phases);

    expect($this->clive->fresh()->hasRole('match_director'))->toBeTrue()
        ->and($this->match->refresh()->created_by)->toBe($this->clive->id);
});

it('creates a stub user + membership for a missing entrant', function () {
    runImport($this->dataset, $this->phases);

    $stub = User::where('email', 'saprf-9999@import.saprf.local')->first();

    expect($stub)->not->toBeNull()
        ->and($stub->name)->toBe('New Person')
        ->and($stub->membership?->saprf_number)->toBe('9999')
        ->and($stub->membership?->membership_type)->toBe('paid');
});

it('creates confirmed + paid registrations for every entrant', function () {
    $report = runImport($this->dataset, $this->phases);

    expect($report['registrations']['created'])->toBe(3)
        ->and(MatchRegistration::count())->toBe(3);

    $reg = MatchRegistration::where('user_id', $this->russell->id)->first();
    expect($reg->registration_status)->toBe('confirmed')
        ->and($reg->payment_status)->toBe('paid')
        ->and((float) $reg->fee_amount)->toBe(550.0);
});

it('sets the junior fee on the match and charges junior entrants the junior fee', function () {
    runImport($this->dataset, $this->phases);

    expect((float) $this->match->refresh()->junior_fee)->toBe(350.0);

    $junior = MatchRegistration::whereHas('user', fn ($q) => $q->where('name', 'Young Gun'))->first();
    expect((float) $junior->fee_amount)->toBe(350.0);
});

it('is idempotent — a second run creates no new registrations', function () {
    runImport($this->dataset, $this->phases);
    $report = runImport($this->dataset, $this->phases);

    expect($report['registrations']['created'])->toBe(0)
        ->and($report['registrations']['skipped_existing'])->toBe(3)
        ->and(MatchRegistration::count())->toBe(3);
});

it('resolves a director override by SAPRF number or email', function () {
    $hendrie = User::factory()->create(['name' => 'Hendrie Brink', 'email' => 'hendriebrink@gmail.com']);
    Membership::create([
        'user_id' => $hendrie->id,
        'saprf_number' => '381',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'unpaid',
    ]);

    $importer = app(UpcomingEntriesImporter::class);
    $mdOnly = ['fees' => false, 'md' => true, 'registrations' => false];

    $importer->run($this->dataset, ['matches' => [], 'directors' => ['229' => 'saprf:381']], $mdOnly);
    expect($this->match->refresh()->created_by)->toBe($hendrie->id);

    $this->match->update(['created_by' => $this->owner->id]);

    $importer->run($this->dataset, ['matches' => [], 'directors' => ['229' => 'hendriebrink@gmail.com']], $mdOnly);
    expect($this->match->refresh()->created_by)->toBe($hendrie->id);
});

it('only runs the requested phase', function () {
    $report = runImport($this->dataset, ['fees' => true, 'md' => false, 'registrations' => false]);

    expect((float) $this->match->refresh()->active_member_fee)->toBe(550.0)
        ->and($this->match->refresh()->created_by)->toBe($this->owner->id)
        ->and($this->clive->fresh()->hasRole('match_director'))->toBeFalse()
        ->and(MatchRegistration::count())->toBe(0)
        ->and($report['directors'])->toBe([]);
});
