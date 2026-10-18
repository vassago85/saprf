<?php

use App\Models\User;
use App\Support\Dashboard\AdminDashboardPresenter;
use App\Support\Dashboard\MemberDashboardPresenter;
use App\Support\Dashboard\OwnerDashboardPresenter;

beforeEach(function () {
    seedRoles();
});

it('renders the owner dashboard shell with KPIs and pricing strip', function () {
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Alice')
        ->assertSee('Dashboard', false)
        ->assertSee('Active members')
        ->assertSee('Lapsing in 30 days')
        ->assertSee('Season income')
        ->assertSee('Upcoming events')
        ->assertSee('Recent activity')
        ->assertSee('Pricing & rules')
        ->assertDontSee('Owner Dashboard');
});

it('renders the admin dashboard shell with operational KPIs', function () {
    $admin = User::factory()->create(['name' => 'Bob Admin']);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Bob')
        ->assertSee('Pending memberships')
        ->assertSee('Upcoming matches')
        ->assertSee('Pending scores')
        ->assertSee('MD payouts pending')
        ->assertDontSee('Admin Dashboard')
        ->assertDontSee('Pricing & rules');
});

it('renders the match director dashboard shell', function () {
    $md = User::factory()->create(['name' => 'Chris MD']);
    $md->assignRole('match_director');

    $this->actingAs($md)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Chris')
        ->assertSee('My matches')
        ->assertSee('My upcoming matches')
        ->assertSee('Create match')
        ->assertDontSee('Match Director Dashboard');
});

it('renders the provincial admin dashboard shell', function () {
    $pa = User::factory()->create(['name' => 'Dana Prov']);
    $pa->assignRole('provincial_admin');

    $this->actingAs($pa)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Dana')
        ->assertSee('Committee seats')
        ->assertSee('Quick links')
        ->assertDontSee('Provincial Admin Dashboard');
});

it('wraps the member dashboard in the shared shell', function () {
    $member = User::factory()->create(['name' => 'Eve Shooter']);
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back, Eve')
        ->assertSee('Matches shot')
        ->assertSee('National rank')
        ->assertSee('Qualification')
        ->assertSee('Season Stats')
        ->assertDontSee('Pricing & rules');
});

it('keeps the developer dashboard unchanged', function () {
    $dev = User::factory()->create(['name' => 'Frank Dev']);
    $dev->assignRole('developer');

    $this->actingAs($dev)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('PHP')
        ->assertDontSee('Active members')
        ->assertDontSee('Pricing & rules');
});

it('builds an owner payload with four KPIs via the presenter', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $payload = app(OwnerDashboardPresenter::class)->build($owner);

    expect($payload->title)->toBe('Dashboard')
        ->and($payload->kpis)->toHaveCount(4)
        ->and($payload->footer)->not->toBeNull()
        ->and(collect($payload->kpis)->pluck('label')->all())->toContain('Active members');
});

it('builds an admin payload without a pricing footer', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $payload = app(AdminDashboardPresenter::class)->build($admin);

    expect($payload->footer)->toBeNull()
        ->and($payload->kpis)->not->toBeEmpty();
});

it('builds a member payload without primary panels', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $payload = app(MemberDashboardPresenter::class)->build($member);

    expect($payload->primary)->toBeNull()
        ->and($payload->secondary)->toBeNull()
        ->and($payload->footer)->toBeNull()
        ->and($payload->kpis)->toHaveCount(4);
});
