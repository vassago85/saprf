<?php

use App\Models\AuditLog;
use App\Models\Province;
use App\Models\ProvincialCommittee;
use App\Models\User;
use App\Services\AuditLogService;

beforeEach(function () {
    seedRoles();
});

function audit(): AuditLogService
{
    return app(AuditLogService::class);
}

it('classifies a change with no actor as a system change', function () {
    $log = audit()->log(null, 'membership.expired', 'Membership', 1);

    expect($log->actor_type)->toBe(AuditLog::ACTOR_SYSTEM);
});

it('classifies a change by a staff member as an admin change', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $log = audit()->log($admin, 'membership.updated', 'Membership', 1);

    expect($log->actor_type)->toBe(AuditLog::ACTOR_ADMIN);
});

it('classifies a change by a provincial committee member as an admin change', function () {
    $province = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $committeeMember = User::factory()->create(['province_id' => $province->id]);
    $committeeMember->assignRole('member');
    ProvincialCommittee::create([
        'user_id' => $committeeMember->id,
        'province_id' => $province->id,
        'position' => 'chair',
        'is_active' => true,
    ]);

    $log = audit()->log($committeeMember, 'member.updated', 'User', 5);

    expect($log->actor_type)->toBe(AuditLog::ACTOR_ADMIN);
});

it('classifies a change by an ordinary member as a user change', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $log = audit()->log($member, 'profile.updated', 'User', $member->id);

    expect($log->actor_type)->toBe(AuditLog::ACTOR_USER);
});

it('filters the audit log index by actor category', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    audit()->log(null, 'system.job', 'Membership', 1);
    audit()->log($admin, 'membership.updated', 'Membership', 2);
    audit()->log($member, 'profile.updated', 'User', $member->id);

    // The action label is rendered with ucfirst(), so match that casing.
    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['category' => AuditLog::ACTOR_SYSTEM]))
        ->assertOk()
        ->assertSee('System.job')
        ->assertDontSee('Membership.updated')
        ->assertDontSee('Profile.updated');
});
