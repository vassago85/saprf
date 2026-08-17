<?php

/**
 * MD / admin "Add Shooter" action from the match edit page
 * (POST /matches/{match}/entries).
 *
 * Contracts locked in here:
 *   1. MD of this match, owner, admin, exco, developer can add. Any other
 *      role (including MD of a *different* match) is blocked.
 *   2. Every seeded entry is confirmed + paid, gateway_fee is 0, and the
 *      md_net_amount is rebalanced accordingly (no phantom card fee).
 *   3. The waive-lapsed toggle only bites when the shooter is actually
 *      lapsed AND a reason is supplied; the reason ends up on
 *      fee_override_reason.
 *   4. Duplicate active entries for the same shooter are rejected.
 *   5. Managed juniors and inactive accounts are refused (family flow /
 *      no revival of stale accounts).
 */

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use Database\Seeders\DivisionCategorySeeder;

beforeEach(function () {
    seedRoles();
    $this->seed(DivisionCategorySeeder::class);

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    foreach ([
        'saprf_fee_type' => 'fixed', 'saprf_fee_value' => '50',
        'platform_fee_type' => 'fixed', 'platform_fee_value' => '0',
        'non_member_surcharge' => '200',
        'lapsed_member_surcharge' => '150',
        'estimated_gateway_fee_percentage' => '3.5',
        'estimated_gateway_flat_fee' => '2',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
    app(SettingsService::class)->clearCache();

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'MD Add-Entry Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'active_member_fee' => 550.00,
        'non_member_fee' => 750.00,
        'lapsed_member_fee' => 700.00,
        'created_by' => $this->md->id,
    ]);

    $ladies = Division::where('slug', 'ladies')->firstOrFail();
    $open = Division::where('slug', 'open')->firstOrFail();
    $this->match->divisions()->sync([$ladies->id, $open->id]);
    $this->ladies = $ladies;
    $this->open = $open;

    // A paid-up active shooter.
    $this->activeMember = User::factory()->create(['email_verified_at' => now()]);
    Membership::create([
        'user_id' => $this->activeMember->id,
        'saprf_number' => 'SAPRF-MDADD-1',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(6),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);

    // A lapsed shooter (expired 6 months ago).
    $this->lapsedMember = User::factory()->create(['email_verified_at' => now()]);
    Membership::create([
        'user_id' => $this->lapsedMember->id,
        'saprf_number' => 'SAPRF-MDADD-2',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYears(2),
        'expiry_date' => Carbon::today()->subMonths(6),
    ]);
});

it('lets the match director add an active member as confirmed and paid', function () {
    $response = $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ]);

    $response->assertRedirect(route('matches.edit', $this->match));
    $response->assertSessionHas('success');

    $registration = MatchRegistration::where('match_id', $this->match->id)
        ->where('user_id', $this->activeMember->id)
        ->firstOrFail();

    expect($registration->registration_status)->toBe('confirmed')
        ->and($registration->payment_status)->toBe('paid')
        ->and($registration->division_id)->toBe($this->ladies->id)
        ->and($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(550.0)
        ->and($registration->registered_by_user_id)->toBe($this->md->id);
});

it('books the gateway fee as zero and rebalances md_net for seeded entries', function () {
    // Money never crossed PayFast — the MD collected cash / EFT. Booking
    // the estimated gateway fee here would silently short the MD by the
    // full card-rate estimate.
    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('user_id', $this->activeMember->id)->firstOrFail();

    $expectedMdNet = round(
        (float) $registration->fee_amount
        - (float) $registration->saprf_fee
        - (float) $registration->platform_fee
        - (float) $registration->surcharge_amount,
        2
    );

    expect((float) $registration->gateway_fee)->toBe(0.0)
        ->and((float) $registration->md_net_amount)->toBe($expectedMdNet);
});

it('waives the lapsed surcharge for a lapsed member when a reason is supplied', function () {
    $reason = 'Paid R550 direct to MD; grace on lapsed renewal for this match.';

    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->lapsedMember->id,
            'division_id' => $this->open->id,
            'waive_lapsed_surcharge' => '1',
            'fee_override_reason' => $reason,
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('user_id', $this->lapsedMember->id)->firstOrFail();

    expect($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(550.0)
        ->and((float) $registration->surcharge_amount)->toBe(0.0)
        ->and($registration->fee_override_reason)->toBe($reason);
});

it('rejects waive-lapsed without a reason', function () {
    $response = $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->lapsedMember->id,
            'division_id' => $this->open->id,
            'waive_lapsed_surcharge' => '1',
            'fee_override_reason' => '   ',
        ]);

    $response->assertRedirect(route('matches.edit', $this->match));
    $response->assertSessionHasErrors('fee_override_reason');
    expect(MatchRegistration::where('user_id', $this->lapsedMember->id)->count())->toBe(0);
});

it('ignores waive-lapsed when the shooter is not actually lapsed', function () {
    // An admin ticking the box for an active member should NOT be able to
    // discount their entry — the toggle only relaxes a real surcharge.
    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
            'waive_lapsed_surcharge' => '1',
            'fee_override_reason' => 'trying to sneak in a discount',
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('user_id', $this->activeMember->id)->firstOrFail();

    // Active member paid R550 all along; no surcharge existed to waive.
    expect($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(550.0)
        ->and((float) $registration->surcharge_amount)->toBe(0.0);
});

it('refuses to double-book an active entry for the same shooter', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->activeMember->id,
        'shooter_name' => $this->activeMember->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 550,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match))
        ->assertSessionHas('info');

    // No second row created.
    expect(MatchRegistration::where('user_id', $this->activeMember->id)->count())->toBe(1);
});

it('rejects a division not offered by the match', function () {
    $factory = Division::where('slug', 'factory')->firstOrFail();
    // factory is NOT in the sync'd list (only ladies + open).

    $response = $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $factory->id,
        ]);

    $response->assertSessionHasErrors('division_id');
    expect(MatchRegistration::where('user_id', $this->activeMember->id)->count())->toBe(0);
});

it('refuses to seed a managed junior — the family flow is the correct path', function () {
    $parent = User::factory()->create(['email_verified_at' => now()]);
    $parent->assignRole('member');

    $junior = User::factory()->create([
        'is_managed_account' => true,
        'parent_id' => $parent->id,
        'managed_relationship' => 'junior',
    ]);

    $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $junior->id,
            'division_id' => $this->open->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect(MatchRegistration::where('user_id', $junior->id)->count())->toBe(0);
});

it('refuses to seed an inactive user', function () {
    $inactive = User::factory()->create(['is_active' => false]);

    $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $inactive->id,
            'division_id' => $this->open->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect(MatchRegistration::where('user_id', $inactive->id)->count())->toBe(0);
});

it('blocks a match director from adding entries to a match they do not own', function () {
    $otherMd = User::factory()->create(['email_verified_at' => now()]);
    $otherMd->assignRole('match_director');

    $this->actingAs($otherMd)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ])
        ->assertForbidden();

    expect(MatchRegistration::where('user_id', $this->activeMember->id)->count())->toBe(0);
});

it('blocks a plain member from adding entries', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    // A pure member can't even hit the route (role middleware on the group).
    $this->actingAs($member)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ])
        ->assertForbidden();

    expect(MatchRegistration::where('user_id', $this->activeMember->id)->count())->toBe(0);
});

it('lets an admin add an entry to any match', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match));

    expect(MatchRegistration::where('user_id', $this->activeMember->id)->count())->toBe(1);
});

it('writes an audit log entry for every seeded row', function () {
    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect();

    $audit = AuditLog::where('action_type', 'registration.admin_added')->firstOrFail();
    expect($audit->user_id)->toBe($this->md->id)
        ->and($audit->new_value)->toMatchArray([
            'seeded_via' => 'match_edit_ui',
        ]);
});

it('shows the Add-a-Shooter panel on the match edit page for the MD', function () {
    $this->actingAs($this->md)
        ->get(route('matches.edit', $this->match))
        ->assertOk()
        ->assertSee('Add a Shooter')
        ->assertSee(route('matches.entries.store', $this->match), escape: false)
        ->assertSee(route('events.members.search', $this->match), escape: false);
});

// ── New (not-on-the-platform) shooter path ──

it('lets the MD add a brand-new shooter (no user_id, name only) as paid + confirmed', function () {
    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'new_shooter_name' => 'Freshly Signed Up',
            'division_id' => $this->open->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match))
        ->assertSessionHas('success');

    $stub = User::where('name', 'Freshly Signed Up')->firstOrFail();
    expect($stub->email)->toBe('freshly.up@import.saprf.local')
        ->and((bool) $stub->is_active)->toBeTrue()
        ->and($stub->hasRole('member'))->toBeTrue();

    $registration = MatchRegistration::where('user_id', $stub->id)->firstOrFail();
    expect($registration->registration_status)->toBe('confirmed')
        ->and($registration->payment_status)->toBe('paid')
        // Fresh stub has a free-type membership → classifies as non_member,
        // so the R750 non-member rate applies at seed time.
        ->and($registration->membership_fee_category)->toBe('non_member')
        ->and((float) $registration->fee_amount)->toBe(750.0)
        ->and($registration->registered_by_user_id)->toBe($this->md->id);
});

it('attaches the entry to the supplied email if a real user already has it', function () {
    // MD types a name + email; the email already belongs to an active
    // member on the platform. The entry must land on THAT user's row,
    // not create a duplicate stub.
    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $this->match), [
            'new_shooter_name' => 'Whatever Name The MD Typed',
            'new_shooter_email' => $this->activeMember->email,
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect();

    $registration = MatchRegistration::where('user_id', $this->activeMember->id)->firstOrFail();
    expect($registration->registration_status)->toBe('confirmed')
        ->and((float) $registration->fee_amount)->toBe(550.0)
        ->and($registration->membership_fee_category)->toBe('active_member');

    expect(User::where('name', 'Whatever Name The MD Typed')->count())->toBe(0);
});

it('rejects a new-shooter POST when both user_id and new_shooter_name are supplied', function () {
    $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'user_id' => $this->activeMember->id,
            'new_shooter_name' => 'Someone Else',
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match))
        ->assertSessionHasErrors('user_id');

    expect(MatchRegistration::count())->toBe(0);
});

it('rejects a new-shooter POST when neither user_id nor new_shooter_name is supplied', function () {
    $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match))
        ->assertSessionHasErrors('user_id');

    expect(MatchRegistration::count())->toBe(0);
});

it('rejects a new-shooter POST with an invalid email', function () {
    $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'new_shooter_name' => 'Bad Email Shooter',
            'new_shooter_email' => 'not-a-real-email',
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match))
        ->assertSessionHasErrors('new_shooter_email');

    expect(User::where('name', 'Bad Email Shooter')->count())->toBe(0)
        ->and(MatchRegistration::count())->toBe(0);
});

it('rejects a new-shooter POST with a name shorter than 2 characters', function () {
    $this->actingAs($this->md)
        ->from(route('matches.edit', $this->match))
        ->post(route('matches.entries.store', $this->match), [
            'new_shooter_name' => 'X',
            'division_id' => $this->ladies->id,
        ])
        ->assertRedirect(route('matches.edit', $this->match))
        ->assertSessionHasErrors('new_shooter_name');
});
