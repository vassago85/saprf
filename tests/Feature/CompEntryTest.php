<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\FinancialService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    foreach ([
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => '50',
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => '50',
        'estimated_gateway_fee_percentage' => '0',
        'estimated_gateway_flat_fee' => '0',
        'non_member_surcharge' => '150',
        'lapsed_member_surcharge' => '0',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    $this->province = Province::firstOrCreate(['code' => 'GP'], ['name' => 'Gauteng', 'abbreviation' => 'GP']);
    Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->otherMd = User::factory()->create(['email_verified_at' => now()]);
    $this->otherMd->assignRole('match_director');

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole('admin');

    $this->shooter = User::factory()->create(['email_verified_at' => now()]);

    $this->match = MatchEvent::create([
        'name' => 'Comp Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subWeek(),
        'status' => 'completed',
        'published' => true,
        'match_director' => $this->md->name,
        'active_member_fee' => 500.00,
        'non_member_fee' => 650.00,
        'lapsed_member_fee' => 500.00,
        'created_by' => $this->md->id,
    ]);
});

function makePendingEntry(MatchEvent $match, User $shooter): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500.00,
        'surcharge_amount' => 0,
        'saprf_fee' => 50.00,
        'platform_fee' => 50.00,
        'gateway_fee' => 19.50,
        'md_net_amount' => 380.50,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);
}

it('comps a pending entry: zeroes fees, sets negative md_net, flags waived', function () {
    $reg = makePendingEntry($this->match, $this->shooter);

    $this->actingAs($this->md)
        ->post(route('registrations.comp', $reg), [
            'comp_note' => 'Travelled from EC, prize comp from previous match',
        ])
        ->assertRedirect();

    $reg->refresh();

    expect($reg->payment_status)->toBe('waived')
        ->and((float) $reg->fee_amount)->toBe(0.00)
        ->and((float) $reg->surcharge_amount)->toBe(0.00)
        ->and((float) $reg->gateway_fee)->toBe(0.00)
        ->and((float) $reg->saprf_fee)->toBe(50.00) // unchanged — MD owes it
        ->and((float) $reg->platform_fee)->toBe(50.00) // unchanged — MD owes it
        ->and((float) $reg->md_net_amount)->toBe(-100.00); // -(saprf + platform)
});

function payThroughPayfast(MatchRegistration $registration): void
{
    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $registration->user_id,
        'amount' => $registration->fee_amount,
        'm_payment_id' => 'REG-'.$registration->id,
        'status' => 'completed',
        'gateway' => 'payfast',
    ]);
}

it('rolls a comp into the MD payout as a negative line against the net', function () {
    // Baseline: one normal paid entry → MD takes R400 (fee 500 - saprf 50 - platform 50).
    $normalShooter = User::factory()->create();
    $paid = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $normalShooter->id,
        'shooter_name' => $normalShooter->name,
        'email' => $normalShooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'surcharge_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => 400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(5),
    ]);
    payThroughPayfast($paid);

    // Comp'd entry: MD covers R100 (saprf+platform) → net to MD = 400 - 100 = 300.
    $comped = makePendingEntry($this->match, $this->shooter);
    $this->actingAs($this->md)->post(route('registrations.comp', $comped), [
        'comp_note' => 'Prize comp from previous match',
    ])->assertRedirect();

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    // The comp'd row contributes 0 to gross (shooter paid nothing) but
    // -100 to md_net via the saprf+platform the MD now owes.
    expect($financials['paid_entries']->count())->toBe(2)
        ->and((float) $financials['gross_revenue'])->toBe(500.00)
        ->and((float) $financials['saprf_fees'])->toBe(100.00) // both rows contribute R50
        ->and((float) $financials['platform_fees'])->toBe(100.00)
        ->and((float) $financials['md_net'])->toBe(300.00); // 400 (normal) + (-100) (comp)
});

it('includes the comp row with zeros + negative net in a frozen payout statement', function () {
    $paid = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Paying Shooter',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500, 'surcharge_amount' => 0,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 0,
        'md_net_amount' => 400, 'payment_status' => 'paid',
        'registration_status' => 'confirmed', 'registered_at' => now()->subDays(5),
    ]);
    payThroughPayfast($paid);

    $comped = makePendingEntry($this->match, $this->shooter);
    $this->actingAs($this->md)->post(route('registrations.comp', $comped), [
        'comp_note' => 'MD comped',
    ])->assertRedirect();

    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->admin);

    // Both rows (normal + comp) show up as billable items on the statement.
    $items = $payout->items()->where('excluded', false)->get();
    expect($items)->toHaveCount(2);

    // The comp row carries the negative net so the payout totals come out right.
    $compItem = $items->firstWhere('shooter_name', $this->shooter->name);
    expect($compItem)->not->toBeNull()
        ->and((float) $compItem->gross_amount)->toBe(0.00)
        ->and((float) $compItem->saprf_fee)->toBe(50.00)
        ->and((float) $compItem->platform_fee)->toBe(50.00)
        ->and((float) $compItem->net_amount)->toBe(-100.00);

    expect((float) $payout->net_amount)->toBe(300.00);
});

it('does NOT re-bill legacy waived rows that carry md_net=0', function () {
    // Simulate a legacy import: waived but zero everywhere (no negative net).
    // These should stay EXCLUDED from the roll-up so historical imports
    // don't retroactively show up on MD payouts as comps.
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => 'Legacy Imported Shooter',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0, 'surcharge_amount' => 0,
        'saprf_fee' => 0, 'platform_fee' => 0, 'gateway_fee' => 0,
        'md_net_amount' => 0,
        'payment_status' => 'waived',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(400),
    ]);

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    expect($financials['paid_entries']->count())->toBe(0)
        ->and($financials['excluded_entries']->count())->toBe(1)
        ->and((float) $financials['md_net'])->toBe(0.00);
});

it('rejects a comp POST without a reason note', function () {
    $reg = makePendingEntry($this->match, $this->shooter);

    $this->actingAs($this->md)
        ->post(route('registrations.comp', $reg), ['comp_note' => ''])
        ->assertSessionHasErrors('comp_note');

    $reg->refresh();
    expect($reg->payment_status)->toBe('pending');
});

it('forbids another MD from comping someone else\'s match entry', function () {
    $reg = makePendingEntry($this->match, $this->shooter);

    $this->actingAs($this->otherMd)
        ->post(route('registrations.comp', $reg), ['comp_note' => 'trying to comp'])
        ->assertForbidden();

    $reg->refresh();
    expect($reg->payment_status)->toBe('pending');
});

it('refuses to comp an already-settled entry', function () {
    $reg = makePendingEntry($this->match, $this->shooter);
    $reg->update(['payment_status' => 'paid']);

    $this->actingAs($this->md)
        ->post(route('registrations.comp', $reg), ['comp_note' => 'late comp'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $reg->refresh();
    expect($reg->payment_status)->toBe('paid');
});

it('shows the Comp this entry card to the owning MD on an unpaid registration', function () {
    $reg = makePendingEntry($this->match, $this->shooter);

    $this->actingAs($this->md)
        ->get(route('registrations.show', $reg))
        ->assertOk()
        ->assertSee('Comp this entry')
        ->assertSee('R100.00')     // SAPRF + platform preview
        ->assertSee('come off your payout', false);
});

it('auto-refreshes the frozen payout snapshot when an entry is comped after the payout was raised', function () {
    // One normal paid entry: R400 net to MD.
    $normalShooter = User::factory()->create();
    $paid = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $normalShooter->id,
        'shooter_name' => $normalShooter->name,
        'email' => $normalShooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500, 'surcharge_amount' => 0,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 0,
        'md_net_amount' => 400, 'payment_status' => 'paid',
        'registration_status' => 'confirmed', 'registered_at' => now()->subDays(5),
    ]);
    payThroughPayfast($paid);

    // MD raises the payout BEFORE comping the pending shooter. The frozen
    // snapshot captures 1 billable entry at R400 net.
    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->md);
    expect($payout->items()->where('excluded', false)->count())->toBe(1)
        ->and((float) $payout->net_amount)->toBe(400.00);

    $firstVersion = $payout->statement_version;

    // Now the MD comps the other shooter — this should transparently
    // regenerate the snapshot so the statement isn't stale.
    $comped = makePendingEntry($this->match, $this->shooter);
    $this->actingAs($this->md)
        ->post(route('registrations.comp', $comped), ['comp_note' => 'MD comped mid-payout'])
        ->assertRedirect();

    $payout->refresh();

    expect($payout->statement_version)->toBe($firstVersion + 1)
        ->and((float) $payout->net_amount)->toBe(300.00) // 400 - 100 comp
        ->and($payout->items()->where('excluded', false)->count())->toBe(2);
});
