<?php

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    foreach (['developer'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    // Static cache persists between tests in the same PHP process; without
    // this we get stale hits when auto-increment IDs collide across tests.
    AuditLog::clearSubjectCache();
});

it('resolves a User entity to name, email and SAPRF number', function () {
    $target = User::factory()->create([
        'name' => 'Paul Charsley',
        'email' => 'paul@example.test',
    ]);
    Membership::create([
        'user_id' => $target->id,
        'saprf_number' => 'SAPRF-1701',
        'status' => 'active',
    ]);

    $log = AuditLog::create([
        'user_id' => $target->id,
        'actor_type' => AuditLog::ACTOR_ADMIN,
        'action_type' => 'roles_changed',
        'entity_type' => 'User',
        'entity_id' => $target->id,
        'created_at' => now(),
    ]);

    $subject = $log->resolveSubject();

    expect($subject)->not->toBeNull()
        ->and($subject['name'])->toBe('Paul Charsley')
        ->and($subject['email'])->toBe('paul@example.test')
        ->and($subject['saprf_number'])->toBe('SAPRF-1701')
        ->and($subject['is_deleted'])->toBeFalse()
        ->and($subject['edit_url'])->toContain('/user-management/' . $target->id . '/edit');
});

it('resolves a Membership entity via its owning user', function () {
    $target = User::factory()->create([
        'name' => 'Jane Shooter',
        'email' => 'jane@example.test',
    ]);
    $membership = Membership::create([
        'user_id' => $target->id,
        'saprf_number' => 'SAPRF-2050',
        'status' => 'active',
    ]);

    $log = AuditLog::create([
        'user_id' => null,
        'actor_type' => AuditLog::ACTOR_SYSTEM,
        'action_type' => 'membership.auto_lapsed',
        'entity_type' => 'Membership',
        'entity_id' => $membership->id,
        'reason' => 'auto lapsed',
        'created_at' => now(),
    ]);

    $subject = $log->resolveSubject();

    expect($subject)->not->toBeNull()
        ->and($subject['name'])->toBe('Jane Shooter')
        ->and($subject['email'])->toBe('jane@example.test')
        ->and($subject['saprf_number'])->toBe('SAPRF-2050');
});

it('still resolves a User entity when the user has been soft-deleted', function () {
    $target = User::factory()->create(['name' => 'Deleted Dan']);
    $target->delete();

    $log = AuditLog::create([
        'user_id' => null,
        'actor_type' => AuditLog::ACTOR_ADMIN,
        'action_type' => 'user.soft_deleted',
        'entity_type' => 'User',
        'entity_id' => $target->id,
        'created_at' => now(),
    ]);

    $subject = $log->resolveSubject();

    expect($subject)->not->toBeNull()
        ->and($subject['name'])->toBe('Deleted Dan')
        ->and($subject['is_deleted'])->toBeTrue()
        ->and($subject['edit_url'])->toBeNull();
});

it('returns null for entity types without a subject (Settings, Divisions, etc.)', function () {
    $log = AuditLog::create([
        'user_id' => null,
        'actor_type' => AuditLog::ACTOR_ADMIN,
        'action_type' => 'settings_updated',
        'entity_type' => 'Setting',
        'entity_id' => 1,
        'created_at' => now(),
    ]);

    expect($log->resolveSubject())->toBeNull();
});

it('renders the affected user on the audit log detail page', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $target = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ]);
    Membership::create([
        'user_id' => $target->id,
        'saprf_number' => 'SAPRF-9999',
        'status' => 'active',
    ]);

    $log = AuditLog::create([
        'user_id' => $developer->id,
        'actor_type' => AuditLog::ACTOR_ADMIN,
        'action_type' => 'roles_changed',
        'entity_type' => 'User',
        'entity_id' => $target->id,
        'old_value' => ['roles' => ['member']],
        'new_value' => ['roles' => ['member', 'admin']],
        'reason' => 'test',
        'created_at' => now(),
    ]);

    $this->actingAs($developer)
        ->get(route('audit-logs.show', $log))
        ->assertOk()
        ->assertSee('Ada Lovelace')
        ->assertSee('ada@example.test')
        ->assertSee('SAPRF-9999')
        ->assertSee(route('user-management.edit', $target->id));
});

it('renders the affected user on the audit log index list', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $target = User::factory()->create(['name' => 'Grace Hopper']);
    Membership::create([
        'user_id' => $target->id,
        'saprf_number' => 'SAPRF-COBOL',
        'status' => 'active',
    ]);

    AuditLog::create([
        'user_id' => $developer->id,
        'actor_type' => AuditLog::ACTOR_ADMIN,
        'action_type' => 'roles_changed',
        'entity_type' => 'User',
        'entity_id' => $target->id,
        'reason' => 'test',
        'created_at' => now(),
    ]);

    $this->actingAs($developer)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertSee('Grace Hopper')
        ->assertSee('SAPRF-COBOL');
});
