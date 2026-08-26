<?php

/**
 * Locks in the shape of the Impact-Scoring CSV export
 * (GET /matches/{match}/export-impact-scoring).
 *
 * Impact Scoring's importer creates one squad per unique value in the
 * Squad column — so if the exporter numbers shooters 1, 2, 3, … the MD
 * ends up with a squad-of-one for every shooter on import. The Squad
 * column must therefore be blank on every row (shooters land in the
 * unassigned pool and the MD squads them up inside Impact Scoring).
 *
 * Authorization for this route is covered by MatchDirectorAccessTest;
 * this file cares only about the CSV body.
 */

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DivisionCategorySeeder;

beforeEach(function () {
    seedRoles();
    $this->seed(DivisionCategorySeeder::class);

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Rimfire PR22 MP 2-Day National',
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

function seedImpactRegistration(MatchEvent $match, string $name, string $saprf, int $secondsOffset, ?string $divisionSlug = null): void
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug('.') . '@example.test',
        'phone' => '0821234567',
        'email_verified_at' => now(),
    ]);

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => $saprf,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(6),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);

    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'division_id' => $divisionSlug ? Division::where('slug', $divisionSlug)->value('id') : null,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->addSeconds($secondsOffset),
    ]);
}

it('emits an empty Squad column on every row so Impact Scoring does not create one squad per shooter', function () {
    seedImpactRegistration($this->match, 'Stelios Christofi', '972', 1);
    seedImpactRegistration($this->match, 'Justin Coverly', '1745', 2);
    seedImpactRegistration($this->match, 'Russell Ferreira', '474', 3);

    $response = $this->actingAs($this->md)
        ->get(route('matches.export-impact-scoring', $this->match))
        ->assertOk();

    $csv = $response->streamedContent();
    $rows = array_values(array_filter(preg_split("/\r?\n/", trim($csv))));

    // Header + three shooters.
    expect($rows)->toHaveCount(4);
    expect($rows[0])->toBe('Email,Name,Phone,Squad,Division,"Member Number"');

    // Every data row's Squad column (index 3) must be blank. If someone
    // reintroduces a per-shooter counter, one of these will read "1", "2",
    // or "3" and the assertion fails.
    foreach (array_slice($rows, 1) as $row) {
        $cols = str_getcsv($row);
        expect($cols)->toHaveCount(6);
        expect($cols[3])->toBe('');
    }
});

it('carries each shooter\'s division into the Division column (blank when the registration has no division yet)', function () {
    // First shooter: registered under the Open division.
    // Second shooter: no division_id set on the registration yet.
    // Old bug: the exporter hard-coded the match's series (PR22/PRS) into
    //          the Division column for every row.
    seedImpactRegistration($this->match, 'Open Shooter', '900', 1, 'open');
    seedImpactRegistration($this->match, 'Undecided Shooter', '901', 2);

    $response = $this->actingAs($this->md)
        ->get(route('matches.export-impact-scoring', $this->match))
        ->assertOk();

    $rows = array_values(array_filter(preg_split("/\r?\n/", trim($response->streamedContent()))));
    $byName = [];
    foreach (array_slice($rows, 1) as $row) {
        $cols = str_getcsv($row);
        $byName[$cols[1]] = $cols[4];
    }

    expect($byName['Open Shooter'])->toBe('Open');
    expect($byName['Undecided Shooter'])->toBe('');

    // Belt & braces: no row still leaks the match series into the column.
    expect($byName)->not->toContain('PR22');
});

it('excludes cancelled registrations from the export', function () {
    seedImpactRegistration($this->match, 'Active Shooter', '100', 1);
    seedImpactRegistration($this->match, 'Cancelled Shooter', '200', 2);

    MatchRegistration::where('shooter_name', 'Cancelled Shooter')
        ->update(['registration_status' => 'cancelled']);

    $response = $this->actingAs($this->md)
        ->get(route('matches.export-impact-scoring', $this->match))
        ->assertOk();

    $csv = $response->streamedContent();
    expect($csv)->toContain('Active Shooter')
        ->not->toContain('Cancelled Shooter');
});
