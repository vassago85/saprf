<?php

use App\Models\ContactMessage;
use App\Models\User;
use App\Navigation\SidebarMenu;
use App\Support\SidebarNavigation;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedRoles();
    Cache::flush();
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
    return SidebarMenu::labelsFor($user, $mode);
}

function sidebarSections(User $user, string $mode): array
{
    return SidebarMenu::sectionsFor($user, $mode);
}

it('lists the expected shooter items for a member', function () {
    $member = staffUser('member');

    expect(sidebarLabels($member, 'shooter'))->toBe([
        'Dashboard',
        'Events',
        'Standings',
        'My registrations',
        'My membership',
        'My family',
        'My invoices',
        'My rifles',
        'My ammo',
        'My barrels',
        'Load tools',
        'Rules & documents',
        'Inbox',
        'IPRF',
    ]);
});

it('tags admin sections with their Operations or Governance super-group', function () {
    $owner = staffUser('owner');

    $sections = sidebarSections($owner, 'admin');

    $byKey = collect($sections)->keyBy('key');

    expect($byKey['main']['super_group'])->toBeNull()
        ->and($byKey['competition_admin']['super_group'])->toBe('Operations')
        ->and($byKey['members']['super_group'])->toBe('Operations')
        ->and($byKey['selection']['super_group'])->toBe('Operations')
        ->and($byKey['comms']['super_group'])->toBe('Operations')
        ->and($byKey['finance']['super_group'])->toBe('Governance')
        ->and($byKey['reports']['super_group'])->toBe('Governance')
        ->and($byKey['settings']['super_group'])->toBe('Governance');
});

it('hides admin functions from a member even if admin mode is requested', function () {
    $member = staffUser('member');

    $labels = sidebarLabels($member, 'admin');

    // Shared no-role items (Dashboard, Events, Standings, Messages, Rules &
    // documents, IPRF) show in either mode — real members never land in admin
    // mode because the toggle is hidden for them. The role-gated admin items
    // must stay out.
    expect($labels)->not->toContain('Matches')
        ->and($labels)->not->toContain('Memberships')
        ->and($labels)->not->toContain('Users')
        ->and($labels)->not->toContain('Audit logs')
        ->and($labels)->toContain('Dashboard');
});

it('shows admin groups for an owner with every route accounted for', function () {
    $owner = staffUser('owner');

    $sections = sidebarSections($owner, 'admin');
    $keys = array_column($sections, 'key');

    expect($keys)->toContain('main', 'competition_admin', 'members', 'selection', 'comms', 'finance', 'reports', 'settings');
});

it('hides the Finance group from a user without a finance role', function () {
    $director = staffUser('match_director');

    $sections = sidebarSections($director, 'admin');
    $keys = array_column($sections, 'key');

    expect($keys)->not->toContain('finance')
        ->and($keys)->toContain('competition_admin');
});

it('hides the EXCO group from a user without EXCO or chair role', function () {
    $admin = staffUser('admin');

    $sections = sidebarSections($admin, 'admin');
    $keys = array_column($sections, 'key');

    expect($keys)->not->toContain('exco');
});

it('places every current admin route in exactly one nav item', function () {
    // Every item.route in the admin catalog must land in exactly one section.
    // Guards against a route being accidentally shown twice or dropped
    // altogether during future menu re-shuffles. documents.index legitimately
    // appears twice (Comms for admin, ExCo for sensitive docs) via distinct
    // item ids — count those as acceptable duplicates by filtering on id.
    $counts = [];
    foreach (SidebarMenu::catalog() as $section) {
        if (! in_array(SidebarMenu::CONTEXT_ADMIN, $section['contexts'], true)) {
            continue;
        }
        foreach ($section['items'] as $item) {
            if (! in_array(SidebarMenu::CONTEXT_ADMIN, $item['contexts'], true)) {
                continue;
            }
            // documents is intentionally offered under both Comms and ExCo
            // because members need it under Comms and ExCo keeps a quick link
            // to the same hub. Skip it from the duplicate check.
            if ($item['route'] === 'documents.index') {
                continue;
            }
            $counts[$item['route']] = ($counts[$item['route']] ?? 0) + 1;
        }
    }

    $duplicates = array_filter($counts, fn (int $n) => $n > 1);

    expect($duplicates)->toBe([])
        ->and($counts)->toHaveKeys([
            'dashboard', 'matches.index', 'registrations.index', 'score-imports.index',
            'scores.index', 'venues.index', 'memberships.index', 'approvals.index',
            'clubs.index', 'provincial-committees.index', 'provincial-members.index',
            'events.index', 'standings.public', 'selection.cycles.index',
            'appearances.index', 'team-years.index', 'iprf.index',
            'communications.index', 'announcements.index', 'contact-messages.index',
            'saved-lists.index', 'media.albums.index',
            'financials.dashboard', 'financials.income', 'financials.expenses',
            'financials.payouts', 'financials.credit-refunds.index',
            'financials.transactions', 'sponsors.index',
            'reports.index', 'reports.participation', 'reports.selection',
            'reports.sponsorship', 'sascoc-report.index',
            'exco.meetings.index', 'exco.actions.index', 'exco.disciplinary.index',
            'exco.members.index', 'exco.prompts',
            'fees.index', 'divisions.index', 'qualification-rules.index',
            'sponsor-tiers.index', 'site-settings.index',
            'user-management.index', 'audit-logs.index', 'email-logs.index',
        ]);
});

function requestForRoute(string $routeName): Illuminate\Http\Request
{
    $route = app('router')->getRoutes()->getByName($routeName);
    expect($route)->not->toBeNull("Route {$routeName} is missing");
    $request = Illuminate\Http\Request::create('/'.ltrim($route->uri(), '/'));
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('expands the Settings group when a settings route is active', function () {
    $owner = staffUser('owner');

    $sections = SidebarMenu::sectionsFor($owner, 'admin', requestForRoute('audit-logs.index'));

    $settings = collect($sections)->firstWhere('key', 'settings');

    expect($settings)->not->toBeNull()
        ->and($settings['has_current'])->toBeTrue()
        ->and($settings['expanded'])->toBeTrue();
});

it('leaves ExCo, Finance, Reports, and Settings collapsed when the active route is elsewhere', function () {
    // ExCo is gated to developer/exco/chair — developer sees everything so
    // we can assert the four collapsed-by-default governance groups.
    $dev = staffUser('developer');

    $sections = SidebarMenu::sectionsFor($dev, 'admin', requestForRoute('matches.index'));

    $exco = collect($sections)->firstWhere('key', 'exco');
    $finance = collect($sections)->firstWhere('key', 'finance');
    $reports = collect($sections)->firstWhere('key', 'reports');
    $settings = collect($sections)->firstWhere('key', 'settings');
    $competition = collect($sections)->firstWhere('key', 'competition_admin');

    expect($exco)->not->toBeNull()
        ->and($finance)->not->toBeNull()
        ->and($reports)->not->toBeNull()
        ->and($settings)->not->toBeNull()
        ->and($exco['expanded'])->toBeFalse()
        ->and($finance['expanded'])->toBeFalse()
        ->and($reports['expanded'])->toBeFalse()
        ->and($settings['expanded'])->toBeFalse()
        ->and($competition['expanded'])->toBeTrue();
});

it('shows Kit and Load tools in shooter mode without any admin groups', function () {
    $owner = staffUser('owner');

    $this->actingAs($owner)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    $sections = SidebarMenu::sectionsFor($owner->fresh(), 'shooter');
    $keys = array_column($sections, 'key');

    expect($keys)->toBe(['main', 'competition', 'me', 'kit', 'federation']);

    $kit = collect($sections)->firstWhere('key', 'kit');
    $labels = array_column($kit['items'], 'label');
    expect($labels)->toContain('Load tools')
        ->and($labels)->toContain('My rifles');
});

it('exposes a flat search catalog for the ⌘K dialog', function () {
    $owner = staffUser('owner');

    $results = SidebarMenu::searchItemsFor($owner, 'admin');
    $labels = array_column($results, 'label');

    expect($labels)->toContain('Matches')
        ->and($labels)->toContain('Audit logs')
        ->and(count($results))->toBeGreaterThan(30);
});

it('does not offer the view-as switch to a pure member', function () {
    $member = staffUser('member');

    $this->actingAs($member)
        ->get(route('profile'))
        ->assertOk()
        ->assertDontSee('View as admin', escape: false)
        ->assertSee('My rifles')
        ->assertSee('Rules & documents')
        ->assertDontSee('Manage matches');
});

it('renders admin navigation for an admin in admin mode', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->get(route('profile'))
        ->assertOk()
        ->assertSee('View as admin', escape: false)
        ->assertSee('Matches')
        ->assertSee('Messages')
        ->assertDontSee('My rifles')
        ->assertDontSee('Load tools');
});

it('renders shooter navigation after an admin flips the toggle', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->post(route('dashboard.view-mode'), ['mode' => 'shooter']);

    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('My rifles')
        ->assertSee('Load tools')
        ->assertSee('Rules & documents');
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
        ->assertOk();

    expect(session('view_mode'))->toBe('admin');
});

it('switches a staff user to shooter context when they open a personal route', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->get(route('rifle-configurations.index'))
        ->assertOk()
        ->assertSee('My rifles');

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
        ->and(sidebarLabels($provincial, 'admin'))->toContain('Provincial members')
        ->and(sidebarLabels($provincial, 'admin'))->not->toContain('Matches')
        ->and(sidebarLabels($provincial, 'admin'))->not->toContain('My rifles');
});

it('keeps match-director admin items limited to match management', function () {
    $director = staffUser('match_director');

    $labels = sidebarLabels($director, 'admin');

    expect($labels)->toContain('Matches')
        ->and($labels)->toContain('Score imports')
        ->and($labels)->not->toContain('Memberships')
        ->and($labels)->not->toContain('Users')
        ->and($labels)->not->toContain('My rifles');
});

it('treats registrations as dual-purpose and does not auto-switch', function () {
    $admin = staffUser('admin');

    $this->actingAs($admin)
        ->get(route('registrations.index'))
        ->assertOk();

    expect(session('view_mode'))->toBeNull()
        ->and($admin->fresh()->effectiveViewMode())->toBe('admin');
});

it('shows a parent badge on comms group when contact messages are unhandled', function () {
    $admin = staffUser('admin');

    ContactMessage::create([
        'first_name' => 'Bob',
        'surname' => 'Byte',
        'email' => 'bob@example.com',
        'subject' => 'A question',
        'message' => 'test message here please read',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    Cache::flush();

    $sections = SidebarMenu::sectionsFor($admin, 'admin');
    $comms = collect($sections)->firstWhere('key', 'comms');

    expect($comms)->not->toBeNull()
        ->and($comms['badge'])->toBe(1)
        ->and($comms['badge_color'])->toBe('amber');
});

it('caches per-user badge counts for 60 seconds', function () {
    $admin = staffUser('admin');

    ContactMessage::create([
        'first_name' => 'Bob',
        'surname' => 'Byte',
        'email' => 'bob@example.com',
        'subject' => 'A question',
        'message' => 'test message here please read',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    Cache::flush();
    SidebarMenu::sectionsFor($admin, 'admin');

    // Second contact added after the first resolve — the cached count should
    // still read 1 until the TTL expires.
    ContactMessage::create([
        'first_name' => 'Carol',
        'surname' => 'Cache',
        'email' => 'carol@example.com',
        'subject' => 'Another',
        'message' => 'another message to double-check caching',
        'spam_status' => ContactMessage::SPAM_CLEAN,
    ]);

    $sections = SidebarMenu::sectionsFor($admin, 'admin');
    $comms = collect($sections)->firstWhere('key', 'comms');

    expect($comms['badge'])->toBe(1);
});

it('keeps the old SidebarNavigation API functional as a shim', function () {
    $owner = staffUser('owner');

    expect(SidebarNavigation::CONTEXT_ADMIN)->toBe('admin')
        ->and(SidebarNavigation::labelsFor($owner, 'admin'))
            ->toEqual(SidebarMenu::labelsFor($owner, 'admin'));
});
