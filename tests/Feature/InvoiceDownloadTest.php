<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;

/**
 * Member-facing invoice download — exercises:
 *   - the policy on /payments/{payment}/invoice (payer, managed family,
 *     support staff, foreign user)
 *   - the invoiceable filter (completed, non-zero, known payable only)
 *   - the two surfaces: /invoices listing + the per-payment download
 *   - VAT mode toggle via the `invoice_vat_number` site setting
 *
 * We rely on the response body for PDF assertions. DomPDF outputs real
 * `%PDF-` bytes so the smoke check is a prefix match on the content.
 */
beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->match = MatchEvent::create([
        'name' => 'Invoice Smoke Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 850.00,
        'non_member_fee' => 1050.00,
        'lapsed_member_fee' => 950.00,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    $this->member->assignRole('member');

    $this->otherMember = User::factory()->create(['email_verified_at' => now()]);
    $this->otherMember->assignRole('member');
});

/** @return array{0: MatchRegistration, 1: Payment} */
function makePaidRegistration(User $payer, MatchEvent $match, array $overrides = []): array
{
    $registration = MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $payer->id,
        'shooter_name' => $payer->name,
        'email' => $payer->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 850.00,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $payment = Payment::create(array_merge([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $payer->id,
        'amount' => 850.00,
        'gateway' => 'payfast',
        'gateway_payment_id' => '333852233',
        'm_payment_id' => Payment::generateReference('REG'),
        'status' => 'completed',
        'paid_at' => now(),
    ], $overrides));

    return [$registration, $payment];
}

// ── /payments/{payment}/invoice — download ──

it('streams a PDF to the member who paid', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match);

    $response = $this->actingAs($this->member)
        ->get(route('payments.invoice', $payment));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    // DomPDF returns a plain Response, not a streamed one — getContent()
    // gives us the raw PDF bytes, which always begin with %PDF-.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects a member who did not pay for this entry', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match);

    $this->actingAs($this->otherMember)
        ->get(route('payments.invoice', $payment))
        ->assertForbidden();
});

it('lets support staff download any member\'s invoice', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('payments.invoice', $payment))
        ->assertOk();
});

it('404s on a pending (unpaid) payment', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match, [
        'status' => 'pending',
        'paid_at' => null,
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.invoice', $payment))
        ->assertNotFound();
});

it('404s on a cancelled payment', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match, [
        'status' => 'cancelled',
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.invoice', $payment))
        ->assertNotFound();
});

it('404s on a zero-amount payment', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match, [
        'amount' => 0,
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.invoice', $payment))
        ->assertNotFound();
});

it('produces a PDF for a completed account-credit payment', function () {
    [, $payment] = makePaidRegistration($this->member, $this->match, [
        'gateway' => 'account_credit',
        'gateway_payment_id' => null,
        'amount_fee' => 0,
    ]);

    $response = $this->actingAs($this->member)
        ->get(route('payments.invoice', $payment));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('produces a PDF for a membership payment', function () {
    $tier = MembershipFeeTier::create([
        'slug' => 'adult',
        'name' => 'Adult',
        'price' => 850.00,
        'duration_months' => 12,
        'is_active' => true,
        'min_age' => 18,
        'max_age' => null,
    ]);

    $membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => '9999',
        'membership_type' => 'paid',
        'fee_tier_id' => $tier->id,
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $payment = Payment::create([
        'payable_type' => Membership::class,
        'payable_id' => $membership->id,
        'user_id' => $this->member->id,
        'amount' => 850.00,
        'gateway' => 'payfast',
        'gateway_payment_id' => '333850401',
        'm_payment_id' => Payment::generateReference('MEM'),
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $this->actingAs($this->member)
        ->get(route('payments.invoice', $payment))
        ->assertOk();
});

// ── /invoices — listing ──

it('lists only completed payments for the signed-in member', function () {
    [, $paidPayment] = makePaidRegistration($this->member, $this->match);
    [, $pendingPayment] = makePaidRegistration($this->member, $this->match, [
        'status' => 'pending',
        'paid_at' => null,
    ]);
    [, $otherMemberPayment] = makePaidRegistration($this->otherMember, $this->match);

    $response = $this->actingAs($this->member)
        ->get(route('invoices.index'))
        ->assertOk();

    $response->assertSee($paidPayment->m_payment_id);
    $response->assertDontSee($pendingPayment->m_payment_id);
    $response->assertDontSee($otherMemberPayment->m_payment_id);
});

it('shows an empty state when the member has no completed payments', function () {
    $this->actingAs($this->member)
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertSee('No invoices yet');
});

// ── VAT mode toggle ──

it('renders a plain Invoice when no VAT number is configured', function () {
    Setting::updateOrCreate(['key' => 'invoice_vat_number'], ['value' => '']);
    app(SettingsService::class)->clearCache();

    [, $payment] = makePaidRegistration($this->member, $this->match);

    $viewModel = app(\App\Services\InvoiceService::class)->buildViewModel($payment);

    expect($viewModel['is_tax_invoice'])->toBeFalse()
        ->and((float) $viewModel['totals']['subtotal'])->toBe(850.00)
        ->and((float) $viewModel['totals']['vat'])->toBe(0.0)
        ->and((float) $viewModel['totals']['total'])->toBe(850.00);
});

it('flips to a Tax Invoice with 15% VAT breakdown when a VAT number is set', function () {
    Setting::updateOrCreate(['key' => 'invoice_vat_number'], ['value' => '4123456789']);
    app(SettingsService::class)->clearCache();

    [, $payment] = makePaidRegistration($this->member, $this->match);

    $viewModel = app(\App\Services\InvoiceService::class)->buildViewModel($payment);

    expect($viewModel['is_tax_invoice'])->toBeTrue()
        // 850.00 inclusive → 15/115 is VAT → 110.87 VAT, 739.13 subtotal.
        ->and((float) $viewModel['totals']['total'])->toBe(850.00)
        ->and((float) $viewModel['totals']['vat'])->toBe(110.87)
        ->and((float) $viewModel['totals']['subtotal'])->toBe(739.13);
});
