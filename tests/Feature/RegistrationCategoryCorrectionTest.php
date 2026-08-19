<?php

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    foreach ([
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => '0',
        'non_member_surcharge' => '250',
        'lapsed_member_surcharge' => '10',
        'estimated_gateway_fee_percentage' => '3.5',
        'estimated_gateway_flat_fee' => '2',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    app(SettingsService::class)->clearCache();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('admin');

    $this->open = Division::create([
        'slug' => 'open',
        'name' => 'Open',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->match = MatchEvent::create([
        'name' => 'Category Correction Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 1190.00,
        'non_member_fee' => 1440.00,
        'lapsed_member_fee' => 1200.00,
        'created_by' => $this->admin->id,
    ]);

    $this->shooter = User::factory()->create(['email_verified_at' => now()]);
    $this->shooter->assignRole('member');
    Membership::create([
        'user_id' => $this->shooter->id,
        'saprf_number' => 'SAPRF-CORR-001',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->subMonth(),
    ]);
});

function lapsedRegistration(User $shooter, MatchEvent $match, ?int $divisionId = null, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'division_id' => $divisionId,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'lapsed_member',
        'fee_amount' => 1200.00,
        'surcharge_amount' => 10.00,
        'saprf_fee' => 50.00,
        'platform_fee' => 0.00,
        'gateway_fee' => 44.00,
        'md_net_amount' => 1096.00,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ], $overrides));
}

it('lets an admin recategorise an unpaid lapsed entry to active and drop the surcharge', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id);
    $reason = 'Member was not lapsed at the time of registration.';

    $this->actingAs($this->admin)
        ->put(route('registrations.update-category', $registration), [
            'membership_fee_category' => 'active_member',
            'fee_override_reason' => $reason,
        ])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('success');

    $registration->refresh();

    expect($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(1190.00)
        ->and((float) $registration->surcharge_amount)->toBe(0.0)
        ->and($registration->fee_override_reason)->toBe($reason);

    $audit = AuditLog::where('action_type', 'registration.category.updated')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->entity_id)->toBe($registration->id)
        ->and($audit->reason)->toBe($reason);
});

it('requires a written reason so the override is never silent', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id);

    $this->actingAs($this->admin)
        ->from(route('registrations.show', $registration))
        ->put(route('registrations.update-category', $registration), [
            'membership_fee_category' => 'active_member',
            'fee_override_reason' => '',
        ])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHasErrors('fee_override_reason');

    $registration->refresh();
    expect($registration->membership_fee_category)->toBe('lapsed_member')
        ->and((float) $registration->surcharge_amount)->toBe(10.00);
});

it('refuses to recategorise a paid registration', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id, [
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
    ]);

    $this->actingAs($this->admin)
        ->from(route('registrations.show', $registration))
        ->put(route('registrations.update-category', $registration), [
            'membership_fee_category' => 'active_member',
            'fee_override_reason' => 'Should not apply after payment.',
        ])
        ->assertRedirect(route('registrations.show', $registration))
        ->assertSessionHas('error');

    $registration->refresh();
    expect($registration->membership_fee_category)->toBe('lapsed_member')
        ->and((float) $registration->fee_amount)->toBe(1200.00);
});

it('blocks the shooter from changing their own fee category', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id);

    $this->actingAs($this->shooter)
        ->put(route('registrations.update-category', $registration), [
            'membership_fee_category' => 'active_member',
            'fee_override_reason' => 'Trying to waive my own surcharge.',
        ])
        ->assertForbidden();
});

it('cancels a pending checkout so Pay Now uses the corrected amount', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id);

    $stale = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $this->shooter->id,
        'amount' => 1200.00,
        'm_payment_id' => 'REG-STALE-1200',
        'status' => 'pending',
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.update-category', $registration), [
            'membership_fee_category' => 'active_member',
            'fee_override_reason' => 'Member was not lapsed at the time of registration.',
        ])
        ->assertRedirect();

    $stale->refresh();
    expect($stale->status)->toBe('cancelled');

    $stub = new class extends PayFastService {
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

    $this->actingAs($this->shooter)
        ->post(route('payments.registration', $registration))
        ->assertRedirect();

    $fresh = Payment::where('payable_id', $registration->id)
        ->where('status', 'pending')
        ->latest('id')
        ->firstOrFail();

    expect((float) $fresh->amount)->toBe(1190.00)
        ->and($fresh->id)->not->toBe($stale->id);
});

it('shows the correct-category form to staff on an unpaid entry', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id);

    $this->actingAs($this->admin)
        ->get(route('registrations.show', $registration))
        ->assertOk()
        ->assertSee('Correct Category')
        ->assertSee('Save Category');
});

it('previews a reprice in dry-run and applies with --apply', function () {
    $registration = lapsedRegistration($this->shooter, $this->match, $this->open->id);
    $reason = 'Member was not lapsed at the time of registration.';

    $this->artisan('registrations:reprice', [
        'id' => $registration->id,
        '--category' => 'active_member',
        '--reason' => $reason,
    ])->assertSuccessful();

    $registration->refresh();
    expect($registration->membership_fee_category)->toBe('lapsed_member')
        ->and((float) $registration->surcharge_amount)->toBe(10.00);

    $this->artisan('registrations:reprice', [
        'id' => $registration->id,
        '--category' => 'active_member',
        '--reason' => $reason,
        '--apply' => true,
    ])->assertSuccessful();

    $registration->refresh();
    expect($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(1190.00)
        ->and((float) $registration->surcharge_amount)->toBe(0.0)
        ->and($registration->fee_override_reason)->toBe($reason);
});
