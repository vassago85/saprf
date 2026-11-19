<?php

/**
 * Covers the two club-hosted series levels and the external registration
 * hand-off:
 *
 *   - Club only with a host club: only members of that club may enter.
 *   - Club only with no host club (legacy row migrated from `club`): entry
 *     stays open until an MD saves a club.
 *   - Club / Provincial: full-member scores land on the provincial standing
 *     the same way a plain provincial does; a Club only score does not.
 *   - External registration: the SAPRF entry is created, no PayFast
 *     payment is kicked off, and the response redirects to the host
 *     club's URL.
 *   - Flipping the series level off a club-hosted value clears the
 *     external URL so no stale link can route shooters off-site.
 */

use App\Models\Club;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\MembershipValidationService;
use App\Services\StandingsCalculationService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->hostClub = Club::firstOrCreate(
        ['slug' => 'host-club'],
        [
            'name' => 'Host Club',
            'abbreviation' => 'HC',
            'province_id' => $this->province->id,
            'is_active' => true,
        ],
    );

    $this->otherClub = Club::firstOrCreate(
        ['slug' => 'other-club'],
        [
            'name' => 'Other Club',
            'abbreviation' => 'OC',
            'province_id' => $this->province->id,
            'is_active' => true,
        ],
    );

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');
});

function clubHostedMatch(array $overrides = []): MatchEvent
{
    $match = MatchEvent::create(array_merge([
        'name' => 'Club Hosted Match',
        'match_type' => 'PR22',
        'series_level' => 'club_only',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => test()->province->id,
        'club_id' => test()->hostClub->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 300,
        'non_member_fee' => 450,
        'lapsed_member_fee' => 400,
        'club_entry_enabled' => false,
        'created_by' => test()->md->id,
    ], $overrides));
    $match->divisions()->syncWithoutDetaching([test()->open->id]);

    return $match->fresh();
}

function clubShooter(?Club $club, string $type = 'full'): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');
    $user->forceFill([
        'province_id' => test()->province->id,
        'club_id' => $club?->id,
        'date_of_birth' => '1990-01-01',
        'gender' => 'male',
        'ethnicity' => 'white',
        'country_of_residence' => 'ZA',
        'sa_id_number' => str_pad((string) $user->id, 13, '0', STR_PAD_LEFT),
        'sa_citizen' => true,
        'previously_disadvantaged' => false,
    ])->save();

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'CHM-'.$user->id,
        'membership_type' => $type,
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(2),
        'expiry_date' => Carbon::today()->addMonths(10),
    ]);

    return $user->fresh();
}

it('lets a member of the host club register for a club-only match', function () {
    $match = clubHostedMatch();
    $member = clubShooter($this->hostClub, 'full');

    $this->actingAs($member)
        ->post(route('events.register.store', $match), ['division_id' => $this->open->id])
        ->assertSessionHas('success');

    expect(MatchRegistration::where('user_id', $member->id)->count())->toBe(1);
});

it('refuses a member of another club on a club-only match', function () {
    $match = clubHostedMatch();
    $outsider = clubShooter($this->otherClub, 'full');

    $this->actingAs($outsider)
        ->post(route('events.register.store', $match), ['division_id' => $this->open->id])
        ->assertSessionHas('error', MembershipValidationService::CLUB_ONLY_HOST_CLUB_MESSAGE);

    expect(MatchRegistration::where('user_id', $outsider->id)->count())->toBe(0);
});

it('refuses a shooter with no club on a club-only match', function () {
    $match = clubHostedMatch();

    // Direct model check: a user with no club_id (no paid membership, so
    // EnsureProfileComplete does not redirect them) is rejected by the
    // host-club gate.
    $guest = User::factory()->create(['club_id' => null]);
    $guest->assignRole('member');

    expect($match->allowsShooter($guest))->toBeFalse()
        ->and(app(MembershipValidationService::class)->matchEntryDenialMessage($match, $guest))
        ->toBe(MembershipValidationService::CLUB_ONLY_HOST_CLUB_MESSAGE);
});

it('leaves a club-only match with no host club open to everyone (legacy row)', function () {
    // Legacy `club` rows migrated to `club_only` carry no club_id — the
    // host-club check is skipped until the MD saves one.
    $match = clubHostedMatch(['club_id' => null]);
    $outsider = clubShooter($this->otherClub, 'full');

    $this->actingAs($outsider)
        ->post(route('events.register.store', $match), ['division_id' => $this->open->id])
        ->assertSessionHas('success');

    expect(MatchRegistration::where('user_id', $outsider->id)->count())->toBe(1);
});

it('treats a club / provincial match like a provincial for membership gating', function () {
    $match = clubHostedMatch([
        'series_level' => 'club_provincial',
        'club_entry_enabled' => false,
    ]);
    $associate = clubShooter($this->hostClub, 'associate');

    $this->actingAs($associate)
        ->post(route('events.register.store', $match), ['division_id' => $this->open->id])
        ->assertSessionHas('error', MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MESSAGE);

    expect(MatchRegistration::where('user_id', $associate->id)->count())->toBe(0);
});

it('counts a club / provincial score on the provincial standing just like a provincial', function () {
    $matchDate = Carbon::today()->subDays(14);

    $clubProv = MatchEvent::create([
        'name' => 'Club Prov Rising',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'club_provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'club_id' => $this->hostClub->id,
        'match_date' => $matchDate,
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => $this->md->id,
    ]);

    $clubOnly = MatchEvent::create([
        'name' => 'Club Only Shoot',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'club_only',
        'season' => '2026',
        'province_id' => $this->province->id,
        'club_id' => $this->hostClub->id,
        'match_date' => $matchDate,
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
        'created_by' => $this->md->id,
    ]);

    $provincialShooter = clubShooter($this->hostClub, 'full');
    $clubOnlyShooter = clubShooter($this->hostClub, 'full');

    Score::create([
        'match_id' => $clubProv->id,
        'user_id' => $provincialShooter->id,
        'shooter_name' => $provincialShooter->name,
        'division_id' => $this->open->id,
        'raw_score' => 95,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $matchDate,
    ]);
    Score::create([
        'match_id' => $clubOnly->id,
        'user_id' => $clubOnlyShooter->id,
        'shooter_name' => $clubOnlyShooter->name,
        'division_id' => $this->open->id,
        'raw_score' => 90,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $matchDate,
    ]);

    app(StandingsCalculationService::class)->recalculateForMatch($clubProv);
    app(StandingsCalculationService::class)->recalculateForMatch($clubOnly);

    $ids = Standing::where('series', 'PR22')
        ->where('season', '2026')
        ->where('province_id', $this->province->id)
        ->pluck('user_id')
        ->unique()
        ->all();

    expect($ids)->toContain($provincialShooter->id)
        ->and($ids)->not->toContain($clubOnlyShooter->id);
});

it('saves an external match entry without PayFast and redirects to the host club URL', function () {
    $match = clubHostedMatch([
        'club_id' => $this->hostClub->id,
        'registration_method' => 'external',
        'external_registration_url' => 'https://example.test/host-club/register',
    ]);
    $member = clubShooter($this->hostClub, 'full');

    $this->actingAs($member)
        ->post(route('events.register.store', $match), ['division_id' => $this->open->id])
        ->assertRedirect('https://example.test/host-club/register');

    $registration = MatchRegistration::where('user_id', $member->id)->firstOrFail();

    expect($registration->payment_status)->toBe('pending')
        ->and((float) $registration->gateway_fee)->toBe(0.0);

    expect(Payment::where('payable_type', MatchRegistration::class)
        ->where('payable_id', $registration->id)
        ->exists())->toBeFalse();
});

it('clears the external url when the series level moves off a club-hosted value', function () {
    $match = clubHostedMatch([
        'registration_method' => 'external',
        'external_registration_url' => 'https://example.test/host-club/register',
    ]);

    $this->actingAs($this->md)
        ->patch(route('matches.update', $match), [
            'name' => $match->name,
            'match_type' => $match->match_type,
            'series_level' => 'national',
            'match_date' => $match->match_date->format('Y-m-d'),
            'active_member_fee' => $match->active_member_fee,
            'status' => $match->status,
        ])
        ->assertSessionDoesntHaveErrors();

    $fresh = $match->fresh();

    expect($fresh->series_level)->toBe('national')
        ->and($fresh->registration_method)->toBe('platform')
        ->and($fresh->external_registration_url)->toBeNull()
        ->and($fresh->club_id)->toBeNull();
});
