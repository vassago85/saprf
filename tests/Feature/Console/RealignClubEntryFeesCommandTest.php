<?php

use App\Models\AccountCredit;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('member', 'web');
    $this->province = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    $this->director = User::factory()->create();

    foreach ([
        'club_entry_saprf_percent' => '3',
        'club_entry_platform_percent' => '3',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    app(SettingsService::class)->clearCache();

    $this->match = MatchEvent::create([
        'name' => 'WC Provincial Rimfire Champs',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subDay(),
        'status' => 'closed',
        'active_member_fee' => 600,
        'created_by' => $this->director->id,
    ]);
});

function payfastClubEntry(MatchEvent $match, User $shooter, array $overrides = []): MatchRegistration
{
    $registration = MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'membership_fee_category' => 'club_entry',
        'fee_amount' => 700,
        'surcharge_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 29.90,
        'md_net_amount' => 470.10,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $shooter->id,
        'amount' => (float) $registration->fee_amount,
        'm_payment_id' => 'PF-'.$registration->id,
        'status' => 'completed',
        'gateway' => 'payfast',
    ]);

    return $registration;
}

it('drops the surcharge and realigns the levies to 3% on a paid club-entry row', function () {
    $gert = User::factory()->create(['name' => 'Gert Coetzee']);
    $registration = payfastClubEntry($this->match, $gert);

    $this->artisan('registrations:realign-club-entry-fees', [
        'match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $registration->refresh();

    // Fee R700 - R100 surcharge = R600; SAPRF/Platform 3% of R600 = R18 each.
    expect((float) $registration->fee_amount)->toBe(600.0)
        ->and((float) $registration->surcharge_amount)->toBe(0.0)
        ->and((float) $registration->saprf_fee)->toBe(18.0)
        ->and((float) $registration->platform_fee)->toBe(18.0)
        ->and((float) $registration->md_net_amount)->toBe(534.10);

    // Two credits: R100 surcharge + R64 levy diff = R164 to the payer.
    $credits = AccountCredit::where('user_id', $gert->id)->get();
    expect(round((float) $credits->sum('amount'), 2))->toBe(164.0);
});

it('is a dry run by default and does not touch the row', function () {
    $dieter = User::factory()->create(['name' => 'Dieter Pagel']);
    $registration = payfastClubEntry($this->match, $dieter);

    $this->artisan('registrations:realign-club-entry-fees', [
        'match' => $this->match->id,
    ])->assertSuccessful();

    $registration->refresh();

    expect((float) $registration->fee_amount)->toBe(700.0)
        ->and((float) $registration->surcharge_amount)->toBe(100.0)
        ->and((float) $registration->saprf_fee)->toBe(50.0)
        ->and((float) $registration->platform_fee)->toBe(50.0);

    expect(AccountCredit::where('user_id', $dieter->id)->count())->toBe(0);
});

it('is idempotent — a second apply pass makes no changes', function () {
    $shooter = User::factory()->create();
    payfastClubEntry($this->match, $shooter);

    $this->artisan('registrations:realign-club-entry-fees', [
        'match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $creditsAfterFirst = AccountCredit::where('user_id', $shooter->id)->sum('amount');

    $this->artisan('registrations:realign-club-entry-fees', [
        'match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $creditsAfterSecond = AccountCredit::where('user_id', $shooter->id)->sum('amount');

    expect(round((float) $creditsAfterSecond, 2))->toBe(round((float) $creditsAfterFirst, 2));
});

it('only touches levies on a row that still has the surcharge removed earlier', function () {
    // Simulates a row the surcharge-credit command already handled: fee
    // dropped to R600, surcharge zeroed, but R50/R50 levies still sitting
    // on it from the old non-member split.
    $japie = User::factory()->create(['name' => 'Japie Louw']);
    $registration = payfastClubEntry($this->match, $japie, [
        'fee_amount' => 600,
        'surcharge_amount' => 0,
    ]);

    $this->artisan('registrations:realign-club-entry-fees', [
        'match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $registration->refresh();

    expect((float) $registration->saprf_fee)->toBe(18.0)
        ->and((float) $registration->platform_fee)->toBe(18.0);

    expect(round((float) AccountCredit::where('user_id', $japie->id)->sum('amount'), 2))->toBe(64.0);
});

it('leaves manual (non-PayFast) club-entry cash alone', function () {
    // Rennie-style row: paid, club_entry, no PayFast payment. The command
    // must not credit a payer who never gave the platform anything.
    $rennie = User::factory()->create(['name' => 'Rennie Brink']);
    $registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $rennie->id,
        'shooter_name' => $rennie->name,
        'membership_fee_category' => 'club_entry',
        'fee_amount' => 600,
        'surcharge_amount' => 0,
        'saprf_fee' => 0,
        'platform_fee' => 0,
        'gateway_fee' => 0,
        'md_net_amount' => 600,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->artisan('registrations:realign-club-entry-fees', [
        'match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $registration->refresh();

    expect((float) $registration->fee_amount)->toBe(600.0)
        ->and((float) $registration->md_net_amount)->toBe(600.0);

    expect(AccountCredit::where('user_id', $rennie->id)->count())->toBe(0);
});
