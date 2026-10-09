<?php

use App\Models\AccountCredit;
use App\Models\CreditRefundRequest;
use App\Models\User;
use App\Notifications\CreditRefundRequestedNotification;
use App\Notifications\CreditRefundRequestedStaffNotification;
use App\Notifications\CreditRefundResolvedNotification;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $this->member = User::factory()->create([
        'name' => 'Stephan',
        'email_verified_at' => now(),
    ]);
    $this->member->assignRole('member');

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->assignRole('owner');

    AccountCredit::query()->create([
        'user_id' => $this->member->id,
        'amount' => 1400,
        'status' => AccountCredit::STATUS_POSTED,
        'type' => AccountCredit::TYPE_CANCELLATION,
        'description' => 'Entry credit: cancelled match (Stephan and son)',
    ]);
});

it('hides the refund form until finance turns it on', function () {
    $this->actingAs($this->member)
        ->get(route('account.refund'))
        ->assertOk()
        ->assertSee('Cash refunds are not open yet')
        ->assertDontSee('name="amount"', false);

    $this->actingAs($this->member)
        ->post(route('account.refund.store'), refundBankDetails())
        ->assertSessionHasErrors('amount');

    expect(CreditRefundRequest::query()->count())->toBe(0)
        ->and($this->member->accountCreditSummary()['available'])->toBe(1400.0);
});

it('holds the requested amount so it cannot also be spent, and leaves the rest as credit', function () {
    app(SettingsService::class)->set('cancellation_refunds_enabled', '1');
    app(SettingsService::class)->set('secretary_email', 'secretary@precisionrifle.co.za');

    $this->actingAs($this->member)
        ->get(route('account.refund'))
        ->assertOk()
        ->assertSee('Request a cash refund instead');

    $this->actingAs($this->member)
        ->post(route('account.refund.store'), refundBankDetails(['amount' => 700]))
        ->assertRedirect(route('account.refund'));

    $summary = $this->member->accountCreditSummary();

    expect($summary['available'])->toBe(700.0)
        ->and($summary['refund_reserved'])->toBe(700.0)
        ->and(CreditRefundRequest::query()->where('status', 'pending')->count())->toBe(1);

    Notification::assertSentTo($this->member, CreditRefundRequestedNotification::class);
    Notification::assertSentOnDemand(CreditRefundRequestedStaffNotification::class);

    $this->actingAs($this->member)
        ->post(route('account.refund.store'), refundBankDetails(['amount' => 700]))
        ->assertSessionHasErrors('amount');

    expect(CreditRefundRequest::query()->count())->toBe(1);
});

it('pays the refund in full and declines a request by putting the credit back', function () {
    app(SettingsService::class)->set('cancellation_refunds_enabled', '1');

    $this->actingAs($this->member)
        ->post(route('account.refund.store'), refundBankDetails())
        ->assertRedirect();

    $refund = CreditRefundRequest::query()->firstOrFail();

    $this->actingAs($this->member)
        ->get(route('financials.credit-refunds.index'))
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->get(route('financials.credit-refunds.index'))
        ->assertOk()
        ->assertSee('62123456789')
        ->assertSee('Stephan');

    $this->actingAs($this->owner)
        ->post(route('financials.credit-refunds.pay', $refund), [
            'payment_reference' => 'EFT-100',
        ])
        ->assertRedirect(route('financials.credit-refunds.index'));

    expect($refund->fresh()->status)->toBe('paid')
        ->and($this->member->accountCreditSummary()['available'])->toBe(0.0);

    Notification::assertSentTo($this->member, CreditRefundResolvedNotification::class);

    AccountCredit::query()->create([
        'user_id' => $this->member->id,
        'amount' => 700,
        'status' => AccountCredit::STATUS_POSTED,
        'type' => AccountCredit::TYPE_CANCELLATION,
        'description' => 'Second credit',
    ]);

    $this->actingAs($this->member)
        ->post(route('account.refund.store'), refundBankDetails(['amount' => 700]))
        ->assertRedirect();

    $second = CreditRefundRequest::query()->where('status', 'pending')->firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('financials.credit-refunds.decline', $second), [
            'decline_reason' => 'Bank account number does not match the payer.',
        ])
        ->assertRedirect();

    expect($second->fresh()->status)->toBe('declined')
        ->and($this->member->accountCreditSummary()['available'])->toBe(700.0);
});

it('lets an owner switch cancellation refunds on from site settings', function () {
    $this->actingAs($this->owner)
        ->put(route('site-settings.update'), refundSiteSettings(['cancellation_refunds_enabled' => '1']))
        ->assertRedirect(route('site-settings.index'));

    expect(app(SettingsService::class)->cancellationRefundsEnabled())->toBeTrue();

    $this->actingAs($this->owner)
        ->put(route('site-settings.update'), refundSiteSettings(['cancellation_refunds_enabled' => '0']))
        ->assertRedirect(route('site-settings.index'));

    expect(app(SettingsService::class)->cancellationRefundsEnabled())->toBeFalse();
});

function refundBankDetails(array $overrides = []): array
{
    return array_merge([
        'amount' => 1400,
        'account_holder' => 'Stephan',
        'bank_name' => 'FNB',
        'account_number' => '62123456789',
        'branch_code' => '250655',
    ], $overrides);
}

function refundSiteSettings(array $overrides = []): array
{
    return array_merge([
        'non_member_surcharge' => '250',
        'lapsed_member_surcharge' => '150',
        'withdrawal_admin_fee' => '100',
        'withdrawal_deadline_hours' => '72',
        'division_single_select' => '1',
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'membership_platform_fee_pct' => '2.5',
        'estimated_gateway_fee_percentage' => '3.5',
        'estimated_gateway_flat_fee' => '2.00',
        'payfast_sandbox' => '1',
        'payments_enabled' => '1',
        'notifications_enabled' => '1',
        'cancellation_refunds_enabled' => '0',
    ], $overrides);
}
