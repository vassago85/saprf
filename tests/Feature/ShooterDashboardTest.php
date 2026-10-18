<?php

use App\Livewire\Dashboard\ShooterDashboard;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Support\Dashboard\Shooter\DisciplineScope;
use App\Support\Dashboard\Shooter\NextMatchesBuilder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();

    $this->gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->wc = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    $this->creator = User::factory()->create();
});

function dashShooter(?Province $province = null): User
{
    $user = User::factory()->create([
        'province_id' => ($province ?? Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']))->id,
        'name' => 'Test Shooter',
    ]);
    $user->assignRole('member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => '29',
        'status' => 'active',
        'payment_status' => 'paid',
        'membership_type' => 'paid',
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);

    return $user->fresh(['membership', 'province']);
}

function dashMatch(array $attrs): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Test Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => (string) Carbon::now()->year,
        'match_date' => Carbon::today()->addDays(10),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Director',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => User::factory()->create()->id,
    ], $attrs));
}

function dashScore(User $user, MatchEvent $match, array $attrs = []): Score
{
    return Score::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'match_date' => $match->match_date,
        'raw_score' => 50,
        'placement' => 5,
        'status' => 'valid',
        'counts_for_season' => true,
    ], $attrs));
}

it('shows only PRS chrome for a PRS-only member with no discipline toggles', function () {
    $user = dashShooter($this->gp);
    $past = dashMatch([
        'name' => 'PRS Past',
        'series' => 'PRS',
        'match_date' => Carbon::today()->subMonth(),
        'status' => 'completed',
        'province_id' => $this->gp->id,
    ]);
    dashScore($user, $past);

    dashMatch([
        'name' => 'Upcoming PRS',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(5),
        'province_id' => $this->gp->id,
    ]);
    dashMatch([
        'name' => 'Upcoming PR22',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'match_date' => Carbon::today()->addDays(6),
        'province_id' => $this->gp->id,
    ]);

    Livewire::actingAs($user)
        ->test(ShooterDashboard::class)
        ->assertSee('PRS only, from the disciplines you shoot')
        ->assertSee('Upcoming PRS')
        ->assertDontSee('Upcoming PR22')
        ->assertDontSeeHtml('wire:click="setNextFilter')
        ->assertSee('Centrefire')
        ->assertDontSee('Rimfire');
});

it('shows only PR22 chrome for a PR22-only member', function () {
    $user = dashShooter($this->gp);
    $past = dashMatch([
        'name' => 'PR22 Past',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'match_date' => Carbon::today()->subMonth(),
        'status' => 'completed',
        'province_id' => $this->gp->id,
    ]);
    dashScore($user, $past);

    Livewire::actingAs($user)
        ->test(ShooterDashboard::class)
        ->assertSee('PR22 only, from the disciplines you shoot')
        ->assertSee('Rimfire')
        ->assertDontSee('Centrefire')
        ->assertDontSeeHtml('wire:click="setResultsFilter');
});

it('shows both cards and toggles for a dual-discipline member and filters next matches', function () {
    $user = dashShooter($this->gp);
    $prsPast = dashMatch([
        'name' => 'PRS Past Dual',
        'series' => 'PRS',
        'match_date' => Carbon::today()->subMonths(2),
        'status' => 'completed',
        'province_id' => $this->gp->id,
    ]);
    $pr22Past = dashMatch([
        'name' => 'PR22 Past Dual',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'match_date' => Carbon::today()->subMonth(),
        'status' => 'completed',
        'province_id' => $this->gp->id,
    ]);
    dashScore($user, $prsPast);
    dashScore($user, $pr22Past);

    dashMatch([
        'name' => 'Dual Upcoming PRS',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(4),
        'province_id' => $this->gp->id,
    ]);
    dashMatch([
        'name' => 'Dual Upcoming PR22',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'match_date' => Carbon::today()->addDays(5),
        'province_id' => $this->gp->id,
    ]);

    Livewire::actingAs($user)
        ->test(ShooterDashboard::class)
        ->assertSee('PRS and PR22, from the disciplines you shoot')
        ->assertSee('Centrefire')
        ->assertSee('Rimfire')
        ->assertSee('Dual Upcoming PRS')
        ->assertSee('Dual Upcoming PR22')
        ->call('setNextFilter', 'PR22')
        ->assertSee('Dual Upcoming PR22')
        ->assertDontSee('Dual Upcoming PRS');
});

it('appends a fourth row when the member is registered for the next three matches', function () {
    $user = dashShooter($this->gp);
    $past = dashMatch([
        'name' => 'Anchor score',
        'match_date' => Carbon::today()->subMonth(),
        'status' => 'completed',
        'province_id' => $this->gp->id,
    ]);
    dashScore($user, $past);

    $matches = collect([
        dashMatch(['name' => 'Reg A', 'match_date' => Carbon::today()->addDays(2), 'province_id' => $this->gp->id]),
        dashMatch(['name' => 'Reg B', 'match_date' => Carbon::today()->addDays(3), 'province_id' => $this->gp->id]),
        dashMatch(['name' => 'Reg C', 'match_date' => Carbon::today()->addDays(4), 'province_id' => $this->gp->id]),
        dashMatch(['name' => 'Not Entered Yet', 'match_date' => Carbon::today()->addDays(5), 'province_id' => $this->gp->id]),
    ]);

    foreach ($matches->take(3) as $match) {
        MatchRegistration::create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'membership_fee_category' => 'active_member',
            'fee_amount' => 250,
            'registration_status' => 'confirmed',
            'payment_status' => 'paid',
            'registered_at' => now(),
        ]);
    }

    $rows = app(NextMatchesBuilder::class)->build($user, ['PRS'], 'all');

    expect($rows)->toHaveCount(4)
        ->and(collect($rows)->pluck('name')->all())->toContain('Not Entered Yet');
});

it('shows OOP attention and Counts for finals on an out-of-province PR22 national', function () {
    $user = dashShooter($this->gp);

    QualificationRule::create([
        'series' => 'PR22',
        'season' => (string) Carbon::now()->year,
        'scoring_mode' => 'weighted_pools',
        'min_out_of_province_matches' => 1,
        'provincial_pool_best_of' => 3,
        'national_pool_best_of' => 2,
        'champs_pool_best_of' => 1,
        'created_by' => $this->creator->id,
    ]);

    $past = dashMatch([
        'name' => 'PR22 Prov Past',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'match_date' => Carbon::today()->subMonth(),
        'status' => 'completed',
        'province_id' => $this->gp->id,
    ]);
    dashScore($user, $past);

    dashMatch([
        'name' => 'PR22 OOP National',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'series_level' => 'national',
        'match_date' => Carbon::today()->addDays(12),
        'province_id' => $this->wc->id,
        'registration_open_date' => Carbon::today()->addDays(3),
        'status' => 'open',
        'published' => true,
    ]);

    Livewire::actingAs($user)
        ->test(ShooterDashboard::class)
        ->assertSee('PR22 finals: out-of-province national needed')
        ->assertSee('0 of 1 shot')
        ->assertSee('Counts for finals')
        ->assertSee('PR22 OOP National');
});

it('shows all-discipline upcoming matches when the member has no scores', function () {
    $user = dashShooter($this->gp);

    dashMatch([
        'name' => 'Open PRS NoScores',
        'series' => 'PRS',
        'match_date' => Carbon::today()->addDays(7),
        'province_id' => $this->gp->id,
    ]);
    dashMatch([
        'name' => 'Open PR22 NoScores',
        'series' => 'PR22',
        'match_type' => 'PR22',
        'match_date' => Carbon::today()->addDays(8),
        'province_id' => $this->gp->id,
    ]);

    expect(app(DisciplineScope::class)->for($user))->toBe(['PRS', 'PR22']);

    Livewire::actingAs($user)
        ->test(ShooterDashboard::class)
        ->assertSee('PRS and PR22, from the disciplines you shoot')
        ->assertSee('Open PRS NoScores')
        ->assertSee('Open PR22 NoScores');
});
