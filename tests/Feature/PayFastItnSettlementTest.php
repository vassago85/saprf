<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->match = MatchEvent::create([
        'name' => 'Settlement Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 1100.00,
        'non_member_fee' => 1300.00,
        'lapsed_member_fee' => 1200.00,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    $this->member->assignRole('member');
});

function signedItn(array $data): array
{
    $data['signature'] = app(PayFastService::class)->generateItnSignature($data);

    return $data;
}

function postItn(array $data)
{
    return test()->post(route('payments.notify'), signedItn($data));
}

it('replaces the card-rate gateway estimate with the actual PayFast fee', function () {
    $registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->member->id,
        'shooter_name' => $this->member->name,
        'email' => $this->member->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1100.00,
        'surcharge_amount' => 0,
        'saprf_fee' => 50.00,
        'platform_fee' => 0,
        'gateway_fee' => 40.50,
        'md_net_amount' => 1009.50,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-SETTLE-1',
        'status' => 'pending',
    ]);

    $response = postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '1089250',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Match Registration',
        'amount_gross' => '1100.00',
        'amount_fee' => '-23.50',
        'amount_net' => '1076.50',
        'merchant_id' => config('payfast.merchant_id'),
    ]);

    $response->assertOk()->assertSee('OK');

    $payment->refresh();
    $registration->refresh();

    expect((float) $payment->amount_gross)->toBe(1100.00)
        ->and((float) $payment->amount_fee)->toBe(23.50)
        ->and((float) $payment->amount_net)->toBe(1076.50)
        ->and((float) $registration->gateway_fee)->toBe(23.50)
        ->and((float) $registration->md_net_amount)->toBe(1026.50)
        ->and($registration->payment_status)->toBe('paid')
        ->and($registration->registration_status)->toBe('confirmed');
});

it('leaves the estimate in place when PayFast omits the fee', function () {
    $registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->member->id,
        'shooter_name' => $this->member->name,
        'email' => $this->member->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1100.00,
        'surcharge_amount' => 0,
        'saprf_fee' => 50.00,
        'platform_fee' => 0,
        'gateway_fee' => 40.50,
        'md_net_amount' => 1009.50,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-SETTLE-2',
        'status' => 'pending',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '1089251',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Match Registration',
        'amount_gross' => '1100.00',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertOk();

    $registration->refresh();

    expect((float) $registration->gateway_fee)->toBe(40.50)
        ->and((float) $registration->md_net_amount)->toBe(1009.50)
        ->and($registration->payment_status)->toBe('paid');
});

it('stores the actual PayFast fee on a membership payment', function () {
    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'SAPRF-SETTLE-1',
        'membership_type' => 'paid',
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $payment = Payment::create([
        'payable_type' => Membership::class,
        'payable_id' => $membership->id,
        'user_id' => $this->member->id,
        'amount' => 500.00,
        'm_payment_id' => 'MEM-SETTLE-1',
        'status' => 'pending',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '1089252',
        'payment_status' => 'COMPLETE',
        'item_name' => 'SAPRF Membership',
        'amount_gross' => '500.00',
        'amount_fee' => '-11.00',
        'amount_net' => '489.00',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertOk();

    $membershipPayment = MembershipPayment::where('payment_reference', 'MEM-SETTLE-1')->first();

    expect($membershipPayment)->not->toBeNull()
        ->and((float) $membershipPayment->gateway_fee)->toBe(11.00)
        ->and($membership->fresh()->status)->toBe('active');
});

it('uses stored membership gateway fees instead of the card-rate estimate', function () {
    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'SAPRF-SETTLE-2',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    MembershipPayment::create([
        'membership_id' => $membership->id,
        'amount' => 500.00,
        'gateway_fee' => 11.00,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'payfast',
        'status' => 'confirmed',
    ]);

    app(SettingsService::class)->clearCache();

    $summary = app(FinancialService::class)->platformSummary();

    expect($summary['membership_revenue']['gateway_fees'])->toBe(11.00)
        ->and($summary['membership_revenue']['net_to_saprf'])->toBe(489.00);
});
