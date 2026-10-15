<?php

use App\Models\AccountCredit;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\SurchargeAdjustmentCreditNotification;
use App\Services\AccountCreditService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    app(SettingsService::class)->set('withdrawal_deadline_hours', '72');

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->director = User::factory()->create(['email_verified_at' => now()]);
    $this->director->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Surcharge Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => (string) now()->year,
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'created_by' => $this->director->id,
    ]);

    $this->parent = User::factory()->create(['name' => 'Paying Parent', 'email_verified_at' => now()]);
    $this->shooter = User::factory()->create(['name' => 'Non Member', 'email_verified_at' => now()]);
});

/** R500 entry + R200 non-member surcharge = R700. */
function surchargeEntry(MatchEvent $match, User $shooter, ?User $payer, array $overrides = []): MatchRegistration
{
    $registration = MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'registered_by_user_id' => $payer?->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'non_member',
        'fee_amount' => 700,
        'surcharge_amount' => 200,
        'saprf_fee' => 50,
        'platform_fee' => 0,
        'gateway_fee' => 26.50,
        'md_net_amount' => 423.50,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));

    if ($payer) {
        Payment::create([
            'payable_type' => MatchRegistration::class,
            'payable_id' => $registration->id,
            'user_id' => $payer->id,
            'amount' => 700,
            'gateway' => 'payfast',
            'm_payment_id' => Payment::generateReference('REG'),
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }

    return $registration;
}

it('credits R100 to whoever paid the R200 surcharge and lowers the entry to match', function () {
    $registration = surchargeEntry($this->match, $this->shooter, $this->parent);

    $this->artisan('registrations:issue-surcharge-credits', ['--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Issued R 100.00 across 1 surcharge credit(s).');

    $registration->refresh();
    $credit = AccountCredit::query()->where('adjusted_registration_id', $registration->id)->firstOrFail();

    expect($credit->type)->toBe(AccountCredit::TYPE_SURCHARGE_ADJUSTMENT)
        ->and($credit->user_id)->toBe($this->parent->id)
        ->and((float) $credit->amount)->toBe(100.0)
        ->and((float) $registration->fee_amount)->toBe(600.0)
        ->and((float) $registration->surcharge_amount)->toBe(100.0)
        ->and((float) $registration->md_net_amount)->toBe(423.5)
        ->and($registration->hasIssuedCredit())->toBeFalse()
        ->and($registration->amountPaidOnPlatform())->toBe(600.0)
        ->and($this->parent->accountCreditSummary()['available'])->toBe(100.0)
        ->and($this->shooter->accountCreditSummary()['posted'])->toBe(0.0);

    Notification::assertSentTo($this->parent, SurchargeAdjustmentCreditNotification::class);
    Notification::assertNotSentTo($this->shooter, SurchargeAdjustmentCreditNotification::class);
});

it('changes nothing on a dry run', function () {
    $registration = surchargeEntry($this->match, $this->shooter, $this->parent);

    $this->artisan('registrations:issue-surcharge-credits')
        ->assertSuccessful()
        ->expectsOutputToContain('Would issue R 100.00 across 1 surcharge credit(s).');

    expect(AccountCredit::query()->count())->toBe(0)
        ->and((float) $registration->fresh()->surcharge_amount)->toBe(200.0);
});

it('does not credit the same entry twice', function () {
    surchargeEntry($this->match, $this->shooter, $this->parent);

    $this->artisan('registrations:issue-surcharge-credits', ['--apply' => true])->assertSuccessful();
    $this->artisan('registrations:issue-surcharge-credits', ['--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('No entries left to credit.');

    expect($this->parent->accountCreditSummary()['posted'])->toBe(100.0);
});

it('credits the shooter for an entry paid on the previous site', function () {
    $registration = surchargeEntry($this->match, $this->shooter, null, ['payment_status' => 'waived']);

    $credit = app(AccountCreditService::class)->issueSurchargeAdjustment($registration, 100, 'test');

    expect($credit?->user_id)->toBe($this->shooter->id)
        ->and((float) $registration->fresh()->fee_amount)->toBe(600.0);
});

it('skips unpaid, cancelled, R100 and already-credited entries', function () {
    $other = fn (string $name) => User::factory()->create(['name' => $name]);

    surchargeEntry($this->match, $other('Unpaid'), null, ['payment_status' => 'unpaid']);
    surchargeEntry($this->match, $other('Cancelled'), $this->parent, ['registration_status' => 'cancelled']);
    surchargeEntry($this->match, $other('New price'), $this->parent, ['fee_amount' => 600, 'surcharge_amount' => 100]);
    $credited = surchargeEntry($this->match, $other('Already credited'), $this->parent);
    AccountCredit::create([
        'user_id' => $this->parent->id,
        'amount' => 700,
        'status' => AccountCredit::STATUS_POSTED,
        'type' => AccountCredit::TYPE_CANCELLATION,
        'match_id' => $this->match->id,
        'issued_for_registration_id' => $credited->id,
        'description' => 'Earlier credit',
    ]);

    $this->artisan('registrations:issue-surcharge-credits', ['--apply' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('No entries left to credit.');

    expect(AccountCredit::query()->where('type', AccountCredit::TYPE_SURCHARGE_ADJUSTMENT)->count())->toBe(0);
});

it('does not hand the R100 back again when the member later withdraws', function () {
    $registration = surchargeEntry($this->match, $this->shooter, $this->shooter);
    app(AccountCreditService::class)->issueSurchargeAdjustment($registration, 100, 'test');

    $withdrawal = app(AccountCreditService::class)->issueForWithdrawal($registration->fresh());

    expect((float) $withdrawal->amount)->toBe(600.0)
        ->and($this->shooter->accountCreditSummary()['posted'])->toBe(700.0);
});

it('does not hand the R100 back again when the match is cancelled', function () {
    $registration = surchargeEntry($this->match, $this->shooter, $this->parent);
    app(AccountCreditService::class)->issueSurchargeAdjustment($registration, 100, 'test');

    $this->match->update(['status' => 'cancelled']);
    $issued = app(AccountCreditService::class)->issueForCancelledMatch($this->match->fresh());

    expect((float) $issued->first()->amount)->toBe(600.0)
        ->and($this->parent->accountCreditSummary()['posted'])->toBe(700.0);
});
