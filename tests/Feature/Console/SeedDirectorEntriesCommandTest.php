<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
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
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => '50',
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

function squadMatch(): MatchEvent
{
    return MatchEvent::create([
        'name' => 'WC Provincial Rimfire Champs',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => test()->province->id,
        'match_date' => now()->addDays(2),
        'status' => 'open',
        'active_member_fee' => 600,
        'created_by' => test()->director->id,
    ]);
}

function squadCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'squad');
    file_put_contents($path, "name,email\n".$body);

    return $path;
}

it('previews club entries without writing', function () {
    $match = squadMatch();
    $path = squadCsv("Chanel van Vuuren,chanel.vanv@gmail.com\n");

    $this->artisan('registrations:seed-director-entries', [
        'match' => $match->id,
        'file' => $path,
    ])->assertSuccessful()
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Would add: 1');

    expect(MatchRegistration::query()->where('match_id', $match->id)->count())->toBe(0)
        ->and(User::query()->where('email', 'chanel.vanv@gmail.com')->exists())->toBeFalse();
});

it('seeds a new club shooter as director-collected with no surcharge', function () {
    $match = squadMatch();
    $path = squadCsv("Ricus Visser,rvisser001@gmail.com\n");

    $this->artisan('registrations:seed-director-entries', [
        'match' => $match->id,
        'file' => $path,
        '--apply' => true,
    ])->assertSuccessful();

    $entry = MatchRegistration::query()->where('match_id', $match->id)->first();

    expect($entry->shooter_name)->toBe('Ricus Visser')
        ->and($entry->membership_fee_category)->toBe('club_entry')
        ->and((float) $entry->fee_amount)->toBe(600.0)
        ->and((float) $entry->surcharge_amount)->toBe(0.0)
        ->and((float) $entry->saprf_fee)->toBe(0.0)
        ->and((float) $entry->platform_fee)->toBe(0.0)
        ->and((float) $entry->gateway_fee)->toBe(0.0)
        ->and((float) $entry->md_net_amount)->toBe(600.0)
        ->and($entry->payment_status)->toBe('paid')
        ->and($entry->registration_status)->toBe('confirmed');
});

it('leaves shooters who are already on the match unchanged', function () {
    $match = squadMatch();
    $japie = User::factory()->create(['name' => 'Japie Louw']);
    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $japie->id,
        'shooter_name' => 'Japie Louw',
        'email' => $japie->email,
        'membership_fee_category' => 'non_member',
        'fee_amount' => 550,
        'surcharge_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 21.28,
        'md_net_amount' => 328.72,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $path = squadCsv("Japie Louw Jnr,\n");

    $this->artisan('registrations:seed-director-entries', [
        'match' => $match->id,
        'file' => $path,
        '--apply' => true,
    ])->assertSuccessful()->expectsOutputToContain('already entered');

    expect(MatchRegistration::query()->where('match_id', $match->id)->count())->toBe(1)
        ->and($japie->fresh()->name)->toBe('Japie Louw')
        ->and((float) MatchRegistration::query()->first()->fee_amount)->toBe(550.0)
        ->and(MatchRegistration::query()->first()->membership_fee_category)->toBe('non_member');
});

it('does not attach a shooter to someone else who owns that email', function () {
    $match = squadMatch();
    $johan = User::factory()->create([
        'name' => 'Johan van Zyl',
        'email' => 'johan.vanzyl@risk-x.co.za',
    ]);
    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $johan->id,
        'shooter_name' => 'Johan Van Zyl',
        'email' => $johan->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 600,
        'surcharge_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => 500,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $path = squadCsv("Lea van Zyl,johan.vanzyl@risk-x.co.za\n");

    $this->artisan('registrations:seed-director-entries', [
        'match' => $match->id,
        'file' => $path,
        '--apply' => true,
    ])->assertSuccessful()->expectsOutputToContain('email belongs to Johan van Zyl');

    $lea = MatchRegistration::query()->where('shooter_name', 'Lea van Zyl')->first();

    expect($lea)->not->toBeNull()
        ->and($lea->user_id)->not->toBe($johan->id)
        ->and($lea->membership_fee_category)->toBe('club_entry')
        ->and((float) $johan->matchRegistrations()->first()->fee_amount)->toBe(600.0);
});

it('prices an existing active member on the member bracket', function () {
    $match = squadMatch();
    $paul = User::factory()->create([
        'name' => 'Paul Coetzee',
        'email' => 'paulatlegal@gmail.com',
    ]);
    giveActiveMembership($paul);

    $path = squadCsv("Paul Coetzee,paulatlegal@gmail.com\n");

    $this->artisan('registrations:seed-director-entries', [
        'match' => $match->id,
        'file' => $path,
        '--apply' => true,
    ])->assertSuccessful();

    $entry = MatchRegistration::query()->where('user_id', $paul->id)->first();

    expect($entry->membership_fee_category)->toBe('active_member')
        ->and((float) $entry->fee_amount)->toBe(600.0)
        ->and((float) $entry->surcharge_amount)->toBe(0.0)
        ->and((float) $entry->saprf_fee)->toBe(50.0)
        ->and((float) $entry->platform_fee)->toBe(50.0)
        ->and((float) $entry->gateway_fee)->toBe(0.0)
        ->and((float) $entry->md_net_amount)->toBe(500.0);
});
