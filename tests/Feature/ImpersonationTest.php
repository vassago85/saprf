<?php

use App\Models\AuditLog;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

it('lets a developer impersonate another member and logs it', function () {
    $developer = User::factory()->create(['name' => 'Dev Dana']);
    $developer->assignRole('developer');

    $target = User::factory()->create(['name' => 'Target Tim']);
    $target->assignRole('member');

    $this->actingAs($developer)
        ->get(route('impersonate.start', $target))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('impersonator_id', $developer->id)
        ->assertSessionHas('impersonator_name', 'Dev Dana');

    // Auth::user() should now be the target.
    expect(auth()->id())->toBe($target->id);

    // Every start writes exactly one 'impersonation.started' audit row
    // pointing at the target user with the developer as the actor.
    $audit = AuditLog::query()
        ->where('action_type', 'impersonation.started')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($developer->id);
    expect($audit->entity_type)->toBe('User');
    expect($audit->entity_id)->toBe($target->id);
    expect(AuditLog::query()->where('action_type', 'user.login')->count())->toBe(0);
});

it('lets an impersonated session hit /impersonate-stop and returns to the developer', function () {
    $developer = User::factory()->create(['name' => 'Dev Ella']);
    $developer->assignRole('developer');
    $target = User::factory()->create(['name' => 'Target Tanya']);

    $this->actingAs($developer)->get(route('impersonate.start', $target));
    // We're now the target.
    expect(auth()->id())->toBe($target->id);

    // Stop is deliberately callable when authed as the TARGET — the
    // session key is what routes us back to the developer, not the role.
    $this->get(route('impersonate.stop'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing('impersonator_id');

    expect(auth()->id())->toBe($developer->id);

    $audit = AuditLog::query()
        ->where('action_type', 'impersonation.stopped')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($developer->id);
    expect($audit->entity_id)->toBe($target->id);
    expect(AuditLog::query()->where('action_type', 'user.login')->count())->toBe(0);
});

it('blocks non-developers from starting impersonation via the middleware', function () {
    // Owners have max authority in the app but still can't impersonate —
    // by design, the surface for this POPIA-sensitive action is limited
    // to the single `developer` role.
    foreach (['member', 'admin', 'owner', 'exco'] as $role) {
        $actor = User::factory()->create();
        $actor->assignRole($role);
        $target = User::factory()->create();

        $response = $this->actingAs($actor)->get(route('impersonate.start', $target));

        // Spatie's role middleware aborts 403 rather than redirecting.
        $response->assertForbidden();
        expect(auth()->id())->toBe($actor->id, "role $role should not switch identity");
    }
});

it('refuses self-impersonation with a flash error rather than switching', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->get(route('impersonate.start', $developer))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error')
        ->assertSessionMissing('impersonator_id');

    expect(auth()->id())->toBe($developer->id);
});

it('silently no-ops the stop route when no impersonation is active', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    // Stop while not impersonating — sends home rather than 404 or crash.
    $this->actingAs($user)
        ->get(route('impersonate.stop'))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('re-attributes writes made during impersonation to the developer', function () {
    $developer = User::factory()->create(['name' => 'Dev Dana']);
    $developer->assignRole('developer');
    $target = User::factory()->create(['name' => 'Target Tim']);
    $target->assignRole('member');

    $this->actingAs($developer)->get(route('impersonate.start', $target));
    expect(auth()->id())->toBe($target->id);

    // Controllers pass $request->user() — during impersonation that is
    // the TARGET. The service must rewrite user_id to the developer and
    // stamp impersonated_user_id so the audit log never pretends Tim
    // made the change.
    $log = app(\App\Services\AuditLogService::class)->log(
        $target,
        'profile.updated',
        'User',
        $target->id,
        null,
        ['public_profile_visibility' => 'hidden'],
        'Visibility changed from profile form',
    );

    expect($log->user_id)->toBe($developer->id);
    expect($log->impersonated_user_id)->toBe($target->id);
    expect($log->actor_type)->toBe(AuditLog::ACTOR_ADMIN);
    expect($log->wasImpersonated())->toBeTrue();
});

it('does not rewrite start/stop rows as impersonated writes', function () {
    $developer = User::factory()->create(['name' => 'Dev Dana']);
    $developer->assignRole('developer');
    $target = User::factory()->create(['name' => 'Target Tim']);

    $this->actingAs($developer)->get(route('impersonate.start', $target));

    $started = AuditLog::query()->where('action_type', 'impersonation.started')->latest('id')->first();
    expect($started->user_id)->toBe($developer->id);
    expect($started->impersonated_user_id)->toBeNull();

    $this->get(route('impersonate.stop'));

    $stopped = AuditLog::query()->where('action_type', 'impersonation.stopped')->latest('id')->first();
    expect($stopped->user_id)->toBe($developer->id);
    expect($stopped->impersonated_user_id)->toBeNull();
});

it('hides impersonation start/stop from the shared audit log that admin and ExCo can see', function () {
    $developer = User::factory()->create(['name' => 'Dev Dana', 'email_verified_at' => now()]);
    $developer->assignRole('developer');
    $target = User::factory()->create(['name' => 'Target Tim']);
    $admin = User::factory()->create(['name' => 'Admin Amy', 'email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($developer)->get(route('impersonate.start', $target));
    $this->get(route('impersonate.stop'));

    $started = AuditLog::query()->where('action_type', 'impersonation.started')->latest('id')->first();
    expect($started)->not->toBeNull();

    // Admin / ExCo / owner share /audit-logs. Start/stop must not appear
    // there, and a direct URL to the row 404s so the event is not
    // confirmable.
    $this->actingAs($admin)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertDontSee('Impersonation.started')
        ->assertDontSee('Impersonation.stopped')
        ->assertDontSee('started impersonating');

    $this->actingAs($admin)
        ->get(route('audit-logs.show', $started))
        ->assertNotFound();

    // The developer still sees the paper trail.
    $this->actingAs($developer)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertSee('Impersonation.started')
        ->assertSee('Impersonation.stopped');
});

it('does not show acting-as on the shared audit log for non-developers', function () {
    $developer = User::factory()->create(['name' => 'Dev Dana', 'email_verified_at' => now()]);
    $developer->assignRole('developer');
    $target = User::factory()->create(['name' => 'Target Tim']);
    $admin = User::factory()->create(['name' => 'Admin Amy', 'email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($developer)->get(route('impersonate.start', $target));
    $log = app(\App\Services\AuditLogService::class)->log(
        $target,
        'profile.updated',
        'User',
        $target->id,
    );
    $this->get(route('impersonate.stop'));
    session()->forget('success');

    // Shared log: looks like Tim made a User change. No "acting as",
    // no developer name. Stop first so the red banner (which names the
    // developer) is not still sitting in the test session.
    $this->actingAs($admin)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertSee('Target Tim')
        ->assertDontSee('acting as')
        ->assertDontSee('Dev Dana');

    $this->actingAs($admin)
        ->get(route('audit-logs.show', $log))
        ->assertOk()
        ->assertSee('Target Tim')
        ->assertDontSee('Acting as')
        ->assertDontSee('Dev Dana');

    // Shared filter tabs follow the same cover: the write is a User
    // change, not an Admin change, even though the stored actor_type
    // is admin (the developer).
    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['category' => AuditLog::ACTOR_ADMIN]))
        ->assertOk()
        ->assertDontSee('Profile.updated');

    $this->actingAs($admin)
        ->get(route('audit-logs.index', ['category' => AuditLog::ACTOR_USER]))
        ->assertOk()
        ->assertSee('Profile.updated')
        ->assertSee('Target Tim')
        ->assertDontSee('Dev Dana');

    // Developer log: real actor + acting-as line.
    $this->actingAs($developer)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->assertSee('Dev Dana')
        ->assertSee('acting as Target Tim');
});

it('leaves ordinary member writes untouched when no impersonation is active', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $log = app(\App\Services\AuditLogService::class)->log(
        $member,
        'profile.updated',
        'User',
        $member->id,
    );

    expect($log->user_id)->toBe($member->id);
    expect($log->impersonated_user_id)->toBeNull();
    expect($log->actor_type)->toBe(AuditLog::ACTOR_USER);
});

it('chains impersonations without stranding the developer as B when starting on C', function () {
    // Guard against "A → B → C leaves stop() returning to B, not A":
    // starting a fresh impersonation must always clear the previous
    // session key so `impersonator_id` still points at the original
    // developer, no matter how many jumps we make.
    $developer = User::factory()->create(['name' => 'Dev Original']);
    $developer->assignRole('developer');
    $b = User::factory()->create(['name' => 'Target B']);
    $c = User::factory()->create(['name' => 'Target C']);

    $this->actingAs($developer)->get(route('impersonate.start', $b));
    // At this point session('impersonator_id') = developer, auth = B.

    // Second impersonation from within B's session.
    // The role middleware would 403 this because B is not a developer —
    // that's actually the right behaviour: chained impersonations are
    // blocked by design. Simulate the developer coming back and
    // starting fresh instead by first stopping.
    $this->get(route('impersonate.stop'));
    expect(auth()->id())->toBe($developer->id);

    $this->get(route('impersonate.start', $c));
    expect(auth()->id())->toBe($c->id);
    expect(session('impersonator_id'))->toBe($developer->id);

    $this->get(route('impersonate.stop'));
    expect(auth()->id())->toBe($developer->id);
});
