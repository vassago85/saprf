<?php

/**
 * Route-level gating for the ExCo workspace (meetings + actions +
 * disciplinary). Only ExCo, Chair and developer are allowed anywhere
 * in the /exco/* prefix. Owner and admin — normally senior admin
 * roles — are refused because these routes can hold personal
 * disciplinary information.
 */

use App\Enums\DisciplinaryCaseStatus;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Models\DisciplinaryCase;
use App\Models\ExcoMeeting;
use App\Models\User;
use App\Support\SidebarNavigation;

beforeEach(function () {
    seedRoles();
});

function excoWithRole(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole([$role, 'member']);

    return $user->fresh();
}

function excoMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    return $user->fresh();
}

// ── Meeting index ─────────────────────────────────────────────────────

it('lets exco reach the meetings index', function () {
    $this->actingAs(excoWithRole('exco'))
        ->get(route('exco.meetings.index'))
        ->assertOk();
});

it('lets chair reach the meetings index', function () {
    $this->actingAs(excoWithRole('chair'))
        ->get(route('exco.meetings.index'))
        ->assertOk();
});

it('lets developer reach the meetings index', function () {
    $this->actingAs(excoWithRole('developer'))
        ->get(route('exco.meetings.index'))
        ->assertOk();
});

it('blocks plain members from the meetings index', function () {
    $this->actingAs(excoMember())
        ->get(route('exco.meetings.index'))
        ->assertForbidden();
});

it('blocks owners from the meetings index', function () {
    $this->actingAs(excoWithRole('owner'))
        ->get(route('exco.meetings.index'))
        ->assertForbidden();
});

it('blocks admins from the meetings index', function () {
    $this->actingAs(excoWithRole('admin'))
        ->get(route('exco.meetings.index'))
        ->assertForbidden();
});

it('blocks match directors from the meetings index', function () {
    $this->actingAs(excoWithRole('match_director'))
        ->get(route('exco.meetings.index'))
        ->assertForbidden();
});

// ── Meeting show / edit ───────────────────────────────────────────────

it('blocks members from a specific meeting page', function () {
    $exco = excoWithRole('exco');
    $meeting = ExcoMeeting::create([
        'title' => 'Sitting',
        'type' => ExcoMeetingType::Regular,
        'scheduled_at' => now()->addDay(),
        'status' => ExcoMeetingStatus::Draft,
        'created_by' => $exco->id,
    ]);

    $this->actingAs(excoMember())
        ->get(route('exco.meetings.show', $meeting))
        ->assertForbidden();
});

it('blocks owners from creating meetings', function () {
    $this->actingAs(excoWithRole('owner'))
        ->get(route('exco.meetings.create'))
        ->assertForbidden();
});

// ── Actions index ─────────────────────────────────────────────────────

it('blocks admins from the actions index', function () {
    $this->actingAs(excoWithRole('admin'))
        ->get(route('exco.actions.index'))
        ->assertForbidden();
});

it('lets exco reach the actions index', function () {
    $this->actingAs(excoWithRole('exco'))
        ->get(route('exco.actions.index'))
        ->assertOk();
});

// ── Disciplinary ──────────────────────────────────────────────────────

it('blocks plain members from the disciplinary index', function () {
    $this->actingAs(excoMember())
        ->get(route('exco.disciplinary.index'))
        ->assertForbidden();
});

it('blocks owners from the disciplinary index', function () {
    $this->actingAs(excoWithRole('owner'))
        ->get(route('exco.disciplinary.index'))
        ->assertForbidden();
});

it('blocks admins from the disciplinary index', function () {
    $this->actingAs(excoWithRole('admin'))
        ->get(route('exco.disciplinary.index'))
        ->assertForbidden();
});

it('lets exco reach the disciplinary index', function () {
    $this->actingAs(excoWithRole('exco'))
        ->get(route('exco.disciplinary.index'))
        ->assertOk();
});

it('lets chair reach the disciplinary index', function () {
    $this->actingAs(excoWithRole('chair'))
        ->get(route('exco.disciplinary.index'))
        ->assertOk();
});

it('blocks admins from viewing an individual case even by direct URL', function () {
    $exco = excoWithRole('exco');
    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-999',
        'title' => 'Sensitive matter',
        'status' => DisciplinaryCaseStatus::Reported,
        'subject_name' => 'External',
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    $this->actingAs(excoWithRole('admin'))
        ->get(route('exco.disciplinary.show', $case))
        ->assertForbidden();
});

it('blocks the subject-search endpoint for members', function () {
    $this->actingAs(excoMember())
        ->get(route('exco.disciplinary.subject-search', ['q' => 'test']))
        ->assertForbidden();
});

// ── Sidebar visibility ────────────────────────────────────────────────

it('shows ExCo section in the sidebar for an exco user', function () {
    $labels = SidebarNavigation::labelsFor(excoWithRole('exco'), 'admin');

    expect($labels)->toContain('Meetings')
        ->and($labels)->toContain('Actions')
        ->and($labels)->toContain('Disciplinary');
});

it('shows ExCo section in the sidebar for a chair user', function () {
    $labels = SidebarNavigation::labelsFor(excoWithRole('chair'), 'admin');

    expect($labels)->toContain('Meetings')
        ->and($labels)->toContain('Disciplinary');
});

it('hides ExCo section from owner even in admin mode', function () {
    $labels = SidebarNavigation::labelsFor(excoWithRole('owner'), 'admin');

    expect($labels)->not->toContain('Disciplinary');
});

it('hides ExCo section from admin even in admin mode', function () {
    $labels = SidebarNavigation::labelsFor(excoWithRole('admin'), 'admin');

    expect($labels)->not->toContain('Disciplinary');
});

it('hides ExCo section from a plain member', function () {
    $labels = SidebarNavigation::labelsFor(excoMember(), 'shooter');

    expect($labels)->not->toContain('Meetings')
        ->and($labels)->not->toContain('Actions')
        ->and($labels)->not->toContain('Disciplinary');
});
