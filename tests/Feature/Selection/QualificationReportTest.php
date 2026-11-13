<?php

use App\Models\Club;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Models\SelectionParticipationSnapshot;
use App\Models\User;
use App\Services\Selection\PolicyImportService;
use App\Services\Selection\SelectionQualificationReport;

beforeEach(fn () => seedRoles());

function qualReportCycle(): SelectionCycle
{
    $cycle = SelectionCycle::create([
        'series' => 'PR22',
        'season' => '2027',
        'championship_name' => 'IPRF PR22 Team World Championships 2027',
        'qualifying_period_start' => '2026-01-01',
        'qualifying_period_end' => '2026-12-31',
        'declaration_deadline' => '2026-11-30 23:59:00',
        'results_freeze' => '2026-12-31',
        'status' => 'open',
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);

    app(PolicyImportService::class)->import(
        base_path('docs/selection/pr22/2027/policy.json'),
        $cycle,
    );

    return $cycle->fresh();
}

function qualReportShooter(SelectionCycle $cycle, string $name, ?Division $division = null, bool $declared = false, bool $fullParticipation = true): SelectionAthlete
{
    $province = Province::firstOrCreate(['abbreviation' => 'GP'], ['name' => 'Gauteng']);
    $club = Club::create([
        'name' => 'Report Club '.uniqid(),
        'slug' => 'report-club-'.uniqid(),
        'province_id' => $province->id,
        'saprf_recognised' => true,
    ]);

    $user = User::factory()->create([
        'name' => $name,
        'province_id' => $province->id,
        'club_id' => $club->id,
        'sa_citizen' => true,
        'country_of_residence' => 'ZA',
    ]);

    Membership::create([
        'user_id' => $user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2025-01-01',
        'expiry_date' => '2027-12-31',
    ]);

    $athlete = SelectionAthlete::create([
        'selection_cycle_id' => $cycle->id,
        'user_id' => $user->id,
        'claimed_division_id' => $division?->id,
        'state' => SelectionAthlete::STATE_ELIGIBLE,
    ]);

    if ($fullParticipation) {
        SelectionParticipationSnapshot::create([
            'selection_athlete_id' => $athlete->id,
            'provincial_1d_count' => 3,
            'national_2d_count' => 2,
            'international_2d_count' => 0,
            'out_of_home_province_2d_count' => 1,
            'sa_champs_shot' => true,
            'computed_at' => now(),
        ]);
    }

    if ($declared) {
        SelectionDeclaration::create([
            'selection_athlete_id' => $athlete->id,
            'submitted_at' => now(),
            'status' => SelectionDeclaration::STATUS_SUBMITTED,
            'form_data' => ['eligibility_to_compete_received' => true],
        ]);
    }

    return $athlete->fresh('user');
}

function qualReportScore(SelectionCycle $cycle, User $user, string $level, float $normalized, string $label): void
{
    $match = MatchEvent::create([
        'name' => $label.' '.uniqid(),
        'slug' => 'qual-'.uniqid(),
        'match_type' => 'PR22',
        'series' => $cycle->series,
        'season' => '2026',
        'series_level' => $level,
        'match_date' => '2026-06-15',
        'status' => 'completed',
        'published' => true,
        'created_by' => $user->id,
    ]);

    Score::create([
        'match_id' => $match->id,
        'shooter_name' => $user->name,
        'user_id' => $user->id,
        'raw_score' => $normalized,
        'normalized_score' => $normalized,
        'status' => 'valid',
        'counts_for_season' => true,
        'match_date' => '2026-06-15',
    ]);
}

function qualReportPack(SelectionCycle $cycle, User $user, float $normalized): void
{
    foreach (['provincial', 'provincial', 'provincial'] as $i => $level) {
        qualReportScore($cycle, $user, $level, $normalized, 'Provincial '.($i + 1));
    }
    foreach (['national', 'national'] as $i => $level) {
        qualReportScore($cycle, $user, $level, $normalized, 'National '.($i + 1));
    }
    qualReportScore($cycle, $user, 'final', $normalized, 'SA Championship');
}

it('ranks qualified shooters by division and keeps declaration-only shooters in the list', function () {
    $cycle = qualReportCycle();
    $factory = Division::create(['slug' => 'factory-qual-'.uniqid(), 'name' => 'Factory', 'is_active' => true, 'display_order' => 1]);
    $open = Division::create(['slug' => 'open-qual-'.uniqid(), 'name' => 'Open', 'is_active' => true, 'display_order' => 2]);

    $leader = qualReportShooter($cycle, 'Amy Leader', $open, declared: true);
    qualReportPack($cycle, $leader->user, 100);

    $waiting = qualReportShooter($cycle, 'Zoe Waiting', $open, declared: false);
    qualReportPack($cycle, $waiting->user, 50);

    $short = qualReportShooter($cycle, 'Chris Short', $open, declared: false, fullParticipation: false);

    $factoryLeader = qualReportShooter($cycle, 'Ben Factory', $factory, declared: true);
    qualReportPack($cycle, $factoryLeader->user, 80);

    $report = app(SelectionQualificationReport::class)->build($cycle);

    expect($report['summary']['qualified'])->toBe(2)
        ->and($report['summary']['declaration_outstanding'])->toBe(1)
        ->and(collect($report['divisions'])->pluck('name')->all())->toBe(['Factory', 'Open']);

    $factoryNames = collect($report['divisions'][0]['shooters'])->pluck('name')->all();
    $openRows = collect($report['divisions'][1]['shooters']);

    expect($factoryNames)->toBe(['Ben Factory'])
        ->and($openRows->pluck('name')->all())->toBe(['Amy Leader', 'Zoe Waiting'])
        ->and($openRows[0]['rank'])->toBe(1)
        ->and($openRows[0]['status'])->toBe('qualified')
        ->and($openRows[0]['colour'])->toBe('protea')
        ->and($openRows[0]['division_pct'])->toBe(100.0)
        ->and($openRows[1]['status'])->toBe('declaration_outstanding')
        ->and($openRows[1]['colour'])->toBe('federation')
        ->and($openRows[0]['components'][2]['matches'])->not->toBeEmpty()
        ->and(collect($report['divisions'])->pluck('shooters')->flatten(1)->pluck('name'))->not->toContain('Chris Short');

    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $response = $this->actingAs($owner)->get(route('selection.cycles.qualification-report', $cycle));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('refuses the selection report to a member', function () {
    $cycle = qualReportCycle();
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('selection.cycles.qualification-report', $cycle))
        ->assertForbidden();
});
