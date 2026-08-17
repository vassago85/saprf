<?php

/**
 * GuestShooterService is the choke point where sponsors and match
 * directors provision unclaimed accounts for shooters who aren't on the
 * platform yet. Two things must be true forever:
 *
 *   1. Dedup: two sponsors adding the same person (by email, or by name
 *      when the previous entry was itself a stub) must resolve back to
 *      the same User row. Otherwise the roster fills with duplicates and
 *      standings/certificate merges become impossible.
 *
 *   2. Never silently claim a REAL account by name. Two different people
 *      can share a name; if the sponsor's dedup lookup finds a real
 *      member with the same name, we must create a fresh stub rather
 *      than attach the sponsored entry to a stranger's account.
 */

use App\Models\Membership;
use App\Models\User;
use App\Services\GuestShooterService;

beforeEach(function () {
    seedRoles();
    $this->service = app(GuestShooterService::class);
});

it('creates a new stub with a placeholder email when only a name is given', function () {
    $user = $this->service->findOrCreate('Jane Doe');

    expect($user->exists)->toBeTrue()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email)->toBe('jane.doe@import.saprf.local')
        ->and($user->is_active)->toBeTrue()
        ->and((bool) $user->is_managed_account)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->hasRole('member'))->toBeTrue();

    $membership = Membership::where('user_id', $user->id)->firstOrFail();
    expect($membership->membership_type)->toBe('free')
        ->and($membership->status)->toBe('pending')
        ->and($membership->payment_status)->toBe('unpaid')
        ->and($membership->saprf_number)->toStartWith('SAPRF-IMPORT-');
});

it('uses the real email when supplied and lowercases it for storage', function () {
    $user = $this->service->findOrCreate('Bob Real', 'Bob.REAL@example.com');

    expect($user->email)->toBe('bob.real@example.com')
        ->and($user->name)->toBe('Bob Real');
});

it('normalises whitespace in the name', function () {
    $user = $this->service->findOrCreate("  John   Q.   Public  ");

    expect($user->name)->toBe('John Q. Public');
});

it('rejects an empty name after normalisation', function () {
    expect(fn () => $this->service->findOrCreate('   '))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a syntactically invalid email', function () {
    expect(fn () => $this->service->findOrCreate('Jane Doe', 'not-an-email'))
        ->toThrow(InvalidArgumentException::class);
});

it('returns the existing user when the supplied email matches (case-insensitive)', function () {
    $existing = User::factory()->create([
        'email' => 'alice@example.com',
        'name' => 'Alice Different Name',
    ]);

    $resolved = $this->service->findOrCreate('Alice Anyname', 'ALICE@example.com');

    expect($resolved->id)->toBe($existing->id)
        ->and(User::count())->toBe(1);
});

it('returns the same stub when the same name is submitted twice with no email', function () {
    $first = $this->service->findOrCreate('Repeat Shooter');
    $second = $this->service->findOrCreate('repeat shooter');

    expect($second->id)->toBe($first->id)
        ->and(User::where('name', 'Repeat Shooter')->count())->toBe(1);
});

it('never silently claims a real (non-stub) account by name alone', function () {
    // A real member happens to share a name with the sponsored shooter.
    // We must NOT attach the sponsored entry to their account — create a
    // fresh stub instead, so nothing in their profile changes.
    $realMember = User::factory()->create([
        'email' => 'nate.thompson@gmail.com',
        'name' => 'Nate Thompson',
    ]);

    $stub = $this->service->findOrCreate('Nate Thompson');

    expect($stub->id)->not->toBe($realMember->id)
        ->and($stub->email)->toBe('nate.thompson@import.saprf.local')
        ->and(User::where('name', 'Nate Thompson')->count())->toBe(2);
});

it('upgrades a stub to a real email when the caller supplies one later', function () {
    // First sponsor only had a name — placeholder email created.
    $first = $this->service->findOrCreate('Later Emailed');
    expect($first->email)->toBe('later.emailed@import.saprf.local');

    // Second sponsor knows the shooter's real email. Rather than create
    // a second stub, we upgrade the existing one so future lookups by
    // email hit the same row.
    $second = $this->service->findOrCreate('Later Emailed', 'later@example.com');

    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->email)->toBe('later@example.com')
        ->and(User::where('name', 'Later Emailed')->count())->toBe(1);
});

it('does not overwrite an existing real email on a stub-that-was-upgraded', function () {
    // Once a stub has a real email attached, subsequent findOrCreate
    // calls with a DIFFERENT email should NOT clobber it — they'd fall
    // out to a fresh stub instead, because the name-based dedup only
    // applies to placeholder-email stubs.
    $upgraded = User::factory()->create([
        'email' => 'first@example.com',
        'name' => 'Was Upgraded',
    ]);

    $fresh = $this->service->findOrCreate('Was Upgraded', 'second@example.com');

    expect($fresh->id)->not->toBe($upgraded->id)
        ->and($upgraded->fresh()->email)->toBe('first@example.com');
});

it('uniques the placeholder email when the slug is already taken by a differently-named stub', function () {
    // An earlier import parked a stub at quirky.example@import.saprf.local
    // under a DIFFERENT name (say the sponsor typo'd first time). A new
    // sponsor now enters someone whose slug happens to hit the same
    // placeholder. Name-based dedup misses (different names), so we fall
    // through to createStub — and the placeholder must be suffixed to
    // dodge the unique-email index rather than crashing the request.
    User::factory()->create([
        'name' => 'Original Stub Owner',
        'email' => 'quirky.example@import.saprf.local',
    ]);

    $collider = $this->service->findOrCreate('Quirky Example');

    expect($collider->email)->toBe('quirky.example2@import.saprf.local');
});
