<?php

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole('owner');

    $this->active = membershipUser('Active Paid', 'paid', 'active', Carbon::today()->addMonths(6), 'paid');
    $this->free = membershipUser('Free Future', 'free', 'active', Carbon::today()->addYear(), 'paid');
    $this->lapsed = membershipUser('Grace Window', 'paid', 'lapsed', Carbon::today()->subDays(10), 'paid');
    $this->expired = membershipUser('Long Expired', 'paid', 'lapsed', Carbon::today()->subDays(120), 'paid');
});

test('membership status is the same label on the dashboard, memberships, users and provincial list', function () {
    expect($this->active->membershipStatus())->toBe(MembershipStatus::Active)
        ->and($this->free->membershipStatus())->toBe(MembershipStatus::NonMember)
        ->and($this->lapsed->membershipStatus())->toBe(MembershipStatus::Lapsed)
        ->and($this->expired->membershipStatus())->toBe(MembershipStatus::Expired);

    $activeCount = Membership::query()->active()->count();
    expect($activeCount)->toBe(1);

    $dashboard = $this->actingAs($this->owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Active memberships')
        ->assertSee('Paid and in date. Free accounts are excluded.');

    expect($dashboard->getContent())->toMatch('/Active memberships<\/p>\s*<p[^>]*>1<\/p>/');

    foreach ([
        $this->free->name => 'Non-member',
        $this->lapsed->name => 'Lapsed (in grace)',
        $this->expired->name => 'Expired',
        $this->active->name => 'Active',
    ] as $name => $label) {
        foreach ([
            route('memberships.index', ['search' => $name]),
            route('user-management.index', ['search' => $name]),
            route('provincial-members.index', ['search' => $name]),
        ] as $url) {
            $this->actingAs($this->owner)
                ->get($url)
                ->assertOk()
                ->assertSeeInOrder([$name, $label]);
        }
    }

    $this->actingAs($this->owner)
        ->get(route('memberships.index', ['status' => 'active']))
        ->assertOk()
        ->assertSee($this->active->name)
        ->assertDontSee($this->free->name)
        ->assertDontSee($this->lapsed->name);

    $this->actingAs($this->owner)
        ->get(route('reports.selection'))
        ->assertOk()
        ->assertSee('Active among these shooters')
        ->assertSee('Not the federation-wide active count');

    $this->actingAs($this->owner)
        ->get(route('sascoc-report.index', ['year' => now()->year]))
        ->assertOk()
        ->assertSee('paid members whose membership covered '.now()->year);

    $this->actingAs($this->owner)
        ->get(route('financials.dashboard'))
        ->assertOk()
        ->assertSee('MD share accrued')
        ->assertSee('not the pending balance on the payouts page')
        ->assertSee('not the number of active members');
});

function membershipUser(string $name, string $type, string $status, Carbon $expiry, string $payment): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => (string) (8000 + $user->id),
        'membership_type' => $type,
        'status' => $status,
        'payment_status' => $payment,
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => $expiry,
    ]);

    return $user->fresh('membership');
}
