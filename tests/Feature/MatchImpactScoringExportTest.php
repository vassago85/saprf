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
        'email' => str($name)->slug('.').'@example.test',
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
    expect($rows[0])->toBe('Email,Name,Phone,Squad,Division,Categories,"Member Number"');

    // Every data row's Squad column (index 3) must be blank. If someone
    // reintroduces a per-shooter counter, one of these will read "1", "2",
    // or "3" and the assertion fails.
    foreach (array_slice($rows, 1) as $row) {
        $cols = str_getcsv($row);
        expect($cols)->toHaveCount(7);
        expect($cols[3])->toBe('');
    }
});

it('puts equipment classes in Division and demographic classes in Categories', function () {
    // Impact's shooter CSV treats Open/Factory/Limited/Production as
    // divisions, and Lady/Junior/Senior as categories. SAPRF stores both
    // in one division field. The old export wrote "Ladies" into Division,
    // which does not match a division on a SAPRF Impact match.
    seedImpactRegistration($this->match, 'Open Shooter', '900', 1, 'open');
    seedImpactRegistration($this->match, 'Ladies Shooter', '901', 2, 'ladies');
    seedImpactRegistration($this->match, 'Senior Shooter', '902', 3, 'senior');
    seedImpactRegistration($this->match, 'Undecided Shooter', '903', 4);

    $response = $this->actingAs($this->md)
        ->get(route('matches.export-impact-scoring', $this->match))
        ->assertOk();

    $rows = array_values(array_filter(preg_split("/\r?\n/", trim($response->streamedContent()))));
    $byName = [];
    foreach (array_slice($rows, 1) as $row) {
        $cols = str_getcsv($row);
        $byName[$cols[1]] = ['division' => $cols[4], 'categories' => $cols[5]];
    }

    expect($byName['Open Shooter'])->toBe(['division' => 'Open', 'categories' => '']);
    expect($byName['Ladies Shooter'])->toBe(['division' => '', 'categories' => 'Lady']);
    expect($byName['Senior Shooter'])->toBe(['division' => '', 'categories' => 'Senior']);
    expect($byName['Undecided Shooter'])->toBe(['division' => '', 'categories' => '']);

    expect(collect($byName)->pluck('division')->all())->not->toContain('PR22');
});

it('drops rows Impact would reject: blank emails and a second row with the same email', function () {
    seedImpactRegistration($this->match, 'First Shooter', '910', 1, 'open');
    seedImpactRegistration($this->match, 'Duplicate Shooter', '911', 2, 'factory');

    MatchRegistration::where('shooter_name', 'Duplicate Shooter')
        ->update(['email' => 'first.shooter@example.test']);

    $noEmail = User::factory()->create([
        'name' => 'No Email Shooter',
        'email' => 'no.email.shooter@example.test',
        'email_verified_at' => now(),
    ]);
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $noEmail->id,
        'shooter_name' => 'No Email Shooter',
        'email' => '',
        'phone' => '0820000000',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->addSeconds(3),
    ]);
    $noEmail->update(['email' => '']);

    $response = $this->actingAs($this->md)
        ->get(route('matches.export-impact-scoring', $this->match))
        ->assertOk();

    $csv = $response->streamedContent();
    expect($csv)->toContain('First Shooter')
        ->not->toContain('Duplicate Shooter')
        ->not->toContain('No Email Shooter');
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
