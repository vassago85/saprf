<?php

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
    $junior = User::factory()->create([
        'parent_id' => $this->parent->id,
        'is_managed_account' => true,
        'managed_relationship' => 'spouse',
        'province_id' => $this->province->id,
    ]);

    MembershipFeeTier::create([
        'name' => 'Standard',
        'slug' => 'standard',
        'price' => 500,
        'duration_months' => 12,
        'is_active' => true,
        'is_default' => true,
        'display_order' => 1,
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

    $membership = Membership::where('user_id', $junior->id)->first();
    $payment = Payment::where('payable_type', Membership::class)->first();

    expect($membership)->not->toBeNull()
        ->and($membership->user_id)->toBe($junior->id)
        ->and(Membership::where('user_id', $this->parent->id)->exists())->toBeFalse()
        ->and($payment)->not->toBeNull()
        ->and($payment->user_id)->toBe($this->parent->id)
        ->and($payment->payable_id)->toBe($membership->id);
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
