<?php

use App\Models\Membership;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Support\KrielAccountsRepair;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
});

it('links an existing free account as a free guardian\'s dependant', function () {
    $guardian = User::factory()->create(['email' => 'theo@example.com']);
    $guardian->assignRole('member');
    Membership::create([
        'user_id' => $guardian->id,
        'saprf_number' => '1604',
        'membership_type' => 'free',
        'status' => 'lapsed',
        'payment_status' => 'unpaid',
        'expiry_date' => '2026-07-31',
    ]);

    $child = User::factory()->create(['email' => 'juan@example.com', 'name' => 'Juan Kriel']);
    $child->assignRole('member');
    Membership::create([
        'user_id' => $child->id,
        'saprf_number' => '1990',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => '2027-01-08',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('owner');

    $this->actingAs($admin)
        ->put(route('user-management.update-guardian', $child), [
            'guardian_email' => 'theo@example.com',
            'managed_relationship' => 'junior',
        ])
        ->assertRedirect(route('user-management.edit', $child));

    $child->refresh();
    expect($child->parent_id)->toBe($guardian->id)
        ->and($child->is_managed_account)->toBeTrue()
        ->and($child->managed_relationship)->toBe('junior')
        ->and($child->membership->membership_type)->toBe('free')
        ->and($child->membership->saprf_number)->toBe('1990')
        ->and($child->email)->toBe('juan@example.com');
});

it('moves Theo onto his own email, keeps Juan as his free junior, and sends Theo an activation email', function () {
    Notification::fake();

    $theo = User::factory()->create([
        'name' => 'Theo Kriel',
        'email' => 'theo.kriel@import.saprf.local',
    ]);
    Membership::create([
        'user_id' => $theo->id,
        'saprf_number' => '1604',
        'membership_type' => 'free',
        'status' => 'lapsed',
        'payment_status' => 'unpaid',
        'expiry_date' => '2026-07-31',
    ]);

    $juan = User::factory()->create([
        'name' => 'Juan Kriel',
        'email' => 'cernoflash@gmail.com',
    ]);
    Membership::create([
        'user_id' => $juan->id,
        'saprf_number' => '1990',
        'membership_type' => 'free',
        'status' => 'active',
        'payment_status' => 'unpaid',
        'expiry_date' => '2027-01-08',
    ]);

    expect(app(KrielAccountsRepair::class)->apply())->toBeTrue();

    $theo->refresh();
    $juan->refresh();

    expect($theo->email)->toBe('nuformconstruction.1@gmail.com')
        ->and($theo->membership->membership_type)->toBe('free')
        ->and($juan->parent_id)->toBe($theo->id)
        ->and($juan->is_managed_account)->toBeTrue()
        ->and($juan->managed_relationship)->toBe('junior')
        ->and($juan->email)->toBe('cernoflash@gmail.com')
        ->and($juan->membership->membership_type)->toBe('free')
        ->and($juan->membership->saprf_number)->toBe('1990');

    Notification::assertSentTo($theo, MemberInvitationNotification::class);
    Notification::assertNotSentTo($juan, MemberInvitationNotification::class);
    expect($theo->email_verified_at)->toBeNull();
});
