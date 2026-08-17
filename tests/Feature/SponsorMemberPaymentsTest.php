<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Notifications\MatchRegistrationConfirmedNotification;
use App\Notifications\SponsoredEntryPaidNotification;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Sponsor flow: any signed-in SAPRF member can search the roster for
 * another member and either enter them (create the registration + pay)
 * or pay an existing unpaid entry. The family managed-account path is
 * untouched and still uses `for_user` with the parent's rifles / contact
 * details.
 */
beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->province = $province;
    $this->division = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->match = MatchEvent::create([
        'name' => 'Sponsor Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => (string) now()->year,
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 1000.00,
        'non_member_fee' => 1200.00,
        'lapsed_member_fee' => 1100.00,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->sponsor = User::factory()->create([
        'name' => 'Sam Sponsor',
        'province_id' => $province->id,
        'email_verified_at' => now(),
    ]);
    $this->sponsor->assignRole('member');

    $this->shooter = User::factory()->create([
        'name' => 'Bobby Shooter',
        'province_id' => $province->id,
        'email_verified_at' => now(),
    ]);
    $this->shooter->assignRole('member');
});

function stubPayFast(bool $enabled = true): void
{
    $stub = new class($enabled) extends PayFastService {
        public function __construct(private readonly bool $enabled)
        {
            parent::__construct(app(SettingsService::class));
        }

        public function isEnabled(): bool
        {
            return $this->enabled;
        }
    };

    app()->instance(PayFastService::class, $stub);
}

function makeShooterEntry(User $shooter, MatchEvent $match, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1000.00,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ], $overrides));
}

// ── Member search ──

it('finds a member by name for the sponsor search', function () {
    $this->actingAs($this->sponsor)
        ->getJson(route('events.members.search', ['match' => $this->match, 'q' => 'Bobby']))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Bobby Shooter'])
        ->assertJsonPath('results.0.entry_state', 'none');
});

it('finds a member by SAPRF number for the sponsor search', function () {
    Membership::create([
        'user_id' => $this->shooter->id,
        'saprf_number' => '4242',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subMonths(3)->toDateString(),
        'expiry_date' => now()->addMonths(9)->toDateString(),
    ]);

    $this->actingAs($this->sponsor)
        ->getJson(route('events.members.search', ['match' => $this->match, 'q' => '4242']))
        ->assertOk()
        ->assertJsonFragment(['saprf_number' => '4242']);
});

it('does not leak email or phone from the sponsor search', function () {
    $this->shooter->update(['phone' => '0821234567']);

    $response = $this->actingAs($this->sponsor)
        ->getJson(route('events.members.search', ['match' => $this->match, 'q' => 'Bobby']));

    $response->assertOk()
        ->assertDontSee($this->shooter->email)
        ->assertDontSee('0821234567');
});

it('excludes the actor from the sponsor search', function () {
    $this->actingAs($this->sponsor)
        ->getJson(route('events.members.search', ['match' => $this->match, 'q' => 'Sam']))
        ->assertOk()
        ->assertJsonPath('results', []);
});

it('rejects the sponsor search from a guest', function () {
    $this->getJson(route('events.members.search', ['match' => $this->match, 'q' => 'Bobby']))
        ->assertStatus(401);
});

it('reports an existing unpaid entry so the sponsor can pay it', function () {
    makeShooterEntry($this->shooter, $this->match, ['payment_status' => 'unpaid']);

    $this->actingAs($this->sponsor)
        ->getJson(route('events.members.search', ['match' => $this->match, 'q' => 'Bobby']))
        ->assertOk()
        ->assertJsonPath('results.0.entry_state', 'unpaid');
});

// ── Sponsor enter-and-pay ──

it('creates a sponsored entry for the shooter with the sponsor as the payer', function () {
    stubPayFast();
    Notification::fake();

    $response = $this->actingAs($this->sponsor)
        ->post(route('events.register.store', $this->match), [
            'for_user' => $this->shooter->id,
            'division_id' => $this->division->id,
        ]);

    $registration = MatchRegistration::where('user_id', $this->shooter->id)->firstOrFail();
    $payment = Payment::where('payable_id', $registration->id)->firstOrFail();

    expect($registration->registered_by_user_id)->toBe($this->sponsor->id)
        ->and($payment->user_id)->toBe($this->sponsor->id)
        ->and((float) $payment->amount)->toBe(1000.00)
        ->and($registration->email)->toBe($this->shooter->email);

    $response->assertRedirect(route('payments.redirect', $payment));

    Notification::assertSentTo(
        $this->shooter,
        MatchRegistrationConfirmedNotification::class,
    );
});

it('lets the shooter change division on a sponsored entry', function () {
    $tactical = Division::create(['slug' => 'tac', 'name' => 'Tactical', 'is_active' => true, 'display_order' => 2]);

    $registration = makeShooterEntry($this->shooter, $this->match, [
        'division_id' => $this->division->id,
        'registered_by_user_id' => $this->sponsor->id,
    ]);

    $this->actingAs($this->shooter)
        ->put(route('registrations.update-division', $registration), ['division_id' => $tactical->id])
        ->assertRedirect(route('registrations.show', $registration));

    expect($registration->fresh()->division_id)->toBe($tactical->id);
});

it('does not create a duplicate entry when the shooter already has one', function () {
    stubPayFast();

    $existing = makeShooterEntry($this->shooter, $this->match);

    $this->actingAs($this->sponsor)
        ->post(route('events.register.store', $this->match), [
            'for_user' => $this->shooter->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect(route('registrations.show', $existing));

    expect(MatchRegistration::where('user_id', $this->shooter->id)->count())->toBe(1);
});

it('rejects sponsoring a managed junior belonging to another family', function () {
    $otherParent = User::factory()->create(['province_id' => $this->province->id]);
    $junior = User::factory()->create([
        'parent_id' => $otherParent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
    ]);

    $this->actingAs($this->sponsor)
        ->post(route('events.register.store', $this->match), [
            'for_user' => $junior->id,
            'division_id' => $this->division->id,
        ])
        ->assertForbidden();

    expect(MatchRegistration::where('user_id', $junior->id)->count())->toBe(0);
});

// ── Family isolation still holds ──

it('still lets a parent enter a managed junior via for_user (family path)', function () {
    stubPayFast();

    $junior = User::factory()->create([
        'parent_id' => $this->sponsor->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

    $this->actingAs($this->sponsor)
        ->post(route('events.register.store', $this->match), [
            'for_user' => $junior->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('user_id', $junior->id)->firstOrFail();

    expect($registration->registered_by_user_id)->toBe($this->sponsor->id)
        // Managed juniors carry a placeholder email — the entry must fall back
        // to the parent's real email so notifications actually deliver.
        ->and($registration->email)->toBe($this->sponsor->email);
});

// ── Sponsor pay existing entry ──

it('notifies the shooter when a sponsor pays for their entry', function () {
    Notification::fake();

    $registration = makeShooterEntry($this->shooter, $this->match, ['payment_status' => 'unpaid']);
    $payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->sponsor->id,
        'amount' => 1000.00,
        'm_payment_id' => 'REG-SPONSOR-1',
        'status' => 'pending',
    ]);

    // Emulate a successful PayFast ITN by calling the same handler path.
    $reflection = new \ReflectionClass(\App\Http\Controllers\PaymentController::class);
    $controller = app(\App\Http\Controllers\PaymentController::class);

    $payment->update([
        'status' => 'completed',
        'paid_at' => now(),
        'amount_gross' => 1000.00,
        'amount_fee' => 20.00,
        'amount_net' => 980.00,
    ]);

    $method = $reflection->getMethod('handleSuccessfulPayment');
    $method->setAccessible(true);
    $method->invoke($controller, $payment->fresh());

    Notification::assertSentTo($this->shooter, SponsoredEntryPaidNotification::class);
    expect($registration->fresh()->payment_status)->toBe('paid');
});
