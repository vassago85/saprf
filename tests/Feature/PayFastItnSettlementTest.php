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

it('rejects a signed ITN whose amount does not match the pending payment', function () {
    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => MatchRegistration::create([
            'match_id' => $this->match->id,
            'user_id' => $this->member->id,
            'shooter_name' => $this->member->name,
            'email' => $this->member->email,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 1100.00,
            'surcharge_amount' => 0,
            'saprf_fee' => 50.00,
            'platform_fee' => 0,
            'gateway_fee' => 0,
            'md_net_amount' => 1050.00,
            'payment_status' => 'unpaid',
            'registration_status' => 'pending',
            'registered_at' => now(),
        ])->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-AMOUNT-MISMATCH',
        'status' => 'pending',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '1089299',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Match Registration',
        'amount_gross' => '1.00',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertStatus(400);

    expect($payment->fresh()->status)->toBe('pending');
});

it('does not accept an unsigned ITN just because sandbox mode is on outside local', function () {
    config(['payfast.sandbox' => true]);

    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => MatchRegistration::create([
            'match_id' => $this->match->id,
            'user_id' => $this->member->id,
            'shooter_name' => $this->member->name,
            'email' => $this->member->email,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 1100.00,
            'surcharge_amount' => 0,
            'saprf_fee' => 0,
            'platform_fee' => 0,
            'gateway_fee' => 0,
            'md_net_amount' => 1100.00,
            'payment_status' => 'unpaid',
            'registration_status' => 'pending',
            'registered_at' => now(),
        ])->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-UNSIGNED',
        'status' => 'pending',
    ]);

    $this->post(route('payments.notify'), [
        'm_payment_id' => $payment->m_payment_id,
        'payment_status' => 'COMPLETE',
        'amount_gross' => '1100.00',
    ])->assertStatus(400);

    expect($payment->fresh()->status)->toBe('pending');
});

/*
 * ─── Late / delayed ITN on an already-cancelled payment ────────────────
 *
 * A Payment row can flip to `cancelled` on our side before PayFast posts
 * the final ITN — the member hit the cancel URL mid-checkout, or
 * RegistrationPricingService dropped the pending row to reprice an unpaid
 * entry while the shooter was still paying. If PayFast actually collected
 * the money, the subsequent COMPLETE ITN is authoritative: we have to
 * re-open the Payment and activate the membership / registration,
 * otherwise PayFast has the funds and our system shows unpaid.
 *
 * The signature is still validated above these branches, so a replay or
 * stray ITN cannot force a reopen on its own.
 */

it('re-opens a cancelled membership payment when a late signed COMPLETE ITN arrives', function () {
    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'SAPRF-LATE-1',
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
        'm_payment_id' => 'MEM-LATE-1',
        'status' => 'cancelled',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '2099991',
        'payment_status' => 'COMPLETE',
        'item_name' => 'SAPRF Membership',
        'amount_gross' => '500.00',
        'amount_fee' => '-11.00',
        'amount_net' => '489.00',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertOk();

    $payment->refresh();
    $membership->refresh();

    expect($payment->status)->toBe('completed')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->gateway_response['reopened_from_cancelled'] ?? false)->toBeTrue()
        ->and($membership->status)->toBe('active')
        ->and($membership->payment_status)->toBe('paid');

    $membershipPayment = MembershipPayment::where('payment_reference', 'MEM-LATE-1')->first();
    expect($membershipPayment)->not->toBeNull()
        ->and($membershipPayment->status)->toBe('confirmed');
});

it('leaves a cancelled payment alone when the ITN is not COMPLETE', function () {
    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'SAPRF-LATE-2',
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
        'm_payment_id' => 'MEM-LATE-2',
        'status' => 'cancelled',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '2099992',
        'payment_status' => 'CANCELLED',
        'item_name' => 'SAPRF Membership',
        'amount_gross' => '500.00',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertOk();

    expect($payment->fresh()->status)->toBe('cancelled')
        ->and($membership->fresh()->payment_status)->toBe('unpaid');
});

it('leaves a cancelled payment alone when a late COMPLETE ITN has a mismatched amount', function () {
    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'SAPRF-LATE-3',
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
        'm_payment_id' => 'MEM-LATE-3',
        'status' => 'cancelled',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '2099993',
        'payment_status' => 'COMPLETE',
        'item_name' => 'SAPRF Membership',
        'amount_gross' => '450.00',
        'amount_fee' => '-11.00',
        'amount_net' => '439.00',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertOk();

    expect($payment->fresh()->status)->toBe('cancelled')
        ->and($membership->fresh()->payment_status)->toBe('unpaid');
});

it('re-opens a cancelled registration payment and confirms the entry', function () {
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

    // The pending checkout row got cancelled on our side (e.g. the MD
    // repriced the entry while the shooter was still inside PayFast).
    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->member->id,
        'amount' => 1100.00,
        'm_payment_id' => 'REG-LATE-1',
        'status' => 'cancelled',
    ]);

    postItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '2099994',
        'payment_status' => 'COMPLETE',
        'item_name' => 'Match Registration',
        'amount_gross' => '1100.00',
        'amount_fee' => '-23.50',
        'amount_net' => '1076.50',
        'merchant_id' => config('payfast.merchant_id'),
    ])->assertOk();

    $payment->refresh();
    $registration->refresh();

    expect($payment->status)->toBe('completed')
        ->and($payment->gateway_response['reopened_from_cancelled'] ?? false)->toBeTrue()
        ->and($registration->payment_status)->toBe('paid')
        ->and($registration->registration_status)->toBe('confirmed');
});
