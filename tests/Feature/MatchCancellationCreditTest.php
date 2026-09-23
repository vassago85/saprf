<?php

use App\Models\AccountCredit;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\MatchCancellationCreditNotification;
use App\Services\AccountCreditService;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->director = User::factory()->create(['email_verified_at' => now()]);
    $this->director->assignRole('admin');

    $this->father = User::factory()->create([
        'name' => 'Stephan',
        'email_verified_at' => now(),
        'province_id' => $this->province->id,
    ]);
    $this->son = User::factory()->create([
        'name' => 'Stephan Junior',
        'email_verified_at' => now(),
        'province_id' => $this->province->id,
        'parent_id' => $this->father->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
    ]);

    $this->match = cancelledCreditMatch($this->director, 'Cancelled Club Match');
});

it('credits the paying parent for his own entry and his son when the match is cancelled', function () {
    $own = paidEntry($this->match, $this->father, $this->father, 700);
    $sons = paidEntry($this->match, $this->son, $this->father, 700, [
        'registered_by_user_id' => $this->father->id,
    ]);
    paidEntry($this->match, $this->father, null, 700, [
        'shooter_name' => 'Unpaid guest',
        'payment_status' => 'unpaid',
        'user_id' => $this->son->id,
    ]);

    $issued = app(AccountCreditService::class)->issueForCancelledMatch($this->match, $this->director);

    expect($issued)->toHaveCount(2)
        ->and($this->father->accountCreditSummary()['posted'])->toBe(1400.0)
        ->and($this->son->accountCreditSummary()['posted'])->toBe(0.0)
        ->and((float) $own->fresh()->refund_amount)->toBe(700.0)
        ->and((float) $sons->fresh()->refund_amount)->toBe(700.0);

    Notification::assertSentToTimes($this->father, MatchCancellationCreditNotification::class, 1);
    Notification::assertNotSentTo($this->son, MatchCancellationCreditNotification::class);

    app(AccountCreditService::class)->issueForCancelledMatch($this->match, $this->director);

    expect(AccountCredit::query()->where('type', 'cancellation')->count())->toBe(2)
        ->and($this->father->fresh()->accountCreditSummary()['posted'])->toBe(1400.0);

    $this->actingAs($this->father)
        ->get(route('registrations.show', $own))
        ->assertOk()
        ->assertSee('Entry credit: R 1,400.00');
});

it('does not credit a walk-in the range collected in cash', function () {
    paidEntry($this->match, $this->father, $this->father, 700, [
        'registration_source' => 'walk_in',
    ]);

    $issued = app(AccountCreditService::class)->issueForCancelledMatch($this->match);

    expect($issued)->toHaveCount(0)
        ->and($this->father->accountCreditSummary()['posted'])->toBe(0.0);
});

it('credits already-cancelled matches from the artisan command without doubling', function () {
    paidEntry($this->match, $this->father, $this->father, 700);
    paidEntry($this->match, $this->son, $this->father, 700);

    $this->artisan('matches:issue-cancellation-credits', ['match' => $this->match->id])
        ->assertSuccessful();

    expect($this->father->accountCreditSummary()['posted'])->toBe(1400.0);

    $this->artisan('matches:issue-cancellation-credits', ['match' => $this->match->id])
        ->assertSuccessful()
        ->expectsOutputToContain('already credited');
});

it('issues credit when an admin cancels the match', function () {
    $open = cancelledCreditMatch($this->director, 'Still Open', 'open');
    paidEntry($open, $this->father, $this->father, 700);

    $this->actingAs($this->director)
        ->put(route('matches.update', $open), [
            'name' => $open->name,
            'match_type' => 'PRS',
            'status' => 'cancelled',
        ])
        ->assertRedirect(route('matches.show', $open));

    expect($open->fresh()->status)->toBe('cancelled')
        ->and($this->father->accountCreditSummary()['posted'])->toBe(700.0);
});

it('pays a later entry from the credit and releases a partial hold if checkout is cancelled', function () {
    paidEntry($this->match, $this->father, $this->father, 700);
    paidEntry($this->match, $this->son, $this->father, 700);
    app(AccountCreditService::class)->issueForCancelledMatch($this->match);

    $next = cancelledCreditMatch($this->director, 'Next Match', 'open');
    $covered = unpaidEntry($next, $this->father, 700);

    stubCreditPayFast();

    $this->actingAs($this->father)
        ->get(route('registrations.show', $covered))
        ->assertOk()
        ->assertSee('Use entry credit — R 700.00');

    $this->actingAs($this->father)
        ->post(route('payments.registration', $covered))
        ->assertRedirect(route('registrations.show', $covered));

    expect($covered->fresh()->payment_status)->toBe('paid')
        ->and($this->father->accountCreditSummary()['posted'])->toBe(700.0);

    $partial = unpaidEntry($next, $this->father, 1000, ['shooter_name' => 'Stephan again']);

    $this->actingAs($this->father)
        ->post(route('payments.registration', $partial))
        ->assertRedirect();

    $card = Payment::query()
        ->where('payable_id', $partial->id)
        ->where('status', 'pending')
        ->first();

    expect($card)->not->toBeNull()
        ->and((float) $card->amount)->toBe(300.0)
        ->and($this->father->accountCreditSummary()['available'])->toBe(0.0)
        ->and($this->father->accountCreditSummary()['reserved'])->toBe(700.0);

    $this->actingAs($this->father)
        ->get(route('payments.cancel', ['m_payment_id' => $card->m_payment_id]))
        ->assertOk();

    expect($this->father->accountCreditSummary()['posted'])->toBe(700.0)
        ->and($this->father->accountCreditSummary()['reserved'])->toBe(0.0)
        ->and($partial->fresh()->payment_status)->not->toBe('paid');
});

function cancelledCreditMatch(User $director, string $name, string $status = 'cancelled'): MatchEvent
{
    return MatchEvent::create([
        'name' => $name,
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => (string) now()->year,
        'province_id' => Province::firstWhere('name', 'Gauteng')?->id,
        'match_date' => now()->addMonth()->toDateString(),
        'status' => $status,
        'published' => $status !== 'draft',
        'match_director' => 'Test Director',
        'active_member_fee' => 700,
        'non_member_fee' => 700,
        'lapsed_member_fee' => 700,
        'created_by' => $director->id,
    ]);
}

function paidEntry(MatchEvent $match, User $shooter, ?User $payer, float $fee, array $overrides = []): MatchRegistration
{
    $registration = unpaidEntry($match, $shooter, $fee, array_merge([
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ], $overrides));

    if ($payer && ($overrides['payment_status'] ?? 'paid') === 'paid') {
        Payment::create([
            'payable_type' => MatchRegistration::class,
            'payable_id' => $registration->id,
            'user_id' => $payer->id,
            'amount' => $fee,
            'gateway' => 'payfast',
            'm_payment_id' => Payment::generateReference('REG'),
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }

    return $registration;
}

function unpaidEntry(MatchEvent $match, User $shooter, float $fee, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => $fee,
        'payment_status' => 'unpaid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));
}

function stubCreditPayFast(): void
{
    $stub = new class extends PayFastService
    {
        public function __construct()
        {
            parent::__construct(app(SettingsService::class));
        }

        public function isEnabled(): bool
        {
            return true;
        }
    };

    app()->instance(PayFastService::class, $stub);
}
