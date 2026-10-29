<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MdPayoutStatementNotification;
use App\Services\FinancialService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

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
    app(SettingsService::class)->clearCache();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->division = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->md = User::factory()->create(['name' => 'Match Director', 'email_verified_at' => now()]);
    $this->md->assignRole('match_director');

    $this->finance = User::factory()->create(['name' => 'Finance Admin', 'email_verified_at' => now()]);
    $this->finance->assignRole('admin');

    $this->otherMd = User::factory()->create(['name' => 'Other MD', 'email_verified_at' => now()]);
    $this->otherMd->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Statement Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subWeek(),
        'status' => 'completed',
        'published' => true,
        'match_director' => 'Match Director',
        'active_member_fee' => 500.00,
        'non_member_fee' => 650.00,
        'lapsed_member_fee' => 500.00,
        'created_by' => $this->md->id,
    ]);
});

function makePaidEntry(MatchEvent $match, User $shooter, string $category = 'active_member', float $fee = 500.0, float $surcharge = 0.0, float $gateway = 0.0): MatchRegistration
{
    $registration = MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => $category,
        'fee_amount' => $fee,
        'surcharge_amount' => $surcharge,
        'saprf_fee' => 50.0,
        'platform_fee' => 50.0,
        'gateway_fee' => $gateway,
        'md_net_amount' => round($fee - 50 - 50 - $surcharge - $gateway, 2),
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(3),
    ]);

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $shooter->id,
        'amount' => $fee,
        'm_payment_id' => 'PF-'.$registration->id,
        'status' => 'completed',
        'gateway' => 'payfast',
        'gateway_response' => ['payment_method' => 'cc'],
    ]);

    return $registration;
}

function makeUnpaidEntry(MatchEvent $match, User $shooter): MatchRegistration
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
        'gateway_fee' => 0,
        'md_net_amount' => 400.00,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);
}

it('matches summary totals and payout net to the cent for mixed entries', function () {
    $memberA = User::factory()->create(['name' => 'Member A']);
    $memberB = User::factory()->create(['name' => 'Member B']);
    $nonMember = User::factory()->create(['name' => 'Non Member']);
    $unpaid = User::factory()->create(['name' => 'Unpaid Shooter']);

    makePaidEntry($this->match, $memberA, 'active_member', 500);
    makePaidEntry($this->match, $memberB, 'active_member', 500, 0, 17.50);
    makePaidEntry($this->match, $nonMember, 'non_member', 650, 150, 0);
    makeUnpaidEntry($this->match, $unpaid);

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    // Identity: gross - saprf - platform - gateway - surcharge == md_net
    $expected = round(
        $financials['gross_revenue']
        - $financials['saprf_fees']
        - $financials['platform_fees']
        - $financials['gateway_fees']
        - $financials['surcharges'],
        2,
    );
    expect(round($financials['md_net'], 2))->toBe($expected);

    // Only paid rows contribute money
    expect($financials['paid_entries']->count())->toBe(3)
        ->and($financials['excluded_entries']->count())->toBe(1);

    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);

    expect(round((float) $payout->net_amount, 2))->toBe(round($financials['md_net'], 2))
        ->and(round($payout->items()->where('excluded', false)->sum('net_amount'), 2))->toBe(round($financials['md_net'], 2));
});

it('leaves cash entries out of the payout', function () {
    $online = User::factory()->create(['name' => 'Online Shooter']);
    makePaidEntry($this->match, $online, 'active_member', 500);

    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Cash Shooter',
        'membership_fee_category' => 'club_entry',
        'fee_amount' => 600,
        'surcharge_amount' => 0,
        'saprf_fee' => 0,
        'platform_fee' => 0,
        'gateway_fee' => 0,
        'md_net_amount' => 600,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    expect($financials['paid_registrations'])->toBe(1)
        ->and((float) $financials['gross_revenue'])->toBe(500.0)
        ->and((float) $financials['md_net'])->toBe(400.0)
        ->and($financials['excluded_entries']->pluck('shooter_name'))->toContain('Cash Shooter');
});

it('logs member levies on a manual payment without paying the cash back out', function () {
    $online = User::factory()->create(['name' => 'Online Shooter']);
    makePaidEntry($this->match, $online, 'active_member', 500);

    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Cash Member',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 600,
        'surcharge_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => 500,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    expect((float) $financials['gross_revenue'])->toBe(500.0)
        ->and((float) $financials['saprf_fees'])->toBe(100.0)
        ->and((float) $financials['platform_fees'])->toBe(100.0)
        ->and((float) $financials['md_net'])->toBe(300.0);
});

it('drops every charge on a manual non-member entry', function () {
    $online = User::factory()->create(['name' => 'Online Shooter']);
    makePaidEntry($this->match, $online, 'active_member', 500);

    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Rennie Brink',
        'membership_fee_category' => 'non_member',
        'fee_amount' => 700,
        'surcharge_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => 500,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    expect((float) $financials['gross_revenue'])->toBe(500.0)
        ->and((float) $financials['surcharges'])->toBe(0.0)
        ->and((float) $financials['saprf_fees'])->toBe(50.0)
        ->and((float) $financials['platform_fees'])->toBe(50.0)
        ->and((float) $financials['md_net'])->toBe(400.0)
        ->and($financials['excluded_entries']->pluck('shooter_name'))->toContain('Rennie Brink');
});

it('shows unpaid rows as excluded with zeros', function () {
    $paid = User::factory()->create(['name' => 'Paid']);
    $pending = User::factory()->create(['name' => 'Still Pending']);
    makePaidEntry($this->match, $paid);
    $unpaid = makeUnpaidEntry($this->match, $pending);

    $financials = app(FinancialService::class)->matchFinancials($this->match->fresh());

    expect($financials['excluded_entries']->pluck('id'))->toContain($unpaid->id)
        ->and($financials['md_net'])->toBeGreaterThan(0);

    // The old `financials.match-report` route is now a 301 alias into the
    // workspace Finances tab. Pending entries render inline in the breakdown
    // flagged as a "Projected" row (worst-case gateway applied) so the MD
    // sees what they'll realistically end up with once stragglers settle,
    // rather than a hidden "not included" footer they can mentally skip.
    $this->actingAs($this->finance)
        ->get(route('matches.finances', $this->match))
        ->assertOk()
        ->assertSee('Projected')
        ->assertSee('Still Pending');
});

it('derives payment method from completed PayFast payments', function () {
    $shooter = User::factory()->create();
    $reg = makePaidEntry($this->match, $shooter);

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $reg->id,
        'user_id' => $shooter->id,
        'amount' => $reg->fee_amount,
        'm_payment_id' => 'REG-'.$reg->id,
        'status' => 'completed',
        'gateway' => 'payfast',
        'gateway_response' => ['payment_method' => 'cc'],
    ]);

    expect($reg->fresh()->paymentMethodSlug())->toBe('card')
        ->and($reg->fresh()->paymentMethodLabel())->toBe('Card');
});

it('freezes payout statement lines and keeps them stable when registrations change later', function () {
    $shooter = User::factory()->create();
    $reg = makePaidEntry($this->match, $shooter, 'active_member', 500);

    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);

    $originalNet = (float) $payout->net_amount;
    $originalItemCount = $payout->items()->count();

    // Change the registration after the payout was raised.
    $reg->update([
        'fee_amount' => 1000,
        'md_net_amount' => 900,
    ]);

    $payout->refresh();

    expect((float) $payout->net_amount)->toBe($originalNet)
        ->and($payout->items()->count())->toBe($originalItemCount)
        ->and((float) $payout->items()->where('excluded', false)->sum('gross_amount'))->toBe(500.00);
});

it('regenerates the frozen statement, bumps version, and reflects live changes', function () {
    $shooter = User::factory()->create();
    makePaidEntry($this->match, $shooter);

    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);
    $v1 = (int) $payout->statement_version;

    // New paid entry lands post-raise.
    $late = User::factory()->create();
    makePaidEntry($this->match, $late);

    $this->actingAs($this->finance)
        ->post(route('financials.payouts.regenerate-statement', $payout))
        ->assertRedirect()
        ->assertSessionHas('success');

    $payout->refresh();

    expect((int) $payout->statement_version)->toBe($v1 + 1)
        ->and($payout->items()->where('excluded', false)->count())->toBe(2);
});

it('surfaces a rebuild banner and admin-only button when the statement has no snapshot', function () {
    $shooter = User::factory()->create();
    makePaidEntry($this->match, $shooter);
    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);

    // Simulate a legacy payout: no frozen snapshot, no items. Mirrors the
    // production state of payouts raised before commit 47c40d4.
    $payout->items()->delete();
    $payout->update(['statement_generated_at' => null, 'statement_snapshot' => null]);

    // Admin sees the explainer AND the rebuild button pointing at the right route.
    $this->actingAs($this->finance)
        ->get(route('my-payouts.show', $payout))
        ->assertOk()
        ->assertSee('This statement has no frozen snapshot.')
        ->assertSee('Rebuild statement from live data')
        ->assertSee(route('financials.payouts.regenerate-statement', $payout));

    // MD sees the explainer but NOT the button (nothing they can do about it).
    $response = $this->actingAs($this->md)->get(route('my-payouts.show', $payout))->assertOk();
    $response->assertSee('This statement has no frozen snapshot.');
    expect($response->getContent())->not->toContain('Rebuild statement from live data');

    // Pending-queue surfaces the inline rebuild button for the same payout.
    $this->actingAs($this->finance)
        ->get(route('financials.payouts'))
        ->assertOk()
        ->assertSee('Rebuild statement');
});

it('restricts my-payouts to the MD themselves and finance roles', function () {
    $shooter = User::factory()->create();
    makePaidEntry($this->match, $shooter);
    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);

    // Own MD: 200
    $this->actingAs($this->md)
        ->get(route('my-payouts.show', $payout))
        ->assertOk();

    // Other MD: 403
    $this->actingAs($this->otherMd)
        ->get(route('my-payouts.show', $payout))
        ->assertForbidden();

    // Finance: 200
    $this->actingAs($this->finance)
        ->get(route('my-payouts.show', $payout))
        ->assertOk();
});

it('emails the MD a statement when the payout is raised', function () {
    Notification::fake();

    $shooter = User::factory()->create();
    makePaidEntry($this->match, $shooter);

    app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);

    Notification::assertSentTo($this->md, MdPayoutStatementNotification::class, function ($notification) {
        return (new ReflectionProperty($notification, 'event'))
            ->getValue($notification) === 'raised';
    });
});

it('emails the MD again when the payout is marked fully paid', function () {
    Notification::fake();

    $shooter = User::factory()->create();
    makePaidEntry($this->match, $shooter);
    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);

    $this->actingAs($this->finance)
        ->post(route('financials.payouts.mark-paid', $payout), [
            'paid_amount' => $payout->net_amount,
            'payment_reference' => 'FNB-123',
        ])->assertRedirect();

    Notification::assertSentTo($this->md, MdPayoutStatementNotification::class, function ($notification) {
        return (new ReflectionProperty($notification, 'event'))
            ->getValue($notification) === 'paid';
    });
});

it('exports payouts CSV respecting type and status filters with outstanding and references', function () {
    $shooter = User::factory()->create();
    makePaidEntry($this->match, $shooter);
    $payout = app(FinancialService::class)->createMdPayout($this->match->fresh(), $this->finance);
    $payout->update(['payment_reference' => 'FNB-REF-9', 'paid_amount' => 100]);

    // Second payout, different status/type for filtering
    Payout::create([
        'reference' => Payout::generateReference(),
        'payee_type' => 'platform_operator',
        'gross_amount' => 100,
        'fees_deducted' => 0,
        'net_amount' => 100,
        'status' => 'paid',
        'paid_amount' => 100,
        'paid_at' => now(),
        'created_by' => $this->finance->id,
    ]);

    $response = $this->actingAs($this->finance)
        ->get(route('financials.export.payouts-csv', ['type' => 'match_director', 'status' => 'pending']));

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain($payout->reference)
        ->and($body)->toContain('FNB-REF-9')
        ->and($body)->toContain('Outstanding')
        ->and($body)->not->toContain('platform_operator');
});
