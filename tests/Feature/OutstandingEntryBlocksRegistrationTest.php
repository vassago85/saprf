<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\View\Components\EventRegistrationCta;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->division = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->makeMatch = function (string $name, ?Carbon $date = null) use ($province): MatchEvent {
        $match = MatchEvent::create([
            'name' => $name,
            'match_type' => 'PRS',
            'series_level' => 'national',
            'series' => 'PRS',
            'season' => '2026',
            'province_id' => $province->id,
            'match_date' => $date ?? Carbon::today()->addMonths(2),
            'status' => 'open',
            'published' => true,
            'match_director' => 'Test Director',
            'active_member_fee' => 500.00,
            'non_member_fee' => 750.00,
            'lapsed_member_fee' => 650.00,
            'created_by' => $this->md->id,
        ]);
        $match->divisions()->syncWithoutDetaching([$this->division->id]);

        return $match;
    };

    foreach (['non_member_surcharge' => '0', 'lapsed_member_surcharge' => '0'] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    app(SettingsService::class)->clearCache();
});

function pendingFeeEntry(MatchEvent $match, User $shooter, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 650.00,
        'saprf_fee' => 50.00,
        'platform_fee' => 50.00,
        'surcharge_amount' => 0,
        'gateway_fee' => 0,
        'md_net_amount' => -100.00,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subWeek(),
    ], $overrides));
}

it('blocks the register form when the shooter has an unpaid entry for a match that has started', function () {
    $owed = ($this->makeMatch)('Owed Match', Carbon::yesterday());
    $next = ($this->makeMatch)('Next Match');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    $outstanding = pendingFeeEntry($owed, $shooter);

    $this->actingAs($shooter)
        ->get(url('/events/'.$next->id.'/register'))
        ->assertRedirect(route('registrations.show', $outstanding))
        ->assertSessionHas('error');
});

it('blocks posting a new entry while an unpaid fee remains on a match that has started', function () {
    $owed = ($this->makeMatch)('Owed Match', Carbon::yesterday());
    $next = ($this->makeMatch)('Next Match');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    $outstanding = pendingFeeEntry($owed, $shooter);

    $this->actingAs($shooter)
        ->post(route('events.register.store', $next), ['division_id' => $this->division->id])
        ->assertRedirect(route('registrations.show', $outstanding))
        ->assertSessionHas('error');

    expect(MatchRegistration::where('match_id', $next->id)->where('user_id', $shooter->id)->exists())->toBeFalse();
});

it('allows a new entry after the outstanding fee is paid', function () {
    $owed = ($this->makeMatch)('Owed Match');
    $next = ($this->makeMatch)('Next Match');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    pendingFeeEntry($owed, $shooter, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $this->actingAs($shooter)
        ->get(url('/events/'.$next->id.'/register'))
        ->assertOk();
});

it('points the event CTA at the unpaid entry instead of register', function () {
    $owed = ($this->makeMatch)('Owed Match', Carbon::yesterday());
    $next = ($this->makeMatch)('Next Match');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    $outstanding = pendingFeeEntry($owed, $shooter);

    Auth::login($shooter);
    $cta = new EventRegistrationCta($next);

    expect($cta->label)->toBe('Pay Outstanding Entry')
        ->and($cta->url)->toBe(route('registrations.show', $outstanding))
        ->and($cta->disabled)->toBeFalse();
});

it('does not block a new entry when the unpaid match is still in the future', function () {
    $owed = ($this->makeMatch)('Future Owed Match');
    $next = ($this->makeMatch)('Next Match', Carbon::today()->addMonths(3));

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    pendingFeeEntry($owed, $shooter);

    expect(MatchRegistration::firstOutstandingForUser($shooter->id))->toBeNull();

    $this->actingAs($shooter)
        ->get(url('/events/'.$next->id.'/register'))
        ->assertOk();

    Auth::login($shooter);
    $cta = new EventRegistrationCta($next);

    expect($cta->label)->toBe('Register Now');
});

it('does not treat a waived or free entry as outstanding', function () {
    $owed = ($this->makeMatch)('Waived Match');
    $next = ($this->makeMatch)('Next Match');

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    pendingFeeEntry($owed, $shooter, ['payment_status' => 'waived']);

    expect(MatchRegistration::firstOutstandingForUser($shooter->id))->toBeNull();

    $this->actingAs($shooter)
        ->get(url('/events/'.$next->id.'/register'))
        ->assertOk();
});
