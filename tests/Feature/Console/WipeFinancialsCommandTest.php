<?php

use App\Models\FinancialTransaction;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRoles();
});

function seedFinancialFixtures(User $user): array
{
    $tier = MembershipFeeTier::firstOrCreate(
        ['slug' => 'adult'],
        ['name' => 'Adult', 'price' => 450, 'duration_months' => 12, 'display_order' => 1, 'is_active' => true, 'is_default' => true],
    );

    $membership = Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SA-'.$user->id,
        'membership_type' => 'annual',
        'status' => 'active',
        'payment_status' => 'paid',
    ]);

    DB::table('membership_payments')->insert([
        'membership_id' => $membership->id,
        'amount' => 450,
        'status' => 'succeeded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('payments')->insert([
        'payable_type' => Membership::class,
        'payable_id' => $membership->id,
        'user_id' => $user->id,
        'amount' => 450,
        'currency' => 'ZAR',
        'gateway' => 'payfast',
        'm_payment_id' => 'test-mp-'.$user->id,
        'status' => 'succeeded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('payouts')->insert([
        'reference' => 'PYT-TEST-'.$user->id,
        'payee_type' => 'saprf',
        'gross_amount' => 500,
        'fees_deducted' => 50,
        'net_amount' => 450,
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $payoutId = DB::table('payouts')->latest('id')->value('id');
    DB::table('payout_items')->insert([
        'payout_id' => $payoutId,
        'source_type' => 'membership',
        'source_id' => $membership->id,
        'description' => 'Test payout item',
        'gross_amount' => 500,
        'net_amount' => 450,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    FinancialTransaction::create([
        'type' => 'payment',
        'source_type' => 'membership',
        'source_id' => $membership->id,
        'user_id' => $user->id,
        'amount' => 450,
        'description' => 'Adult membership fee',
    ]);

    DB::table('platform_income')->insert([
        'category' => 'sponsorship',
        'amount' => 10000,
        'income_date' => now()->toDateString(),
        'description' => 'Test sponsorship',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('platform_expenses')->insert([
        'category' => 'hosting',
        'amount' => 250,
        'expense_date' => now()->toDateString(),
        'description' => 'Test server bill',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['tier' => $tier, 'membership' => $membership];
}

test('dry run reports counts but changes nothing', function () {
    $user = User::factory()->create();
    seedFinancialFixtures($user);

    $this->artisan('financials:wipe')
        ->expectsOutputToContain('Dry-run only')
        ->assertSuccessful();

    expect(DB::table('financial_transactions')->count())->toBeGreaterThan(0)
        ->and(DB::table('payouts')->count())->toBeGreaterThan(0)
        ->and(DB::table('payments')->count())->toBeGreaterThan(0);
});

test('confirmed wipe empties every financial table and preserves fee tiers + memberships', function () {
    $user = User::factory()->create();
    seedFinancialFixtures($user);
    $tierIdBefore = MembershipFeeTier::first()->id;
    $membershipIdBefore = Membership::first()->id;

    $this->artisan('financials:wipe', ['--confirm' => true, '--force' => true])
        ->assertSuccessful();

    expect(DB::table('financial_transactions')->count())->toBe(0)
        ->and(DB::table('payout_items')->count())->toBe(0)
        ->and(DB::table('payouts')->count())->toBe(0)
        ->and(DB::table('platform_expenses')->count())->toBe(0)
        ->and(DB::table('platform_income')->count())->toBe(0)
        ->and(DB::table('match_expenses')->count())->toBe(0)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('membership_payments')->count())->toBe(0);

    // Config + member data must survive.
    expect(MembershipFeeTier::find($tierIdBefore))->not->toBeNull()
        ->and(Membership::find($membershipIdBefore))->not->toBeNull()
        ->and(Membership::find($membershipIdBefore)->payment_status)->toBe('unpaid');
});

test('running on an already-empty system is a no-op success', function () {
    $this->artisan('financials:wipe', ['--confirm' => true, '--force' => true])
        ->expectsOutputToContain('Nothing to wipe')
        ->assertSuccessful();
});
