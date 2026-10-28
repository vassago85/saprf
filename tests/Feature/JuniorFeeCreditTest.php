<?php

use App\Models\AccountCredit;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\JuniorFeeCreditNotification;
use App\Services\AccountCreditService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    app(SettingsService::class)->set('saprf_fee_type', 'fixed', 'test');
    app(SettingsService::class)->set('saprf_fee_value', '50', 'test');
    app(SettingsService::class)->set('platform_fee_type', 'fixed', 'test');
    app(SettingsService::class)->set('platform_fee_value', '0', 'test');
    app(SettingsService::class)->set('estimated_gateway_fee_percentage', '3.5', 'test');
    app(SettingsService::class)->set('estimated_gateway_flat_fee', '2', 'test');

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->director = User::factory()->create(['email_verified_at' => now()]);
    $this->director->assignRole('admin');

    $this->junior = Division::create(['slug' => 'junior', 'name' => 'Junior', 'is_active' => true, 'display_order' => 1]);
    $this->open = Division::create(['slug' => 'open', 'name' => 'Open', 'is_active' => true, 'display_order' => 2]);

    $this->match = MatchEvent::create([
        'name' => 'Junior Fee Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => (string) now()->year,
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 1200,
        'junior_fee' => 850,
        'created_by' => $this->director->id,
    ]);

    $this->parent = User::factory()->create(['name' => 'Paying Parent', 'email_verified_at' => now()]);
    $this->shooter = User::factory()->create(['name' => 'Junior Shooter', 'email_verified_at' => now()]);
});

function juniorEntry(MatchEvent $match, Division $division, User $shooter, ?User $payer, array $overrides = []): MatchRegistration
{
    $registration = MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'registered_by_user_id' => $payer?->id,
        'division_id' => $division->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1200,
        'surcharge_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 0,
        'gateway_fee' => 44,
        'md_net_amount' => 1106,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));

    if ($payer) {
        Payment::create([
            'payable_type' => MatchRegistration::class,
            'payable_id' => $registration->id,
            'user_id' => $payer->id,
            'amount' => $overrides['fee_amount'] ?? 1200,
            'gateway' => 'payfast',
            'm_payment_id' => Payment::generateReference('REG'),
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }

    return $registration;
}

it('credits the paying account the junior fee difference and lowers the entry', function () {
    $registration = juniorEntry($this->match, $this->junior, $this->shooter, $this->parent);

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
        '--fee' => 850,
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('Issued R 350.00 across 1 junior fee credit(s).');

    $registration->refresh();
    $credit = AccountCredit::query()->where('fee_adjusted_registration_id', $registration->id)->firstOrFail();

    expect($credit->type)->toBe(AccountCredit::TYPE_FEE_ADJUSTMENT)
        ->and($credit->user_id)->toBe($this->parent->id)
        ->and((float) $credit->amount)->toBe(350.0)
        ->and((float) $registration->fee_amount)->toBe(850.0)
        ->and((float) $registration->surcharge_amount)->toBe(0.0)
        ->and((float) $registration->saprf_fee)->toBe(50.0)
        ->and((float) $registration->gateway_fee)->toBe(44.0)
        ->and((float) $registration->md_net_amount)->toBe(756.0)
        ->and($registration->amountPaidOnPlatform())->toBe(850.0)
        ->and($this->parent->accountCreditSummary()['available'])->toBe(350.0)
        ->and($this->shooter->accountCreditSummary()['posted'])->toBe(0.0);

    Notification::assertSentTo($this->parent, JuniorFeeCreditNotification::class);
    Notification::assertNotSentTo($this->shooter, JuniorFeeCreditNotification::class);
});

it('keeps a non-member surcharge and only credits the base-fee difference', function () {
    $registration = juniorEntry($this->match, $this->junior, $this->shooter, $this->parent, [
        'membership_fee_category' => 'non_member',
        'fee_amount' => 1400,
        'surcharge_amount' => 200,
        'md_net_amount' => 1106,
    ]);

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $registration->refresh();

    expect((float) $registration->fee_amount)->toBe(1050.0)
        ->and((float) $registration->surcharge_amount)->toBe(200.0)
        ->and((float) $registration->md_net_amount)->toBe(756.0)
        ->and((float) AccountCredit::query()->value('amount'))->toBe(350.0);
});

it('changes nothing on a dry run', function () {
    $registration = juniorEntry($this->match, $this->junior, $this->shooter, $this->parent);

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
    ])->assertSuccessful()
        ->expectsOutputToContain('Would issue R 350.00 across 1 junior fee credit(s).');

    expect(AccountCredit::query()->count())->toBe(0)
        ->and((float) $registration->fresh()->fee_amount)->toBe(1200.0);
});

it('does not credit the same junior entry twice', function () {
    juniorEntry($this->match, $this->junior, $this->shooter, $this->parent);

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful();

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('No junior entries left above R850.00.');

    expect($this->parent->accountCreditSummary()['posted'])->toBe(350.0);
});

it('leaves other divisions, cancelled juniors, and juniors already at the new fee', function () {
    $other = fn (string $name) => User::factory()->create(['name' => $name, 'email_verified_at' => now()]);

    juniorEntry($this->match, $this->open, $other('Open Shooter'), $this->parent);
    juniorEntry($this->match, $this->junior, $other('Cancelled'), $this->parent, [
        'registration_status' => 'cancelled',
    ]);
    juniorEntry($this->match, $this->junior, $other('Already 850'), $this->parent, [
        'fee_amount' => 850,
        'md_net_amount' => 756,
    ]);

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('No junior entries left above R850.00.');

    expect(AccountCredit::query()->count())->toBe(0);
});

it('reprices an unpaid junior entry without issuing credit', function () {
    $registration = juniorEntry($this->match, $this->junior, $this->shooter, null, [
        'payment_status' => 'unpaid',
        'fee_amount' => 1200,
    ]);
    $pending = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->parent->id,
        'amount' => 1200,
        'gateway' => 'payfast',
        'm_payment_id' => Payment::generateReference('REG'),
        'status' => 'pending',
    ]);

    $this->artisan('registrations:issue-junior-fee-credits', [
        '--match' => $this->match->id,
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('Repriced 1 unpaid junior entry.');

    $registration->refresh();

    expect((float) $registration->fee_amount)->toBe(850.0)
        ->and((float) $registration->saprf_fee)->toBe(50.0)
        ->and((float) $registration->gateway_fee)->toBe(31.75)
        ->and((float) $registration->md_net_amount)->toBe(768.25)
        ->and($pending->fresh()->status)->toBe('cancelled')
        ->and(AccountCredit::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('does not hand the junior discount back again when the member later withdraws', function () {
    $registration = juniorEntry($this->match, $this->junior, $this->shooter, $this->shooter);
    app(AccountCreditService::class)->issueJuniorFeeCredit($registration, 850, 'test');

    $withdrawal = app(AccountCreditService::class)->issueForWithdrawal($registration->fresh());

    expect((float) $withdrawal->amount)->toBe(850.0)
        ->and($this->shooter->accountCreditSummary()['posted'])->toBe(1200.0);
});
