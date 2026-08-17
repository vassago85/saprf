<?php

/**
 * Age-gated membership fee tiers.
 *
 * We introduced a Junior tier at R150 for under-18 shooters and gated the
 * existing Senior tier to 65+. Applicants missing a DOB are bounced to set
 * one before we take their money — silently defaulting to Adult (R850) for
 * a shooter who should pay R150 is a real revenue leak this test guards.
 *
 * Non-goals: shooter age divisions (`junior`/`senior` on the divisions
 * table) and `membership_fee_category` on match registrations are UNAFFECTED
 * and NOT tested here.
 */

use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Services\PayFastService;
use App\Services\SettingsService;

beforeEach(function () {
    seedRoles();
    $this->province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);

    $this->parent = User::factory()->create([
        'province_id' => $this->province->id,
        'email_verified_at' => now(),
        'date_of_birth' => now()->subYears(40)->toDateString(),
    ]);
    $this->parent->assignRole('member');

    // The seed migration should already have created adult / mil-leo /
    // senior / junior tiers with their age gates in place — assert that
    // so a broken migration doesn't make every downstream assertion
    // silently degenerate.
    expect(MembershipFeeTier::where('slug', 'junior')->value('max_age'))->toBe(17)
        ->and(MembershipFeeTier::where('slug', 'senior')->value('min_age'))->toBe(65)
        ->and(MembershipFeeTier::where('slug', 'adult')->value('min_age'))->toBe(18)
        ->and(MembershipFeeTier::where('slug', 'adult')->value('max_age'))->toBeNull();
});

function stubPayFast(): void
{
    $stub = new class(true) extends PayFastService {
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

// ── Model: availableForUser ───────────────────────────────────────

it('returns only the junior tier for a 14 year-old', function () {
    $junior = User::factory()->create([
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

    $available = MembershipFeeTier::availableForUser($junior);

    expect($available->pluck('slug')->all())->toBe(['junior']);
});

it('returns adult and mil-leo for a 30 year-old (junior + senior filtered out)', function () {
    $adult = User::factory()->create([
        'date_of_birth' => now()->subYears(30)->toDateString(),
    ]);

    $available = MembershipFeeTier::availableForUser($adult)->pluck('slug')->sort()->values();

    expect($available->all())->toBe(['adult', 'military-law-enforcement']);
});

it('returns adult, mil-leo and senior for a 65 year-old', function () {
    $senior = User::factory()->create([
        'date_of_birth' => now()->subYears(65)->toDateString(),
    ]);

    $available = MembershipFeeTier::availableForUser($senior)->pluck('slug')->sort()->values();

    expect($available->all())->toBe(['adult', 'military-law-enforcement', 'senior']);
});

it('returns nothing for a user with no date of birth (DOB gate is the only path)', function () {
    // Every seeded tier has an age floor (Junior 0–17, Adult/Mil-LEO 18+,
    // Senior 65+), so a null-DOB user matches none of them. The controller
    // is expected to intercept this case with the DOB-required redirect
    // before ever hitting the picker — this test guards that no free
    // "unrestricted" path silently slips through.
    $noDob = User::factory()->create(['date_of_birth' => null]);

    $available = MembershipFeeTier::availableForUser($noDob);

    expect($available)->toBeEmpty();
});

// ── Controller: joinMembership ────────────────────────────────────

it('rejects a fee_tier_id that is not allowed for the applicant\'s age', function () {
    stubPayFast();

    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

    $adultTier = MembershipFeeTier::where('slug', 'adult')->firstOrFail();

    $this->actingAs($this->parent)
        ->from(route('my-membership', ['for_user' => $junior->id]))
        ->post(route('membership.join'), [
            'for_user' => $junior->id,
            'fee_tier_id' => $adultTier->id,
        ])
        ->assertSessionHasErrors('fee_tier_id');

    expect(Membership::where('user_id', $junior->id)->exists())->toBeFalse()
        ->and(Payment::where('payable_type', Membership::class)->count())->toBe(0);
});

it('auto-picks the junior tier at R150 when a parent enrols a 14 year-old with no tier', function () {
    stubPayFast();

    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

    $this->actingAs($this->parent)
        ->post(route('membership.join'), ['for_user' => $junior->id])
        ->assertRedirect();

    $juniorTier = MembershipFeeTier::where('slug', 'junior')->firstOrFail();
    $membership = Membership::where('user_id', $junior->id)->firstOrFail();
    $payment = Payment::where('payable_type', Membership::class)
        ->where('payable_id', $membership->id)
        ->firstOrFail();

    expect($membership->fee_tier_id)->toBe($juniorTier->id)
        ->and((float) $payment->amount)->toBe(150.0)
        ->and($payment->user_id)->toBe($this->parent->id);
});

it('rejects a 60 year-old actor trying to pick the senior tier', function () {
    stubPayFast();

    $actor = User::factory()->create([
        'email_verified_at' => now(),
        'date_of_birth' => now()->subYears(60)->toDateString(),
    ]);
    $actor->assignRole('member');

    $seniorTier = MembershipFeeTier::where('slug', 'senior')->firstOrFail();

    $this->actingAs($actor)
        ->from(route('my-membership'))
        ->post(route('membership.join'), [
            'fee_tier_id' => $seniorTier->id,
        ])
        ->assertSessionHasErrors('fee_tier_id');

    expect(Membership::where('user_id', $actor->id)->exists())->toBeFalse();
});

it('lets a 66 year-old actor pick the senior tier', function () {
    stubPayFast();

    $actor = User::factory()->create([
        'email_verified_at' => now(),
        'date_of_birth' => now()->subYears(66)->toDateString(),
    ]);
    $actor->assignRole('member');

    $seniorTier = MembershipFeeTier::where('slug', 'senior')->firstOrFail();

    $this->actingAs($actor)
        ->post(route('membership.join'), [
            'fee_tier_id' => $seniorTier->id,
        ])
        ->assertRedirect();

    $membership = Membership::where('user_id', $actor->id)->firstOrFail();
    expect($membership->fee_tier_id)->toBe($seniorTier->id);
});

// ── DOB gate ──────────────────────────────────────────────────────

it('redirects a parent enrolling a managed junior with no date of birth to the family edit page', function () {
    stubPayFast();

    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => null,
    ]);

    $this->actingAs($this->parent)
        ->post(route('membership.join'), ['for_user' => $junior->id])
        ->assertRedirect(route('family.edit', $junior))
        ->assertSessionHas('error');

    expect(Membership::where('user_id', $junior->id)->exists())->toBeFalse();
});

it('redirects an actor with no date of birth to their profile', function () {
    stubPayFast();

    $actor = User::factory()->create([
        'email_verified_at' => now(),
        'date_of_birth' => null,
    ]);
    $actor->assignRole('member');

    $this->actingAs($actor)
        ->post(route('membership.join'))
        ->assertRedirect(route('profile'))
        ->assertSessionHas('error');

    expect(Membership::where('user_id', $actor->id)->exists())->toBeFalse();
});

// ── my-membership render side ────────────────────────────────────

it('shows only the junior tier when a parent views my-membership for a 14 year-old', function () {
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

    app(SettingsService::class)->set('payments_enabled', '1');
    stubPayFast();

    $this->actingAs($this->parent)
        ->get(route('my-membership', ['for_user' => $junior->id]))
        ->assertOk()
        ->assertSee('Junior Membership')
        ->assertSee('R 150.00')
        ->assertDontSee('R 850.00')
        ->assertDontSee('Senior');
});
