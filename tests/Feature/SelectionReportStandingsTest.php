<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // QualificationService uses YEAR() in a raw query meant for MySQL.
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::connection()->getPdo()->sqliteCreateFunction('YEAR', function ($date) {
            return $date ? date('Y', strtotime($date)) : null;
        }, 1);
    }

    seedRoles();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->gp = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $wc = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);

    QualificationRule::create([
        'series' => 'PRS',
        'season' => '2026',
        'min_out_of_province_matches' => 1,
        'created_by' => $this->admin->id,
    ]);

    $match = MatchEvent::create([
        'name' => 'WC National',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $wc->id,
        'match_date' => now()->subMonth(),
        'status' => 'closed',
        'active_member_fee' => 250,
        'non_member_fee' => 500,
        'lapsed_member_fee' => 375,
        'created_by' => $this->admin->id,
    ]);

    $this->shooter = User::factory()->create(['province_id' => $this->gp->id]);

    Score::create([
        'match_id' => $match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => $this->shooter->name,
        'raw_score' => 80,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $match->match_date,
    ]);

    $this->open = Division::create(['slug' => 'open-sel', 'name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $this->factory = Division::create(['slug' => 'factory-sel', 'name' => 'Factory', 'display_order' => 2, 'is_active' => true]);
});

function selectionStanding(User $user, ?Division $division, int $rank, float $points): void
{
    Standing::create([
        'user_id' => $user->id,
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => null,
        'division_id' => $division?->id,
        'points' => $points,
        'rank' => $rank,
    ]);
}

it('shows rank and points from the best division standing when no overall standing exists', function () {
    selectionStanding($this->shooter, $this->open, 7, 410.5);
    selectionStanding($this->shooter, $this->factory, 3, 380.25);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.selection', ['series' => 'PRS', 'season' => '2026']))
        ->assertOk();

    $row = $response->viewData('rows')->first();

    expect($row['rank'])->toBe(3)
        ->and((float) $row['points'])->toBe(380.25)
        ->and($row['rank_division'])->toBe('Factory')
        ->and($row['qualified'])->toBeTrue()
        ->and($row['progress'])->toBe('1 of 1 out-of-province');
});

it('prefers the overall standing over division standings', function () {
    selectionStanding($this->shooter, null, 12, 500.0);
    selectionStanding($this->shooter, $this->factory, 1, 700.0);

    $row = $this->actingAs($this->admin)
        ->get(route('reports.selection', ['series' => 'PRS', 'season' => '2026']))
        ->assertOk()
        ->viewData('rows')
        ->first();

    expect($row['rank'])->toBe(12)
        ->and((float) $row['points'])->toBe(500.0)
        ->and($row['rank_division'])->toBeNull();
});

it('leaves rank and points empty when the shooter has no standings', function () {
    $row = $this->actingAs($this->admin)
        ->get(route('reports.selection', ['series' => 'PRS', 'season' => '2026']))
        ->assertOk()
        ->viewData('rows')
        ->first();

    expect($row['rank'])->toBeNull()
        ->and($row['points'])->toBeNull();
});

it('exports division-derived rank and points in the CSV', function () {
    selectionStanding($this->shooter, $this->open, 2, 123.4570);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.selection.export', ['series' => 'PRS', 'season' => '2026']))
        ->assertOk();

    $lines = explode("\n", trim($response->streamedContent()));
    $cells = str_getcsv($lines[1]);

    expect($cells[0])->toBe('2')
        ->and($cells[8])->toBe('123.4570');
});
