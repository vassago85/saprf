<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;

beforeEach(function () {
    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->province = Province::where('abbreviation', 'GP')->first();
    $this->director = User::factory()->create();

    foreach ([
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'platform_fee_type' => 'percentage',
        'platform_fee_value' => '5',
        'club_entry_saprf_percent' => '3',
        'club_entry_platform_percent' => '3',
        'estimated_gateway_fee_percentage' => '3.5',
        'estimated_gateway_flat_fee' => '2',
        'non_member_surcharge' => '100',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    app(SettingsService::class)->clearCache();
});

function clubMatchForBackfill(array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Existing Club Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => test()->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 300.00,
        'club_entry_fee' => 200.00,
        'created_by' => test()->director->id,
    ], $overrides));
}

function nonMemberEntry(MatchEvent $match, array $overrides = []): MatchRegistration
{
    $shooter = User::factory()->create();

    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'non_member',
        'fee_amount' => 400.00,
        'surcharge_amount' => 100.00,
        'saprf_fee' => 50.00,
        'platform_fee' => 15.00,
        'gateway_fee' => 16.00,
        'md_net_amount' => 219.00,
        'payment_status' => 'unpaid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));
}

it('previews unpaid club-match non-members without writing', function () {
    $match = clubMatchForBackfill();
    $entry = nonMemberEntry($match);

    $this->artisan('registrations:apply-club-entry')
        ->assertSuccessful()
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('would update');

    expect($entry->fresh()->membership_fee_category)->toBe('non_member')
        ->and((float) $entry->fresh()->fee_amount)->toBe(400.00);
});

it('moves unpaid non-members on club and provincial matches onto the club fee', function () {
    $club = clubMatchForBackfill();
    $provincial = clubMatchForBackfill([
        'name' => 'Existing Provincial',
        'series_level' => 'provincial',
        'club_entry_fee' => null,
    ]);
    $national = clubMatchForBackfill([
        'name' => 'National stays put',
        'series_level' => 'national',
    ]);

    $clubEntry = nonMemberEntry($club);
    $provincialEntry = nonMemberEntry($provincial);
    $paid = nonMemberEntry($club, ['payment_status' => 'paid', 'fee_amount' => 400.00]);
    $nationalEntry = nonMemberEntry($national);
    $walkIn = nonMemberEntry($club, ['registration_source' => 'walk_in', 'fee_amount' => 0]);

    $this->artisan('registrations:apply-club-entry', ['--apply' => true])
        ->assertSuccessful();

    expect($clubEntry->fresh()->membership_fee_category)->toBe('club_entry')
        ->and((float) $clubEntry->fresh()->fee_amount)->toBe(200.00)
        ->and((float) $clubEntry->fresh()->surcharge_amount)->toBe(0.0)
        ->and((float) $clubEntry->fresh()->saprf_fee)->toBe(6.00);

    // Blank club fee falls back to the member fee.
    expect($provincialEntry->fresh()->membership_fee_category)->toBe('club_entry')
        ->and((float) $provincialEntry->fresh()->fee_amount)->toBe(300.00);

    expect($paid->fresh()->membership_fee_category)->toBe('non_member')
        ->and((float) $paid->fresh()->fee_amount)->toBe(400.00);

    expect($nationalEntry->fresh()->membership_fee_category)->toBe('non_member');
    expect($walkIn->fresh()->membership_fee_category)->toBe('non_member');
});

it('can limit the backfill to one match', function () {
    $keep = clubMatchForBackfill(['name' => 'Leave this one']);
    $change = clubMatchForBackfill(['name' => 'Change this one']);
    $untouched = nonMemberEntry($keep);
    $targeted = nonMemberEntry($change);

    $this->artisan('registrations:apply-club-entry', [
        '--match' => $change->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect($untouched->fresh()->membership_fee_category)->toBe('non_member')
        ->and($targeted->fresh()->membership_fee_category)->toBe('club_entry')
        ->and((float) $targeted->fresh()->fee_amount)->toBe(200.00);
});
