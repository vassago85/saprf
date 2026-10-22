<?php

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRoles();
});

/**
 * Seed one owner (the acting user), one bouncing / unverified / never-logged-in
 * "bot-ish" account, and one normal verified account that has signed in.
 * Return the trio as [$owner, $bot, $real] so tests can assert exactly which
 * rows the filter / bulk-delete surfaces.
 */
function seedBotSweepFixtures(): array
{
    $owner = User::factory()->create(['name' => 'Owner Person', 'email_verified_at' => now()]);
    $owner->assignRole('owner');

    $bot = User::factory()->create([
        'name' => 'keqjokbg nbpvdblr',
        'email' => 'crackllc373@gmail.com',
        'email_verified_at' => null,
        'email_bounced_at' => now()->subDays(2),
    ]);
    $bot->assignRole('member');

    $real = User::factory()->create([
        'name' => 'Real Shooter',
        'email' => 'real@example.com',
        'email_verified_at' => now()->subMonth(),
        'email_bounced_at' => null,
    ]);
    $real->assignRole('member');

    // Give the real user a login audit row so the "has signed in" filter
    // separates them from the bot. The AuthAuditListener writes exactly
    // this shape on every Login event in production.
    app(AuditLogService::class)->log(
        actor: $real,
        actionType: 'user.login',
        entityType: 'User',
        entityId: $real->id,
    );

    return [$owner, $bot, $real];
}

test('the email_status=bouncing filter lists only bounced accounts', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->get(route('user-management.index', ['email_status' => 'bouncing']))
        ->assertOk()
        ->assertSee($bot->email)
        ->assertDontSee($real->email);
});

test('the verified=no filter lists only unverified accounts', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->get(route('user-management.index', ['verified' => 'no']))
        ->assertOk()
        ->assertSee($bot->email)
        ->assertDontSee($real->email);
});

test('the login_history=never filter lists only accounts without a login audit row', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->get(route('user-management.index', ['login_history' => 'never']))
        ->assertOk()
        ->assertSee($bot->email)
        ->assertDontSee($real->email);
});

test('combining all three bot-sweep filters surfaces only the bot-ish account', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->get(route('user-management.index', [
            'email_status' => 'bouncing',
            'verified' => 'no',
            'login_history' => 'never',
        ]))
        ->assertOk()
        ->assertSee($bot->email)
        ->assertDontSee($real->email);
});

test('the user management index renders the per-row Bouncing / Unverified badges', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->get(route('user-management.index'))
        ->assertOk()
        ->assertSee('Bouncing')
        ->assertSee('Unverified');
});

test('bulk-destroy soft-deletes every selected non-owner, non-self user', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->post(route('user-management.bulk-destroy'), [
            'user_ids' => [$bot->id, $real->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(User::find($bot->id))->toBeNull()
        ->and(User::find($real->id))->toBeNull()
        ->and(User::withTrashed()->find($bot->id))->not->toBeNull()
        ->and(User::withTrashed()->find($real->id))->not->toBeNull();
});

test('bulk-destroy silently skips owner accounts even when they are selected', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $secondOwner = User::factory()->create(['name' => 'Second Owner']);
    $secondOwner->assignRole('owner');

    $this->actingAs($owner)
        ->post(route('user-management.bulk-destroy'), [
            'user_ids' => [$bot->id, $secondOwner->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(User::find($bot->id))->toBeNull()           // deleted
        ->and(User::find($secondOwner->id))->not->toBeNull(); // skipped
});

test('bulk-destroy never deletes the acting user even when their id is in the payload', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    // Add a developer that we'll act as — owners get skipped by the owner
    // guard, so to isolate the "acting user" guard we use a developer who
    // owns the user-management permission via Gate::before in AppServiceProvider.
    $developer = User::factory()->create(['name' => 'Dev Person']);
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->post(route('user-management.bulk-destroy'), [
            'user_ids' => [$developer->id, $bot->id],
        ])
        ->assertRedirect();

    expect(User::find($developer->id))->not->toBeNull()  // self-skip
        ->and(User::find($bot->id))->toBeNull();         // deleted
});

test('bulk-destroy writes an audit row for every soft-deleted user', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->post(route('user-management.bulk-destroy'), [
            'user_ids' => [$bot->id, $real->id],
        ])
        ->assertRedirect();

    $audits = DB::table('audit_logs')
        ->where('action_type', 'user.soft_deleted')
        ->whereIn('entity_id', [$bot->id, $real->id])
        ->get();

    expect($audits)->toHaveCount(2);
    foreach ($audits as $row) {
        expect($row->reason)->toBe('Bulk soft-delete from User Management');
    }
});

test('bulk-destroy requires at least one user id', function () {
    [$owner] = seedBotSweepFixtures();

    $this->actingAs($owner)
        ->post(route('user-management.bulk-destroy'), [
            'user_ids' => [],
        ])
        ->assertSessionHasErrors('user_ids');
});

test('bulk-destroy is unreachable without the owner / developer / exco role', function () {
    [$owner, $bot, $real] = seedBotSweepFixtures();

    // seedBotSweepFixtures assigns member to $real; using them keeps the
    // test honest — a plain member must not reach the bulk endpoint.
    $this->actingAs($real)
        ->post(route('user-management.bulk-destroy'), [
            'user_ids' => [$bot->id],
        ])
        ->assertStatus(403);

    expect(User::find($bot->id))->not->toBeNull();
});
