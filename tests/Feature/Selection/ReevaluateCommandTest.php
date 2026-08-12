<?php

use App\Models\Club;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\Selection\PolicyImportService;

beforeEach(fn () => seedRoles());

it('populates snapshots + rule evaluations + state for every athlete under v1.4', function () {
    $prov = Province::create(['name' => 'GP', 'abbreviation' => 'GP']);
    $club = Club::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'province_id' => $prov->id, 'saprf_recognised' => true]);

    $cycle = SelectionCycle::create([
        'series' => 'PRS', 'season' => '2026', 'championship_name' => 'IPRF WCH 2026 (Centrefire)',
        'qualifying_period_start' => '2024-11-15', 'qualifying_period_end' => '2025-11-30',
        'declaration_deadline' => '2025-09-30 23:59:00', 'results_freeze' => '2026-03-01',
        'status' => 'open',
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);
    app(PolicyImportService::class)->import(base_path('docs/selection/prs/2026/policy.json'), $cycle);

    $users = collect(range(1, 2))->map(function () use ($prov, $club) {
        $u = User::factory()->create([
            'province_id' => $prov->id, 'club_id' => $club->id,
            'sa_citizen' => true, 'country_of_residence' => 'ZA',
        ]);
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => 'M'.$u->id, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => '2024-01-01', 'expiry_date' => '2026-12-31',
        ]);

        return $u;
    });

    foreach ($users as $u) {
        SelectionAthlete::create(['selection_cycle_id' => $cycle->id, 'user_id' => $u->id, 'state' => 'registered']);

        $m = MatchEvent::create([
            'name' => 'SA Champs', 'match_type' => 'PRS', 'series' => 'PRS', 'season' => '2025',
            'series_level' => 'final', 'province_id' => $prov->id, 'match_date' => '2025-11-01',
            'status' => 'completed', 'created_by' => $u->id, 'active_member_fee' => 500, 'published' => true,
        ]);
        Score::create([
            'match_id' => $m->id, 'user_id' => $u->id, 'shooter_name' => $u->name,
            'raw_score' => 80, 'status' => 'valid', 'match_date' => '2025-11-01', 'counts_for_season' => true,
        ]);
    }

    $this->artisan('selection:reevaluate', ['--cycle' => (string) $cycle->id])
        ->assertSuccessful();

    expect(SelectionParticipationSnapshot::count())->toBe(2);
    expect(SelectionRuleEvaluation::where('rule_id', 'PART-01')->count())->toBe(2);
    expect(SelectionRuleEvaluation::where('rule_id', 'PART-06')->where('outcome', SelectionRuleEvaluation::OUTCOME_BLOCKED)->count())->toBe(2);
    SelectionAthlete::all()->each(fn ($a) => expect($a->last_evaluated_at)->not->toBeNull());
});
