<?php

/**
 * EXCO ownership override for matches: reassigns `created_by` (the source
 * of truth for management rights and MD payouts) and keeps display fields
 * in sync. Match directors, owner, and admin must NOT be able to trigger
 * this transfer — even though they can otherwise edit the match.
 */

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
});

function makeMatchOwnedBy_change(User $creator, Province $province): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Ownership Transfer Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $creator->id,
        'match_director' => $creator->name,
    ]);
}

// ── Happy path: transfer ownership ────────────────────────────────────

it('lets exco transfer ownership to a new user', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $oldMd = User::factory()->create(['name' => 'Old Director']);
    $oldMd->assignRole('match_director');

    $newMd = User::factory()->create(['name' => 'New Director', 'phone' => '0821234567']);

    $match = makeMatchOwnedBy_change($oldMd, $this->province);

    $this->actingAs($exco)
        ->post(route('matches.change-director', $match), ['user_id' => $newMd->id])
        ->assertRedirect(route('matches.show', $match));

    $match->refresh();

    expect($match->created_by)->toBe($newMd->id)
        ->and($match->match_director)->toBe('New Director')
        ->and($match->match_director_contact)->toBe('0821234567')
        ->and($newMd->fresh()->hasRole('match_director'))->toBeTrue();
});

it('lets developer transfer ownership', function () {
    $dev = User::factory()->create();
    $dev->assignRole('developer');

    $oldMd = User::factory()->create();
    $oldMd->assignRole('match_director');

    $newMd = User::factory()->create();

    $match = makeMatchOwnedBy_change($oldMd, $this->province);

    $this->actingAs($dev)
        ->post(route('matches.change-director', $match), ['user_id' => $newMd->id])
        ->assertRedirect(route('matches.show', $match));

    expect($match->fresh()->created_by)->toBe($newMd->id);
});

it('lets chair transfer ownership', function () {
    $chair = User::factory()->create();
    $chair->assignRole(['chair', 'exco']);

    $oldMd = User::factory()->create();
    $oldMd->assignRole('match_director');

    $newMd = User::factory()->create();

    $match = makeMatchOwnedBy_change($oldMd, $this->province);

    $this->actingAs($chair)
        ->post(route('matches.change-director', $match), ['user_id' => $newMd->id])
        ->assertRedirect(route('matches.show', $match));

    expect($match->fresh()->created_by)->toBe($newMd->id);
});

// ── Denied roles ──────────────────────────────────────────────────────

it('refuses match director from changing director on their own match', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');

    $other = User::factory()->create();

    $match = makeMatchOwnedBy_change($md, $this->province);

    $this->actingAs($md)
        ->post(route('matches.change-director', $match), ['user_id' => $other->id])
        ->assertForbidden();

    expect($match->fresh()->created_by)->toBe($md->id);
});

it('refuses owner from changing director', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $other = User::factory()->create();

    $match = makeMatchOwnedBy_change($md, $this->province);

    $this->actingAs($owner)
        ->post(route('matches.change-director', $match), ['user_id' => $other->id])
        ->assertForbidden();
});

it('refuses admin from changing director', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $other = User::factory()->create();

    $match = makeMatchOwnedBy_change($md, $this->province);

    $this->actingAs($admin)
        ->post(route('matches.change-director', $match), ['user_id' => $other->id])
        ->assertForbidden();
});

it('refuses a plain member from changing director', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $md = User::factory()->create();
    $md->assignRole('match_director');
    $other = User::factory()->create();

    $match = makeMatchOwnedBy_change($md, $this->province);

    $this->actingAs($member)
        ->post(route('matches.change-director', $match), ['user_id' => $other->id])
        ->assertForbidden();
});

// ── Validation ────────────────────────────────────────────────────────

it('rejects selecting the current director', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $md = User::factory()->create();
    $md->assignRole('match_director');

    $match = makeMatchOwnedBy_change($md, $this->province);

    $this->actingAs($exco)
        ->from(route('matches.show', $match))
        ->post(route('matches.change-director', $match), ['user_id' => $md->id])
        ->assertRedirect(route('matches.show', $match))
        ->assertSessionHasErrors('user_id');

    expect($match->fresh()->created_by)->toBe($md->id);
});

it('rejects a non-existent user_id', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $md = User::factory()->create();
    $md->assignRole('match_director');

    $match = makeMatchOwnedBy_change($md, $this->province);

    $this->actingAs($exco)
        ->from(route('matches.show', $match))
        ->post(route('matches.change-director', $match), ['user_id' => 999999])
        ->assertSessionHasErrors('user_id');
});

// ── Side effects ──────────────────────────────────────────────────────

it('leaves the previous director with their match_director role', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $oldMd = User::factory()->create();
    $oldMd->assignRole('match_director');

    $newMd = User::factory()->create();

    $match = makeMatchOwnedBy_change($oldMd, $this->province);

    $this->actingAs($exco)
        ->post(route('matches.change-director', $match), ['user_id' => $newMd->id]);

    expect($oldMd->fresh()->hasRole('match_director'))->toBeTrue();
});

it('preserves existing contact when new director has no phone', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $oldMd = User::factory()->create();
    $oldMd->assignRole('match_director');

    $newMd = User::factory()->create(['phone' => null]);

    $match = makeMatchOwnedBy_change($oldMd, $this->province);
    $match->update(['match_director_contact' => 'preserved@example.com']);

    $this->actingAs($exco)
        ->post(route('matches.change-director', $match), ['user_id' => $newMd->id]);

    expect($match->fresh()->match_director_contact)->toBe('preserved@example.com');
});

// ── Search endpoint auth ──────────────────────────────────────────────

it('exposes the director-candidate search to exco only', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    User::factory()->create(['name' => 'Findable Person']);

    $this->actingAs($exco)
        ->getJson(route('matches.directors.search', ['q' => 'Findable']))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Findable Person']);
});

it('blocks non-exco users from the director search endpoint', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');

    $this->actingAs($md)
        ->getJson(route('matches.directors.search', ['q' => 'anything']))
        ->assertForbidden();
});
