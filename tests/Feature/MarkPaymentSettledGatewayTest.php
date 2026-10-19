<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['code' => 'GP'], ['name' => 'Gauteng', 'abbreviation' => 'GP']);
    Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->shooter = User::factory()->create(['email_verified_at' => now()]);

    $this->match = MatchEvent::create([
        'name' => 'Settlement Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subWeek(),
        'status' => 'completed',
        'published' => true,
        'match_director' => $this->md->name,
        'active_member_fee' => 500.00,
        'non_member_fee' => 650.00,
        'lapsed_member_fee' => 500.00,
        'created_by' => $this->md->id,
    ]);
});

/**
 * Build an unpaid online registration that LOOKS like it came through the
 * normal shooter-registers flow: a non-zero gateway_fee was booked at
 * register-time because the fee list assumes PayFast will take its cut.
 */
function makeOnlineUnpaidRegistration(MatchEvent $match, User $shooter, float $fee = 500.0, float $gatewayFee = 19.50): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => $fee,
        'surcharge_amount' => 0,
        'saprf_fee' => 50.0,
        'platform_fee' => 50.0,
        'gateway_fee' => $gatewayFee,
        'md_net_amount' => round($fee - 50 - 50 - $gatewayFee, 2),
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);
}

it('zeroes the gateway fee and credits it back to the MD on POP settlement', function () {
    $reg = makeOnlineUnpaidRegistration($this->match, $this->shooter);

    expect((float) $reg->gateway_fee)->toBe(19.50)
        ->and((float) $reg->md_net_amount)->toBe(380.50);

    $this->actingAs($this->md)
        ->post(route('registrations.mark-settled', $reg), [
            'settlement_proof' => 'pop',
            'settlement_note' => 'EFT received 19 Oct — standard bank',
        ])
        ->assertRedirect();

    $reg->refresh();

    expect($reg->payment_status)->toBe('paid')
        ->and((float) $reg->gateway_fee)->toBe(0.0)
        // Full fee minus saprf (50) and platform (50) only — gateway refunded.
        ->and((float) $reg->md_net_amount)->toBe(400.00);
});

it('zeroes the gateway fee on "other" (cash) settlement too', function () {
    $reg = makeOnlineUnpaidRegistration($this->match, $this->shooter);

    $this->actingAs($this->md)
        ->post(route('registrations.mark-settled', $reg), [
            'settlement_proof' => 'other',
            'settlement_note' => 'Paid cash on the range',
        ])
        ->assertRedirect();

    $reg->refresh();

    expect((float) $reg->gateway_fee)->toBe(0.0)
        ->and((float) $reg->md_net_amount)->toBe(400.00);
});

it('keeps the gateway fee intact on payfast-screenshot settlement', function () {
    // Money actually went through PayFast — just no ITN reached us. So the
    // gateway slice is real and stays booked.
    $reg = makeOnlineUnpaidRegistration($this->match, $this->shooter);

    $this->actingAs($this->md)
        ->post(route('registrations.mark-settled', $reg), [
            'settlement_proof' => 'payfast_screenshot',
            'settlement_note' => 'Shooter emailed PayFast confirmation PDF',
        ])
        ->assertRedirect();

    $reg->refresh();

    expect($reg->payment_status)->toBe('paid')
        ->and((float) $reg->gateway_fee)->toBe(19.50)
        ->and((float) $reg->md_net_amount)->toBe(380.50);
});

it('leaves the row untouched when the gateway fee was already zero (walk-in or admin-added)', function () {
    // Admin-added entries already carry gateway_fee=0, so there's nothing
    // to refund. Just make sure we don't double-count or bump md_net.
    $reg = makeOnlineUnpaidRegistration($this->match, $this->shooter, 500.0, 0.0);

    $originalNet = (float) $reg->md_net_amount;

    $this->actingAs($this->md)
        ->post(route('registrations.mark-settled', $reg), [
            'settlement_proof' => 'pop',
            'settlement_note' => 'POP received',
        ])
        ->assertRedirect();

    $reg->refresh();

    expect((float) $reg->gateway_fee)->toBe(0.0)
        ->and((float) $reg->md_net_amount)->toBe($originalNet);
});
