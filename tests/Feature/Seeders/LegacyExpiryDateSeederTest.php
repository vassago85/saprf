<?php

use App\Models\Membership;
use App\Models\User;
use Database\Seeders\LegacyExpiryDateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    seedRoles();
});

it('updates start and expiry dates for an unambiguous paid membership match', function () {
    $user = User::create([
        'name' => 'Malcolm Coetzee',
        'email' => 'malcolm.coetzee@example.co.za',
        'password' => Hash::make('secret'),
        'is_active' => true,
    ]);
    $user->assignRole('member');

    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-2026-1004',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'waived',
        'start_date' => '2026-01-01',
        'expiry_date' => '2027-08-09',
    ]);

    Artisan::call('db:seed', ['--class' => LegacyExpiryDateSeeder::class, '--force' => true]);

    $membership->refresh();

    expect($membership->start_date?->toDateString())->toBe('2022-01-31')
        ->and($membership->expiry_date?->toDateString())->toBe('2026-09-15')
        ->and($membership->status)->toBe('active')
        ->and($membership->payment_status)->toBe('paid');
});

it('skips rows when two paid members share the same normalized name', function () {
    foreach (['alice.dupe@example.co.za', 'bob.dupe@example.co.za'] as $i => $email) {
        $user = User::create([
            'name' => 'Duplicate Name',
            'email' => $email,
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
        Membership::create([
            'user_id' => $user->id,
            'saprf_number' => 'SAPRF-2026-DUP'.$i,
            'membership_type' => 'paid',
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
        ]);
    }

    Artisan::call('db:seed', ['--class' => LegacyExpiryDateSeeder::class, '--force' => true]);

    expect(
        Membership::where('saprf_number', 'like', 'SAPRF-2026-DUP%')->pluck('expiry_date')->map->toDateString()->unique()
    )->toHaveCount(1);
});

it('matches by real email when the display name differs', function () {
    $user = User::create([
        'name' => 'M Coetzee',
        'email' => 'mrcoetzee101@gmail.com',
        'password' => Hash::make('secret'),
        'is_active' => true,
    ]);

    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SAPRF-2026-EMAIL-1',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'waived',
        'start_date' => '2026-01-01',
        'expiry_date' => '2027-08-09',
    ]);

    Artisan::call('db:seed', ['--class' => LegacyExpiryDateSeeder::class, '--force' => true]);

    $membership->refresh();

    expect($membership->start_date?->toDateString())->toBe('2022-01-31')
        ->and($membership->expiry_date?->toDateString())->toBe('2026-09-15')
        ->and($membership->payment_status)->toBe('paid');
});

    $beforeUsers = User::count();
    $beforeMemberships = Membership::count();

    Artisan::call('db:seed', ['--class' => LegacyExpiryDateSeeder::class, '--force' => true]);

    expect(User::count())->toBe($beforeUsers)
        ->and(Membership::count())->toBe($beforeMemberships);
});
