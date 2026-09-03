<?php

/**
 * Early membership renewal:
 *   - Active members may renew in the last 60 days before expiry.
 *   - Checkout does not demote them to pending; ITN stacks +12 months on the
 *     current expiry so unused days are kept.
 *   - My Membership shows the renew card inside the window.
 *   - Shooter dashboard banner starts from 30 days out (asserted via the
 *     view — memberDashboard() uses MySQL YEAR() that SQLite can't run).
 */

use App\Models\Club;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\Payment;
use App\Models\Province;
use App\Models\User;
use App\Services\PayFastService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $club = Club::create([
        'name' => 'Renewal Test Club',
        'slug' => 'renewal-test-club-'.uniqid(),
        'abbreviation' => 'RTC',
        'province_id' => $this->province->id,
        'is_active' => true,
    ]);

    // Active paid members are gated by EnsureProfileComplete — fill SASCOC fields.
    $this->user = User::factory()->create([
        'province_id' => $this->province->id,
        'club_id' => $club->id,
        'email_verified_at' => now(),
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'gender' => 'male',
        'ethnicity' => 'white',
        'country_of_residence' => 'ZA',
        'sa_id_number' => '9001015800085',
        'sa_citizen' => true,
        'previously_disadvantaged' => false,
    ]);
    $this->user->assignRole('member');

    app(SettingsService::class)->set('payments_enabled', '1');

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
});

function makeActiveMembership(User $user, Carbon $expiry): Membership
{
    return Membership::create([
        'user_id' => $user->id,
        'saprf_number' => (string) random_int(1000, 9999),
        'membership_type' => 'paid',
        'fee_tier_id' => MembershipFeeTier::where('slug', 'adult')->value('id'),
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => $expiry->copy()->subYear()->toDateString(),
        'expiry_date' => $expiry->toDateString(),
    ]);
}

function signedRenewalItn(array $data): array
{
    $data['signature'] = app(PayFastService::class)->generateItnSignature($data);

    return $data;
}

function renderMemberDashboard(User $user, Membership $membership): string
{
    return view('dashboard.member', [
        'user' => $user->load('province'),
        'membership' => $membership,
        'nextMatch' => null,
        'scoresCount' => 0,
        'standingsPosition' => null,
        'qualificationProgress' => [],
        'matchesShot' => 0,
        'bestPlacement' => null,
        'avgPlacement' => null,
        'totalPoints' => 0,
        'statsBreakdown' => [
            ['series' => 'PRS', 'level' => 'provincial', 'matches' => 0, 'best' => null, 'avg' => null, 'points' => 0],
            ['series' => 'PRS', 'level' => 'national', 'matches' => 0, 'best' => null, 'avg' => null, 'points' => 0],
            ['series' => 'PR22', 'level' => 'provincial', 'matches' => 0, 'best' => null, 'avg' => null, 'points' => 0],
            ['series' => 'PR22', 'level' => 'national', 'matches' => 0, 'best' => null, 'avg' => null, 'points' => 0],
        ],
        'rifles' => collect(),
        'rifleCount' => 0,
        'recentMatches' => collect(),
        'seasonRankings' => collect(),
    ])->render();
}

it('allows renewal when expiry is within 60 days and keeps membership active', function () {
    $membership = makeActiveMembership($this->user, Carbon::today()->addDays(8));
    $adultTier = MembershipFeeTier::where('slug', 'adult')->firstOrFail();

    $this->actingAs($this->user)
        ->post(route('membership.join'), ['fee_tier_id' => $adultTier->id])
        ->assertRedirect();

    $membership->refresh();
    $payment = Payment::where('payable_type', Membership::class)
        ->where('payable_id', $membership->id)
        ->latest('id')
        ->first();

    expect($membership->status)->toBe('active')
        ->and($membership->payment_status)->toBe('paid')
        ->and($membership->expiry_date->toDateString())->toBe(Carbon::today()->addDays(8)->toDateString())
        ->and($payment)->not->toBeNull()
        ->and($payment->status)->toBe('pending');
});

it('blocks renewal when expiry is more than 60 days away', function () {
    makeActiveMembership($this->user, Carbon::today()->addDays(61));
    $adultTier = MembershipFeeTier::where('slug', 'adult')->firstOrFail();

    $this->actingAs($this->user)
        ->from(route('my-membership'))
        ->post(route('membership.join'), ['fee_tier_id' => $adultTier->id])
        ->assertRedirect(route('my-membership'))
        ->assertSessionHas('info');

    expect(Payment::where('payable_type', Membership::class)->count())->toBe(0);
});

it('stacks expiry from the current date when an early-renewal ITN completes', function () {
    $expiry = Carbon::today()->addDays(8);
    $membership = makeActiveMembership($this->user, $expiry);
    $adultTier = MembershipFeeTier::where('slug', 'adult')->firstOrFail();

    $payment = Payment::create([
        'payable_type' => Membership::class,
        'payable_id' => $membership->id,
        'user_id' => $this->user->id,
        'amount' => (float) $adultTier->price,
        'm_payment_id' => 'MEM-RENEW-1',
        'status' => 'pending',
    ]);

    $this->post(route('payments.notify'), signedRenewalItn([
        'm_payment_id' => $payment->m_payment_id,
        'pf_payment_id' => '2099001',
        'payment_status' => 'COMPLETE',
        'item_name' => 'SAPRF Membership',
        'amount_gross' => number_format((float) $adultTier->price, 2, '.', ''),
        'amount_fee' => '-11.00',
        'amount_net' => number_format((float) $adultTier->price - 11, 2, '.', ''),
        'merchant_id' => config('payfast.merchant_id'),
    ]))->assertOk();

    $membership->refresh();
    $expected = $expiry->copy()->addMonths($adultTier->duration_months)->toDateString();

    expect($membership->status)->toBe('active')
        ->and($membership->payment_status)->toBe('paid')
        ->and($membership->expiry_date->toDateString())->toBe($expected);
});

it('shows the renew card on my-membership inside the 60-day window', function () {
    makeActiveMembership($this->user, Carbon::today()->addDays(8));

    $this->actingAs($this->user)
        ->get(route('my-membership'))
        ->assertOk()
        ->assertSee('Renewal available')
        ->assertSee('Renew Membership');
});

it('hides the renew card on my-membership outside the 60-day window', function () {
    makeActiveMembership($this->user, Carbon::today()->addDays(90));

    $this->actingAs($this->user)
        ->get(route('my-membership'))
        ->assertOk()
        ->assertDontSee('Renewal available')
        ->assertDontSee('Renew Membership');
});

it('shows a renew banner on the shooter dashboard from 30 days before expiry', function () {
    $membership = makeActiveMembership($this->user, Carbon::today()->addDays(8));

    $this->actingAs($this->user);
    $html = renderMemberDashboard($this->user, $membership);

    expect($html)->toContain('Membership renewal due')
        ->and($html)->toContain('Renew membership');
});

it('does not show the dashboard renew banner when expiry is more than 30 days away', function () {
    $membership = makeActiveMembership($this->user, Carbon::today()->addDays(45));

    $this->actingAs($this->user);
    $html = renderMemberDashboard($this->user, $membership);

    expect($html)->not->toContain('Membership renewal due');
});

it('reports window helpers correctly on the model', function () {
    $near = makeActiveMembership($this->user, Carbon::today()->addDays(8));

    expect($near->isWithinRenewalWindow())->toBeTrue()
        ->and($near->shouldShowDashboardRenewalNotice())->toBeTrue()
        ->and($near->daysUntilExpiry())->toBe(8);

    $mid = Membership::create([
        'user_id' => User::factory()->create()->id,
        'saprf_number' => '28',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(10)->toDateString(),
        'expiry_date' => Carbon::today()->addDays(45)->toDateString(),
    ]);

    expect($mid->isWithinRenewalWindow())->toBeTrue()
        ->and($mid->shouldShowDashboardRenewalNotice())->toBeFalse();

    $far = Membership::create([
        'user_id' => User::factory()->create()->id,
        'saprf_number' => '29',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(6)->toDateString(),
        'expiry_date' => Carbon::today()->addDays(120)->toDateString(),
    ]);

    expect($far->isWithinRenewalWindow())->toBeFalse()
        ->and($far->shouldShowDashboardRenewalNotice())->toBeFalse();
});
