<?php

use App\Models\ContactMessage;
use App\Models\User;
use App\Support\SidebarNavigation;

beforeEach(function () {
    seedRoles();
});

function staffUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->assignRole('member');

    return $user;
}

function sidebarLabels(User $user, string $mode): array
{
    return SidebarNavigation::labelsFor($user, $mode);
}

it('lists the expected shooter items for a member', function () {
    $member = staffUser('member');

    expect(sidebarLabels($member, 'shooter'))->toBe([
        'Dashboard',
        'Events',
        'Standings',
        'My Registrations',
        'My Membership',
        'My Family',
        'My Rifles',
        'My Ammo',
        'My Barrels',
        'Ladder Analyser',
        'String Analyser',
        'Rules & Documents',
        'Communications',
        'IPRF',
    ]);
});

it('hides admin functions from a member even if admin mode is requested', function () {
    $member = staffUser('member');

    $labels = sidebarLabels($member, 'admin');

    expect($labels)->not->toContain('Manage Matches')
        ->and($labels)->not->toContain('Memberships')
        ->and($labels)->not->toContain('User Management')
        ->and($labels)->toContain('Dashboard')
        ->and($labels)->toContain('Events');
});

it('shows admin functions and hides personal tools for an admin in admin mode', function () {
    $admin = staffUser('admin');

    $labels = sidebarLabels($admin, 'admin');

    expect($labels)->toContain('Dashboard')
        ->and($labels)->toContain('Events')
        ->and($labels)->toContain('Standings')
        ->and($labels)->toContain('Manage Matches')
        ->and($labels)->toContain('Venues')
        ->and($labels)->toContain('Score Imports')
        ->and($labels)->toContain('Scores')
        ->and($labels)->toContain('Memberships')
        ->and($labels)->toContain('Rules & Documents')
        ->and($labels)->toContain('Communications')
        ->and($labels)->toContain('IPRF')
        ->and($labels)->not->toContain('My Membership')
        ->and($labels)->not->toContain('My Registrations')
        ->and($labels)->not->toContain('My Family')
        ->and($labels)->not->toContain('My Rifles')
        ->and($labels)->not->toContain('My Ammo')
        ->and($labels)->not->toContain('My Barrels')
        ->and($labels)->not->toContain('Ladder Analyser')
        ->and($labels)->not->toContain('String Analyser')
        ->and($labels)->not->toContain('Published Documents');
});

it('shows only shooter items when an admin switches to shooter mode', function () {
    $admin = staffUser('admin');

    $labels = sidebarLabels($admin, 'shooter');

    expect($labels)->toContain('My Rifles')
        ->and($labels)->toContain('Ladder Analyser')
        ->and($labels)->toContain('Rules & Documents')
        ->and($labels)->not->toContain('Manage Matches')
        ->and($labels)->not->toContain('Memberships')
        ->and($labels)->not->toContain('User Management')
        ->and($labels)->not->toContain('Score Imports');
});

it('does not offer the view-as switch to a pure member', function () {
    $member = staffUser('member');

    $this->actingAs($member)
        ->get(route('profile'))
        ->assertOk()
        ->assertDontSee('View as')
        ->assertSee('My Rifles')
        ->assertSee('Rules & Documents')
        ->assertDontSee('Manage Matches');
});

it('renders admin navigation for an admin in admin mode', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->get(route('profile'))
        ->assertOk()
        ->assertSee('View as')
        ->assertSee('Manage Matches')
        ->assertSee('Match Management')
        ->assertSee('Rules & Documents')
        ->assertDontSee('My Rifles')
        ->assertDontSee('Ladder Analyser');
});

it('renders shooter navigation after an admin flips the toggle', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('My Rifles')
        ->assertSee('Equipment & Reloading')
        ->assertSee('Rules & Documents')
        ->assertDontSee('Manage Matches')
        ->assertDontSee('Match Management');
});

it('persists view mode across a page refresh', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    expect(session('view_mode'))->toBe('shooter');

    $this->get(route('communications.index'))->assertOk();

    expect(session('view_mode'))->toBe('shooter')
        ->and($admin->fresh()->effectiveViewMode())->toBe('shooter');
});

it('switches a staff user to admin context when they open an admin route', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    expect(session('view_mode'))->toBe('shooter');

    $this->get(route('matches.index'))
        ->assertOk()
        ->assertSee('Manage Matches');

    expect(session('view_mode'))->toBe('admin');
});

it('switches a staff user to shooter context when they open a personal route', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->get(route('rifle-configurations.index'))
        ->assertOk()
        ->assertSee('My Rifles')
        ->assertDontSee('Manage Matches');

    expect(session('view_mode'))->toBe('shooter');
});

it('does not switch context on shared routes', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    $this->get(route('communications.index'))->assertOk();

    expect(session('view_mode'))->toBe('shooter');
});

it('does not expose admin routes to a member', function () {
    $member = staffUser('member');

    $this->actingAs($member)
        ->get(route('matches.index'))
        ->assertForbidden();
});

it('lets a provincial admin switch view mode so they can reach reports', function () {
    $provincial = staffUser('provincial_admin');

    expect($provincial->canSwitchViewMode())->toBeTrue()
        ->and($provincial->effectiveViewMode())->toBe('admin')
        ->and(sidebarLabels($provincial, 'admin'))->toContain('Provincial Members')
        ->and(sidebarLabels($provincial, 'admin'))->not->toContain('Manage Matches')
        ->and(sidebarLabels($provincial, 'admin'))->not->toContain('My Rifles');
});

it('keeps match-director admin items limited to match management', function () {
    $director = staffUser('match_director');

    $labels = sidebarLabels($director, 'admin');

    expect($labels)->toContain('Manage Matches')
        ->and($labels)->toContain('Score Imports')
        ->and($labels)->toContain('Rules & Documents')
        ->and($labels)->not->toContain('Memberships')
        ->and($labels)->not->toContain('User Management')
        ->and($labels)->not->toContain('My Rifles');
});

it('treats registrations as dual-purpose and does not auto-switch', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->get(route('registrations.index'))
        ->assertOk();

    expect(session('view_mode'))->toBeNull()
        ->and($admin->fresh()->effectiveViewMode())->toBe('admin');
});

it('shows a parent badge on communications when a child has notifications', function () {
    $admin = staffUser('admin');

    ContactMessage::create([
        'first_name' => 'Bob',
        'surname' => 'Byte',
        'email' => 'bob@example.com',
        'subject' => 'A question',
        'message' => 'test message here please read',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    $sections = SidebarNavigation::sectionsFor($admin, 'admin');
    $communications = collect($sections)->firstWhere('key', 'communications');

    expect($communications)->not->toBeNull()
        ->and($communications['badge'])->toBe(1)
        ->and($communications['badge_color'])->toBe('amber');
});
