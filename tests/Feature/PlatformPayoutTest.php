<?php

/**
 * Monthly platform-operator payout.
 *
 * The platform fee is settled monthly, grouped by when each shooter paid
 * (registered_at, which is the paid-at proxy — a registration only marks
 * itself paid once the shooter has actually paid). These tests lock in the
 * grouping rule, guard against double-billing a month, and enforce that the
 * platform_operator_user_id setting is required before a payout can be
 * generated.
 */

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\PlatformPayoutService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->operator = User::factory()->create(['name' => 'Platform Operator']);
    $this->operator->assignRole('developer');
    $this->creator = User::factory()->create();
    $this->creator->assignRole('owner');

    Setting::updateOrCreate(
        ['key' => 'platform_operator_user_id'],
        ['value' => (string) $this->operator->id],
    );
    app(SettingsService::class)->clearCache();

    $this->match = MatchEvent::create([
        'name' => 'August Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::create(2026, 12, 1),
        'status' => 'open',
        'active_member_fee' => 500,
        'non_member_fee' => 750,
        'lapsed_member_fee' => 650,
        'created_by' => $this->creator->id,
    ]);
});

function makePaidRegistration(MatchEvent $match, Carbon $registeredAt, float $platformFee = 50, string $paymentStatus = 'paid', string $registrationStatus = 'confirmed'): MatchRegistration
{
    $shooter = User::factory()->create();

    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => $platformFee,
        'gateway_fee' => 0,
        'surcharge_amount' => 0,
        'md_net_amount' => 400,
        'payment_status' => $paymentStatus,
        'registration_status' => $registrationStatus,
        'registered_at' => $registeredAt,
    ]);
}

it('previews platform fees grouped by the month the shooter paid', function () {
    makePaidRegistration($this->match, Carbon::create(2026, 8, 3));
    makePaidRegistration($this->match, Carbon::create(2026, 8, 15));
    makePaidRegistration($this->match, Carbon::create(2026, 8, 31, 23, 59, 59));
    makePaidRegistration($this->match, Carbon::create(2026, 9, 1));
    makePaidRegistration($this->match, Carbon::create(2026, 7, 31, 23, 59, 59));

    $preview = app(PlatformPayoutService::class)->preview(Carbon::create(2026, 8, 20));

    expect($preview['entry_count'])->toBe(3);
    expect($preview['platform_fees'])->toBe(150.0);
    expect($preview['period_start']->toDateString())->toBe('2026-08-01');
    expect($preview['period_end']->toDateString())->toBe('2026-08-31');
    expect($preview['existing_payout'])->toBeNull();
    expect($preview['operator_user_id'])->toBe($this->operator->id);
});

it('excludes unpaid and cancelled registrations from the payout', function () {
    makePaidRegistration($this->match, Carbon::create(2026, 8, 5));
    makePaidRegistration($this->match, Carbon::create(2026, 8, 6), paymentStatus: 'unpaid');
    makePaidRegistration($this->match, Carbon::create(2026, 8, 7), registrationStatus: 'cancelled');
    makePaidRegistration($this->match, Carbon::create(2026, 8, 8), platformFee: 0);

    $preview = app(PlatformPayoutService::class)->preview(Carbon::create(2026, 8, 1));

    expect($preview['entry_count'])->toBe(1);
    expect($preview['platform_fees'])->toBe(50.0);
});

it('generates a payout with per-registration payout items', function () {
    $r1 = makePaidRegistration($this->match, Carbon::create(2026, 8, 3));
    $r2 = makePaidRegistration($this->match, Carbon::create(2026, 8, 20));

    $payout = app(PlatformPayoutService::class)->createForMonth(
        Carbon::create(2026, 8, 1),
        $this->creator,
    );

    expect($payout)->toBeInstanceOf(Payout::class);
    expect($payout->payee_type)->toBe('platform_operator');
    expect($payout->payee_user_id)->toBe($this->operator->id);
    expect($payout->status)->toBe('pending');
    expect((float) $payout->net_amount)->toBe(100.0);
    expect($payout->period_start->toDateString())->toBe('2026-08-01');
    expect($payout->period_end->toDateString())->toBe('2026-08-31');

    $items = PayoutItem::where('payout_id', $payout->id)->orderBy('source_id')->get();
    expect($items)->toHaveCount(2);
    expect($items->pluck('source_id')->all())->toBe([$r1->id, $r2->id]);
    expect((float) $items[0]->platform_fee)->toBe(50.0);
});

it('refuses to create a second payout for the same month', function () {
    makePaidRegistration($this->match, Carbon::create(2026, 8, 3));

    $service = app(PlatformPayoutService::class);
    $service->createForMonth(Carbon::create(2026, 8, 1), $this->creator);

    expect(fn () => $service->createForMonth(Carbon::create(2026, 8, 15), $this->creator))
        ->toThrow(RuntimeException::class, 'already exists');
});

it('refuses to create a payout for an empty month', function () {
    $service = app(PlatformPayoutService::class);

    expect(fn () => $service->createForMonth(Carbon::create(2026, 8, 1), $this->creator))
        ->toThrow(RuntimeException::class, 'No platform fees');
});

it('refuses to create a payout when no operator is configured', function () {
    Setting::where('key', 'platform_operator_user_id')->update(['value' => '']);
    app(SettingsService::class)->clearCache();

    makePaidRegistration($this->match, Carbon::create(2026, 8, 3));

    expect(fn () => app(PlatformPayoutService::class)->createForMonth(Carbon::create(2026, 8, 1), $this->creator))
        ->toThrow(RuntimeException::class, 'not configured');
});

it('lists unsettled past months and skips the current month', function () {
    $now = Carbon::create(2026, 11, 20);
    Carbon::setTestNow($now);

    makePaidRegistration($this->match, Carbon::create(2026, 8, 5));
    makePaidRegistration($this->match, Carbon::create(2026, 9, 5));
    makePaidRegistration($this->match, Carbon::create(2026, 11, 5));

    $service = app(PlatformPayoutService::class);
    $service->createForMonth(Carbon::create(2026, 9, 1), $this->creator);

    $unsettled = $service->unsettledMonths(12);

    expect($unsettled)->toHaveCount(1);
    expect($unsettled[0]['month']->format('Y-m'))->toBe('2026-08');
    expect($unsettled[0]['platform_fees'])->toBe(50.0);

    Carbon::setTestNow();
});

it('routes the create platform payout page for authorised users', function () {
    makePaidRegistration($this->match, Carbon::create(2026, 8, 3));

    $this->actingAs($this->creator)
        ->get(route('financials.payouts.platform.create', ['month' => '2026-08']))
        ->assertOk()
        ->assertSee('Create Platform Payout')
        ->assertSee('R50.00');
});

it('creates a payout via the controller and redirects to payouts', function () {
    makePaidRegistration($this->match, Carbon::create(2026, 8, 3));
    makePaidRegistration($this->match, Carbon::create(2026, 8, 10));

    $this->actingAs($this->creator)
        ->post(route('financials.payouts.platform.store'), ['month' => '2026-08'])
        ->assertRedirect(route('financials.payouts'));

    $payout = Payout::where('payee_type', 'platform_operator')->firstOrFail();
    expect((float) $payout->net_amount)->toBe(100.0);
    expect($payout->items()->count())->toBe(2);
});

it('surfaces validation errors when the month is malformed', function () {
    $this->actingAs($this->creator)
        ->post(route('financials.payouts.platform.store'), ['month' => 'not-a-month'])
        ->assertSessionHasErrors('month');
});
