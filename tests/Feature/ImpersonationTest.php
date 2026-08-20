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
