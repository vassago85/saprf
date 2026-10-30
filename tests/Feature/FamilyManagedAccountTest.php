<?php

use App\Models\Club;
use App\Models\Division;
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
    $this->division = Division::create(['slug' => 'open', 'name' => 'Open', 'display_order' => 1]);

    $this->parent = User::factory()->create([
        'province_id' => $this->province->id,
        'email_verified_at' => now(),
    ]);
    $this->parent->assignRole('member');
});

it('lets a user add a spouse without a date of birth', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Sarah Britnell',
            'relationship' => 'spouse',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect(route('family.index'));

    $spouse = User::where('name', 'Sarah Britnell')->first();

    expect($spouse)->not->toBeNull()
        ->and($spouse->parent_id)->toBe($this->parent->id)
        ->and($spouse->is_managed_account)->toBeTrue()
        ->and($spouse->managed_relationship)->toBe('spouse')
        ->and($spouse->date_of_birth)->toBeNull()
        ->and($spouse->hasRole('member'))->toBeTrue();
});

it('requires a date of birth for junior accounts', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Conner Britnell',
            'relationship' => 'junior',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertSessionHasErrors('date_of_birth');

    expect(User::where('name', 'Conner Britnell')->exists())->toBeFalse();
});

it('rejects an unknown relationship', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Someone',
            'relationship' => 'cousin-twice-removed',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertSessionHasErrors('relationship');
});

it('lets the account holder hand over a managed adult account', function () {
    $spouse = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'spouse',
        'province_id' => $this->province->id,
    ]);

    $this->actingAs($this->parent)
        ->post(route('family.handover.start', $spouse), [
            'handover_email' => 'sarah@example.com',
        ])
        ->assertRedirect(route('family.show', $spouse));

    $spouse->refresh();

    expect($spouse->handover_email)->toBe('sarah@example.com')
        ->and($spouse->hasPendingHandover())->toBeTrue();
});

it('cannot manage a family member belonging to another account', function () {
    $otherParent = User::factory()->create(['province_id' => $this->province->id]);

    $theirChild = User::factory()->create([
        'parent_id' => $otherParent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
    ]);

    $this->actingAs($this->parent)
        ->get(route('family.show', $theirChild))
        ->assertForbidden();
});

it('lets a parent remove a managed account that has no scores or active registrations', function () {
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
    ]);

    $this->actingAs($this->parent)
        ->delete(route('family.destroy', $junior))
        ->assertRedirect(route('family.index'));

    expect(User::find($junior->id))->toBeNull()
        ->and(User::withTrashed()->find($junior->id))->not->toBeNull();
});

it('refuses to remove a managed account that has recorded scores', function () {
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
    ]);

    // Give the junior a score so removal is blocked.
    $match = \App\Models\MatchEvent::create([
        'name' => 'Guard Test Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'provincial',
        'season' => (string) now()->year,
        'match_date' => \Carbon\Carbon::today()->subMonth(),
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'created_by' => $this->parent->id,
    ]);
    \App\Models\Score::create([
        'match_id' => $match->id,
        'user_id' => $junior->id,
        'shooter_name' => $junior->name,
        'raw_score' => 85.0,
        'division_id' => $this->division->id,
        'status' => 'valid',
        'match_date' => $match->match_date->toDateString(),
    ]);

    $this->actingAs($this->parent)
        ->delete(route('family.destroy', $junior))
        ->assertRedirect(route('family.show', $junior))
        ->assertSessionHas('error');

    expect(User::find($junior->id))->not->toBeNull();
});

it('exposes all managed accounts and relationship labels', function () {
    User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
    ]);
    $spouse = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'spouse',
    ]);

    expect($this->parent->managedAccounts()->count())->toBe(2)
        ->and($spouse->managedRelationshipLabel())->toBe('Spouse / Partner')
        ->and($spouse->isJuniorAccount())->toBeFalse();
});

it('lets a member open the add-family-member form', function () {
    $this->actingAs($this->parent)
        ->get(route('family.create'))
        ->assertOk()
        ->assertSee('Add a Family Member');
});

it('shows each family member SAPRF number on the family list and profile', function () {
    $junior = User::factory()->create([
        'name' => 'Conner Britnell',
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(12)->toDateString(),
    ]);
    Membership::create([
        'user_id' => $junior->id,
        'saprf_number' => '1842',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2027-01-01',
    ]);

    $this->actingAs($this->parent)
        ->get(route('family.index'))
        ->assertOk()
        ->assertSee('SAPRF number')
        ->assertSee('1842');

    $this->actingAs($this->parent)
        ->get(route('family.show', $junior))
        ->assertOk()
        ->assertSee('SAPRF number')
        ->assertSee('1842')
        ->assertSee('Copy');
});

it('does not send members to the admin membership create page from a family profile', function () {
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

    $this->actingAs($this->parent)
        ->get(route('family.show', $junior))
        ->assertOk()
        ->assertSee(route('my-membership', ['for_user' => $junior->id]), false)
        ->assertDontSee(route('memberships.create'), false);
});

it('lets a member apply for membership on behalf of a family account', function () {
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
    ]);

    $this->actingAs($this->parent)
        ->get(route('my-membership', ['for_user' => $junior->id]))
        ->assertOk()
        ->assertSee($junior->name)
        ->assertDontSee('User does not have the right roles');
});

it('creates a membership for the family member and charges the parent', function () {
    // Junior with a DOB in range so the age-gated tier picker resolves to
    // the seeded Junior tier at R425 (half the R850 adult rate). Historic
    // form of this test used a hand-rolled R500 "Standard" tier on a spouse
    // account with no DOB — both the tier picker and the DOB gate now block
    // that path.
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
        'province_id' => $this->province->id,
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]);

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

    $this->actingAs($this->parent)
        ->post(route('membership.join'), ['for_user' => $junior->id])
        ->assertRedirect();

    $juniorTier = MembershipFeeTier::where('slug', 'junior')->firstOrFail();
    $membership = Membership::where('user_id', $junior->id)->first();
    $payment = Payment::where('payable_type', Membership::class)->first();

    expect($membership)->not->toBeNull()
        ->and($membership->user_id)->toBe($junior->id)
        ->and($membership->fee_tier_id)->toBe($juniorTier->id)
        ->and(Membership::where('user_id', $this->parent->id)->exists())->toBeFalse()
        ->and($payment)->not->toBeNull()
        ->and($payment->user_id)->toBe($this->parent->id)
        ->and($payment->payable_id)->toBe($membership->id)
        ->and((float) $payment->amount)->toBe(425.0);
});

it('gives a free non-member dependant to a free registrant', function () {
    Membership::create([
        'user_id' => $this->parent->id,
        'membership_type' => 'free',
        'status' => 'lapsed',
        'payment_status' => 'unpaid',
        'expiry_date' => '2026-07-31',
    ]);

    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Juan Kriel',
            'relationship' => 'junior',
            'date_of_birth' => '2014-03-12',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect(route('family.index'));

    $child = User::where('name', 'Juan Kriel')->first();
    expect($child)->not->toBeNull()
        ->and($child->parent_id)->toBe($this->parent->id)
        ->and($child->is_managed_account)->toBeTrue()
        ->and($child->membership)->not->toBeNull()
        ->and($child->membership->membership_type)->toBe('free')
        ->and($child->membership->payment_status)->toBe('unpaid')
        ->and($child->membership->isActiveMember())->toBeFalse();

    $this->actingAs($this->parent)
        ->get(route('family.index'))
        ->assertOk()
        ->assertSee('Non-member');
});

it('gives a free non-member dependant to a guardian with no membership', function () {
    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Sam Kriel',
            'relationship' => 'junior',
            'date_of_birth' => '2016-01-02',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect(route('family.index'));

    $child = User::where('name', 'Sam Kriel')->first();
    expect($child->membership->membership_type)->toBe('free')
        ->and($child->membership->isActiveMember())->toBeFalse();
});

it('does not turn a paying member\'s new dependant into a free registrant', function () {
    $club = Club::create([
        'name' => 'Highveld Precision Rifle Club',
        'slug' => 'highveld-precision-rifle-club',
        'abbreviation' => 'HPRC',
        'province_id' => $this->province->id,
        'saprf_recognised' => true,
    ]);
    $this->parent->update([
        'date_of_birth' => '1985-04-01',
        'gender' => 'male',
        'ethnicity' => 'white',
        'club_id' => $club->id,
        'country_of_residence' => 'ZA',
        'sa_id_number' => '8504015009087',
        'sa_citizen' => true,
        'previously_disadvantaged' => false,
    ]);
    Membership::create([
        'user_id' => $this->parent->id,
        'saprf_number' => '1604',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2027-01-01',
    ]);

    $this->actingAs($this->parent)
        ->post(route('family.store'), [
            'name' => 'Paid Child',
            'relationship' => 'junior',
            'date_of_birth' => '2015-05-05',
            'province_id' => $this->province->id,
            'division_id' => $this->division->id,
        ])
        ->assertRedirect(route('family.index'));

    $child = User::where('name', 'Paid Child')->first();
    expect($child->is_managed_account)->toBeTrue()
        ->and($child->membership)->toBeNull();
});

it('rejects applying for membership on someone else\'s family account', function () {
    $otherParent = User::factory()->create(['province_id' => $this->province->id]);
    $theirChild = User::factory()->create([
        'parent_id' => $otherParent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'junior',
    ]);

    $this->actingAs($this->parent)
        ->get(route('my-membership', ['for_user' => $theirChild->id]))
        ->assertForbidden();
});
