<?php

use App\Models\Club;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use App\Services\MembershipValidationService;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->division = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'is_active' => true, 'display_order' => 1],
    );

    $this->md = User::factory()->create(['email_verified_at' => now()]);
    $this->md->assignRole('match_director');
});

function provincialGateMatch(array $overrides = []): MatchEvent
{
    $match = MatchEvent::create(array_merge([
        'name' => 'Provincial Gate',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => test()->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 400,
        'non_member_fee' => 600,
        'lapsed_member_fee' => 500,
        'club_entry_enabled' => false,
        'created_by' => test()->md->id,
    ], $overrides));
    $match->divisions()->syncWithoutDetaching([test()->division->id]);

    return $match;
}

function gateShooter(string $type, array $membership = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    if ($type !== 'none') {
        $club = Club::firstOrCreate(
            ['slug' => 'gate-club'],
            [
                'name' => 'Gate Club',
                'abbreviation' => 'GC',
                'province_id' => test()->province->id,
                'is_active' => true,
            ],
        );
        $user->forceFill([
            'province_id' => test()->province->id,
            'club_id' => $club->id,
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'ethnicity' => 'white',
            'country_of_residence' => 'ZA',
            'sa_id_number' => str_pad((string) $user->id, 13, '0', STR_PAD_LEFT),
            'sa_citizen' => true,
            'previously_disadvantaged' => false,
        ])->save();

        Membership::create(array_merge([
            'user_id' => $user->id,
            'saprf_number' => 'GATE-'.$user->id,
            'membership_type' => $type,
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => Carbon::today()->subMonths(2),
            'expiry_date' => Carbon::today()->addMonths(10),
        ], $membership));
    }

    return $user->fresh();
}

it('shows the allow-club-members switch off on a provincial', function () {
    $match = provincialGateMatch();

    $this->actingAs($this->md)
        ->get(route('matches.settings', $match))
        ->assertOk()
        ->assertSee('Allow club members')
        ->assertDontSee('Allow non-members to enter as club shooters');
});

it('blocks a club member from registering for a provincial by default', function () {
    $match = provincialGateMatch();
    $associate = gateShooter('associate');

    $this->actingAs($associate)
        ->post(route('events.register.store', $match), ['division_id' => $this->division->id])
        ->assertSessionHas('error', MembershipValidationService::PROVINCIAL_MEMBERS_ONLY_MESSAGE);

    expect(MatchRegistration::where('user_id', $associate->id)->count())->toBe(0);
});

it('blocks a shooter with no membership from a provincial by default', function () {
    $match = provincialGateMatch();
    $guest = gateShooter('none');

    $this->actingAs($guest)
        ->get(route('events.register', $match))
        ->assertRedirect(route('events.show', $match))
        ->assertSessionHas('error');

    expect(MatchRegistration::where('user_id', $guest->id)->count())->toBe(0);
});

it('lets a full member register for a members-only provincial', function () {
    $match = provincialGateMatch();
    $member = gateShooter('full');

    $this->actingAs($member)
        ->post(route('events.register.store', $match), ['division_id' => $this->division->id])
        ->assertSessionHas('success');

    expect(MatchRegistration::where('user_id', $member->id)->value('membership_fee_category'))
        ->toBe('active_member');
});

it('lets a club member enter a provincial once the match director allows it', function () {
    $match = provincialGateMatch([
        'club_entry_enabled' => true,
        'club_entry_fee' => 180,
    ]);
    $associate = gateShooter('none');

    $this->actingAs($associate)
        ->post(route('events.register.store', $match), ['division_id' => $this->division->id])
        ->assertSessionHas('success');

    expect(MatchRegistration::where('user_id', $associate->id)->value('membership_fee_category'))
        ->toBe('club_entry');
});

it('still lets a non-member enter a club match when the provincial gate is off', function () {
    $match = provincialGateMatch([
        'name' => 'Club Gate',
        'series_level' => 'club',
        'club_entry_enabled' => false,
    ]);
    $guest = gateShooter('none');

    $this->actingAs($guest)
        ->post(route('events.register.store', $match), ['division_id' => $this->division->id])
        ->assertSessionHas('success');

    expect(MatchRegistration::where('user_id', $guest->id)->count())->toBe(1);
});

it('refuses an admin add of a club member on a members-only provincial', function () {
    $match = provincialGateMatch();
    $associate = gateShooter('club');

    $this->actingAs($this->md)
        ->post(route('matches.entries.store', $match), [
            'user_id' => $associate->id,
            'division_id' => $this->division->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect(MatchRegistration::where('user_id', $associate->id)->count())->toBe(0);
});
