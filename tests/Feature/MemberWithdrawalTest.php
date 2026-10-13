<?php

use App\Models\AccountCredit;
use App\Models\CreditRefundRequest;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\CreditRefundRequestedNotification;
use App\Services\AccountCreditService;
use App\Services\FinancialService;
use App\Services\PlatformPayoutService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $settings = app(SettingsService::class);
    foreach ([
        'withdrawal_admin_fee' => '100',
        'withdrawal_deadline_hours' => '72',
        'withdrawal_developer_fee' => '50',
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => '50',
        'non_member_surcharge' => '0',
        'lapsed_member_surcharge' => '0',
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->match = withdrawalTestMatch($this->province, $this->md, 'Withdrawal Match');

    $this->shooter = User::factory()->create(['email_verified_at' => now()]);
    $this->shooter->assignRole('member');

    $this->division = Division::create(['slug' => 'open', 'name' => 'Open', 'is_active' => true, 'display_order' => 1]);
});

function withdrawalTestMatch(Province $province, User $md, string $name, ?Carbon $date = null): MatchEvent
{
    return MatchEvent::create([
        'name' => $name,
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => $date ?? Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 500.00,
        'non_member_fee' => 500.00,
        'created_by' => $md->id,
    ]);
}

/** A R500 entry paid by card, PayFast keeping R19.50. */
function withdrawalTestEntry(MatchEvent $match, User $shooter, ?User $payer = null): MatchRegistration
{
    $payer ??= $shooter;

    $registration = MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'registered_by_user_id' => $payer->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500.00,
        'saprf_fee' => 50.00,
        'platform_fee' => 50.00,
        'gateway_fee' => 19.50,
        'md_net_amount' => 380.50,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $payer->id,
        'amount' => 500.00,
        'amount_fee' => 19.50,
        'gateway' => 'payfast',
        'm_payment_id' => Payment::generateReference('REG'),
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    return $registration;
}

function withdrawalBankDetails(array $overrides = []): array
{
    return array_merge([
        'withdrawal_option' => 'refund',
        'account_holder' => 'Test Shooter',
        'bank_name' => 'FNB',
        'account_number' => '62123456789',
        'branch_code' => '250655',
    ], $overrides);
}

it('keeps the full fee as credit with no withdrawal fee, carrying the gateway fee with it', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit'])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('success');

    $registration->refresh();
    $credit = AccountCredit::query()->where('issued_for_registration_id', $registration->id)->firstOrFail();

    expect($registration->registration_status)->toBe('cancelled')
        ->and($registration->withdrawal_option)->toBe('credit')
        ->and((float) $registration->refund_amount)->toBe(500.0)
        ->and((float) $registration->admin_fee_charged)->toBe(0.0)
        ->and((float) $registration->penalty_platform_fee)->toBe(0.0)
        ->and($credit->type)->toBe(AccountCredit::TYPE_WITHDRAWAL)
        ->and((float) $credit->amount)->toBe(500.0)
        ->and((float) $credit->gateway_fee)->toBe(19.5)
        ->and($this->shooter->accountCreditSummary()['available'])->toBe(500.0)
        ->and(CreditRefundRequest::query()->count())->toBe(0);
});

it('passes the original gateway fee to the match the credit is spent on', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);
    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit']);

    $nextMatch = withdrawalTestMatch($this->province, $this->md, 'Next Match');

    $this->actingAs($this->shooter)
        ->post(route('events.register.store', $nextMatch), ['division_id' => $this->division->id])
        ->assertRedirect();

    $entry = MatchRegistration::query()->where('match_id', $nextMatch->id)->firstOrFail();

    expect($entry->payment_status)->toBe('paid')
        ->and((float) $entry->gateway_fee)->toBe(19.5)
        ->and((float) $entry->md_net_amount)->toBe(380.5)
        ->and($this->shooter->fresh()->accountCreditSummary()['available'])->toBe(0.0);
});

it('splits the carried gateway fee across the entries the credit pays for', function () {
    AccountCredit::create([
        'user_id' => $this->shooter->id,
        'amount' => 500,
        'gateway_fee' => 20,
        'status' => AccountCredit::STATUS_POSTED,
        'type' => AccountCredit::TYPE_WITHDRAWAL,
        'description' => 'Withdrawal credit',
    ]);

    $credits = app(AccountCreditService::class);
    $carried = [];

    foreach (['Half A', 'Half B'] as $name) {
        $match = withdrawalTestMatch($this->province, $this->md, $name);
        $entry = MatchRegistration::create([
            'match_id' => $match->id,
            'user_id' => $this->shooter->id,
            'shooter_name' => $this->shooter->name,
            'email' => $this->shooter->email,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 250.00,
            'payment_status' => 'pending',
            'registration_status' => 'pending',
            'registered_at' => now(),
        ]);

        $payment = $credits->applyToRegistrationPayment($this->shooter, $entry);
        $credits->captureReservationForPayment($payment);
        $carried[] = $credits->carriedGatewayFeeForPayment($payment);
    }

    expect($carried)->toBe([10.0, 10.0])
        ->and($credits->withdrawalCreditBalance($this->shooter))->toBe(['amount' => 0.0, 'gateway_fee' => 0.0]);
});

it('refunds cash minus the withdrawal fee, split developer then gateway then SAPRF', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), withdrawalBankDetails())
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('success');

    $registration->refresh();
    $refund = CreditRefundRequest::query()->firstOrFail();

    expect($registration->withdrawal_option)->toBe('refund')
        ->and((float) $registration->refund_amount)->toBe(400.0)
        ->and((float) $registration->admin_fee_charged)->toBe(100.0)
        ->and((float) $registration->penalty_platform_fee)->toBe(50.0)
        ->and((float) $registration->penalty_gateway_fee)->toBe(19.5)
        ->and((float) $registration->penalty_saprf_fee)->toBe(30.5)
        ->and($refund->status)->toBe('pending')
        ->and((float) $refund->amount)->toBe(400.0)
        ->and($refund->match_registration_id)->toBe($registration->id)
        ->and($refund->user_id)->toBe($this->shooter->id)
        ->and($this->shooter->accountCreditSummary()['available'])->toBe(0.0);

    Notification::assertSentTo($this->shooter, CreditRefundRequestedNotification::class);
});

it('bills the developer share of the withdrawal fee and counts the rest as SAPRF income', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);
    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), withdrawalBankDetails());

    $preview = app(PlatformPayoutService::class)->preview(now());
    $summary = app(FinancialService::class)->platformSummary(now()->startOfMonth(), now()->endOfMonth());

    expect($preview['platform_fees'])->toBe(50.0)
        ->and($summary['match_revenue']['withdrawal_fees'])->toBe(100.0)
        ->and($summary['total_saprf_fees'])->toBe(30.5)
        ->and($summary['match_revenue']['gateway_fees'])->toBe(19.5);
});

it('needs bank details before it will refund cash', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->from(route('registrations.show', $registration))
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'refund'])
        ->assertSessionHasErrors(['account_holder', 'account_number']);

    expect($registration->fresh()->registration_status)->toBe('confirmed');
});

it('makes the member choose credit or refund before the deadline', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->from(route('registrations.show', $registration))
        ->post(route('registrations.withdraw', $registration))
        ->assertSessionHasErrors('withdrawal_option');

    expect($registration->fresh()->registration_status)->toBe('confirmed');
});

it('does not let withdrawal credit be cashed out through a refund request', function () {
    app(SettingsService::class)->set('cancellation_refunds_enabled', '1');
    $registration = withdrawalTestEntry($this->match, $this->shooter);
    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit']);

    $this->actingAs($this->shooter)
        ->post(route('account.refund.store'), array_merge(withdrawalBankDetails(), ['amount' => 500]))
        ->assertSessionHasErrors('amount');

    $summary = $this->shooter->accountCreditSummary();
    expect($summary['refundable'])->toBe(0.0)
        ->and($summary['available'])->toBe(500.0)
        ->and(CreditRefundRequest::query()->count())->toBe(0);
});

it('only offers credit to the payer when someone else paid for the entry', function () {
    $parent = User::factory()->create(['email_verified_at' => now()]);
    $registration = withdrawalTestEntry($this->match, $this->shooter, $parent);

    $this->actingAs($this->shooter)
        ->from(route('registrations.show', $registration))
        ->post(route('registrations.withdraw', $registration), withdrawalBankDetails())
        ->assertSessionHasErrors('withdrawal_option');

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit'])
        ->assertSessionHas('success');

    expect($parent->accountCreditSummary()['available'])->toBe(500.0)
        ->and($this->shooter->accountCreditSummary()['available'])->toBe(0.0);
});

it('forfeits the fee inside the deadline with no credit or refund', function () {
    $lateMatch = withdrawalTestMatch($this->province, $this->md, 'Late Match', now()->addHours(24));
    $registration = withdrawalTestEntry($lateMatch, $this->shooter);

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit'])
        ->assertSessionHas('success');

    $registration->refresh();
    expect($registration->registration_status)->toBe('cancelled')
        ->and($registration->withdrawal_option)->toBeNull()
        ->and((float) $registration->refund_amount)->toBe(0.0)
        ->and(AccountCredit::query()->count())->toBe(0)
        ->and(CreditRefundRequest::query()->count())->toBe(0);
});

/** Imported from the previous site: marked paid, no payment on this platform. */
function importedWithdrawalEntry(MatchEvent $match, User $shooter, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500.00,
        'saprf_fee' => 50.00,
        'platform_fee' => 0,
        'gateway_fee' => 19.50,
        'md_net_amount' => 430.50,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));
}

it('credits an imported entry at the entry fee without carrying a gateway fee', function () {
    $registration = importedWithdrawalEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('Keep R 500.00 as entry credit')
        ->assertDontSee('to my bank account');

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit'])
        ->assertSessionHas('success');

    $registration->refresh();
    $credit = AccountCredit::query()->where('issued_for_registration_id', $registration->id)->firstOrFail();

    expect($registration->registration_status)->toBe('cancelled')
        ->and((float) $registration->refund_amount)->toBe(500.0)
        ->and($credit->user_id)->toBe($this->shooter->id)
        ->and((float) $credit->amount)->toBe(500.0)
        ->and((float) $credit->gateway_fee)->toBe(0.0);

    $nextMatch = withdrawalTestMatch($this->province, $this->md, 'After Import');
    $this->actingAs($this->shooter)
        ->post(route('events.register.store', $nextMatch), ['division_id' => $this->division->id]);

    $entry = MatchRegistration::query()->where('match_id', $nextMatch->id)->firstOrFail();
    expect($entry->payment_status)->toBe('paid')
        ->and((float) $entry->gateway_fee)->toBe(0.0);
});

it('does not offer a cash refund for an entry paid off the platform', function () {
    $registration = importedWithdrawalEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->from(route('registrations.show', $registration))
        ->post(route('registrations.withdraw', $registration), withdrawalBankDetails())
        ->assertSessionHasErrors('withdrawal_option');

    expect($registration->fresh()->registration_status)->toBe('confirmed')
        ->and(CreditRefundRequest::query()->count())->toBe(0);
});

it('credits an entry the shooter confirmed paying on the previous site', function () {
    $registration = importedWithdrawalEntry($this->match, $this->shooter, ['payment_status' => 'waived']);

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit'])
        ->assertSessionHas('success');

    expect($this->shooter->accountCreditSummary()['available'])->toBe(500.0);
});

it('credits the shooter, not the match director, for an entry the MD keyed in', function () {
    $registration = importedWithdrawalEntry($this->match, $this->shooter, [
        'registered_by_user_id' => $this->md->id,
        'gateway_fee' => 0,
    ]);

    $this->actingAs($this->shooter)
        ->post(route('registrations.withdraw', $registration), ['withdrawal_option' => 'credit'])
        ->assertSessionHas('success');

    expect($this->shooter->accountCreditSummary()['available'])->toBe(500.0)
        ->and($this->md->accountCreditSummary()['available'])->toBe(0.0);
});

it('shows both options on the entry page before the deadline', function () {
    $registration = withdrawalTestEntry($this->match, $this->shooter);

    $this->actingAs($this->shooter)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('Keep R 500.00 as entry credit')
        ->assertSee('Refund R 400.00 to my bank account');
});
