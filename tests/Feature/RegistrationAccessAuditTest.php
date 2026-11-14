<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $settings = app(SettingsService::class);
    foreach ([
        'withdrawal_admin_fee' => '100',
        'withdrawal_deadline_hours' => '72',
        'withdrawal_developer_fee' => '50',
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::create(['slug' => 'open-audit', 'name' => 'Open', 'is_active' => true, 'display_order' => 1]);
    $this->factory = Division::create(['slug' => 'factory-audit', 'name' => 'Factory', 'is_active' => true, 'display_order' => 2]);

    $director = User::factory()->create();
    $this->match = MatchEvent::create([
        'name' => 'Access Audit Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 500.00,
        'non_member_fee' => 500.00,
        'created_by' => $director->id,
    ]);
});

it('does not let a pending checkout open another members registration', function () {
    $shooter = accessAuditMember('secret.shooter@example.test');
    $stranger = accessAuditMember('stranger@example.test');
    $registration = accessAuditUnpaid($this->match, $shooter, $this->open);

    accessAuditStubPayFast();

    $this->actingAs($stranger)
        ->post(route('payments.registration', $registration))
        ->assertRedirect();

    expect(Payment::query()->where('user_id', $stranger->id)->where('status', 'pending')->count())->toBe(1);

    $this->actingAs($stranger)
        ->get(route('registrations.show', $registration))
        ->assertForbidden()
        ->assertDontSee('secret.shooter@example.test');

    $this->actingAs($shooter)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('secret.shooter@example.test');
});

it('still lets someone who has paid for an entry open the receipt', function () {
    $shooter = accessAuditMember('paid.shooter@example.test');
    $sponsor = accessAuditMember('sponsor@example.test');
    $registration = accessAuditUnpaid($this->match, $shooter, $this->open);

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $sponsor->id,
        'amount' => 500.00,
        'm_payment_id' => Payment::generateReference('REG'),
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $this->actingAs($sponsor)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('paid.shooter@example.test');
});

it('lets a parent withdraw a managed juniors entry and change its division', function () {
    $parent = accessAuditMember('parent@example.test');
    $junior = User::factory()->create([
        'name' => 'Junior Shooter',
        'email' => 'junior@example.test',
        'is_managed_account' => true,
        'parent_id' => $parent->id,
        'managed_relationship' => 'junior',
    ]);
    $registration = accessAuditPaid($this->match, $junior, $parent, $this->open);

    $this->actingAs($parent)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('Change Division')
        ->assertSee('Confirm Withdrawal');

    $this->actingAs($parent)
        ->put(route('registrations.update-division', $registration), [
            'division_id' => $this->factory->id,
        ])
        ->assertRedirect(route('registrations.show', $registration));

    expect($registration->fresh()->division_id)->toBe($this->factory->id);

    $this->actingAs($parent)
        ->post(route('registrations.withdraw', $registration), [
            'withdrawal_option' => 'credit',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($registration->fresh()->registration_status)->toBe('cancelled');
});

it('does not let a sponsor withdraw an independent members entry', function () {
    $shooter = accessAuditMember('independent@example.test');
    $sponsor = accessAuditMember('helpful@example.test');
    $registration = accessAuditPaid($this->match, $shooter, $sponsor, $this->open);

    $this->actingAs($sponsor)
        ->post(route('registrations.withdraw', $registration), [
            'withdrawal_option' => 'credit',
        ])
        ->assertForbidden();

    expect($registration->fresh()->registration_status)->toBe('confirmed');
});

function accessAuditMember(string $email): User
{
    $user = User::factory()->create([
        'email' => $email,
        'email_verified_at' => now(),
    ]);
    $user->assignRole('member');

    return $user;
}

function accessAuditUnpaid(MatchEvent $match, User $shooter, Division $division): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'division_id' => $division->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500.00,
        'saprf_fee' => 50.00,
        'platform_fee' => 50.00,
        'gateway_fee' => 19.50,
        'md_net_amount' => 380.50,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);
}

function accessAuditPaid(MatchEvent $match, User $shooter, User $payer, Division $division): MatchRegistration
{
    $registration = MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'registered_by_user_id' => $payer->id === $shooter->id ? null : $payer->id,
        'division_id' => $division->id,
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

function accessAuditStubPayFast(): void
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
