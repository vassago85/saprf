<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $this->province = Province::firstOrCreate(['name' => 'North West'], ['abbreviation' => 'NW']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $this->senior = Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 7, 'is_active' => true]);

    $this->national = MatchEvent::create([
        'name' => 'Centrefire NW 2-Day National',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'venue_name' => 'NW Klerksdorp',
        'match_date' => '2026-03-21',
        'match_end_date' => '2026-03-22',
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
    ]);
});

it('posts the first day of stages as a provincial and counts every shooter', function () {
    $johan = User::factory()->create(['name' => 'Johan Nel', 'province_id' => $this->province->id]);
    Membership::create([
        'user_id' => $johan->id,
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'start_date' => '2025-01-01',
        'expiry_date' => '2025-12-31',
    ]);
    $chris = User::factory()->create(['name' => 'Chris Pretorius', 'province_id' => $this->province->id]);
    Membership::create([
        'user_id' => $chris->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    Score::create([
        'match_id' => $this->national->id,
        'user_id' => $johan->id,
        'shooter_name' => 'Johan Nel',
        'division_id' => $this->senior->id,
        'raw_score' => 114,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $this->national->match_date,
    ]);
    $tinus = User::factory()->create([
        'name' => 'Tinus Cronje',
        'email' => 'tinus.cronje@import.saprf.local',
        'province_id' => $this->province->id,
    ]);
    $tinus->assignRole('member');
    Membership::create([
        'user_id' => $tinus->id,
        'saprf_number' => 'SAPRF-IMPORT-'.$tinus->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'waived',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    Score::create([
        'match_id' => $this->national->id,
        'user_id' => $chris->id,
        'shooter_name' => 'Chris Pretorius',
        'division_id' => $this->open->id,
        'raw_score' => 46,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $this->national->match_date,
    ]);

    fakeLegacyImpact();

    $this->artisan('scores:legacy-day1-provincial', [
        'match' => $this->national->id,
        '--impact' => 8369,
        '--day1-stages' => 2,
    ])->assertSuccessful();

    $sibling = MatchEvent::query()->where('source_national_match_id', $this->national->id)->first();
    $johanDay1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $johan->id)->first();
    $chrisDay1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $chris->id)->first();
    $tinus->refresh();
    $tinusDay1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $tinus->id)->first();

    expect($sibling->everyone_counts)->toBeTrue()
        ->and($sibling->series_level)->toBe('provincial')
        ->and((float) $johanDay1->raw_score)->toBe(15.0)
        ->and($johanDay1->division_id)->toBe($this->senior->id)
        ->and($johanDay1->status)->toBe('valid')
        ->and($chrisDay1->shooter_name)->toBe('Chris Pretorius')
        ->and($chrisDay1->status)->toBe('valid')
        ->and($tinusDay1->status)->toBe('non_member')
        ->and($tinus->membership)->toBeNull()
        ->and((float) Score::query()->where('match_id', $this->national->id)->where('user_id', $johan->id)->value('raw_score'))->toBe(114.0)
        ->and((int) $this->national->fresh()->impact_stage_days[101])->toBe(1)
        ->and((int) $this->national->fresh()->impact_stage_days[103])->toBe(2);

    $this->artisan('scores:legacy-day1-provincial', [
        'match' => $this->national->id,
        '--impact' => 8369,
        '--day1-stages' => 2,
    ])->assertSuccessful();

    expect(User::query()->where('name', 'Tinus Cronje')->count())->toBe(1)
        ->and(Score::query()->where('match_id', $sibling->id)->count())->toBe(3);
});

it('keeps the division the shooter entered, even when the national score says otherwise', function () {
    $limited = Division::firstOrCreate(['slug' => 'limited'], ['name' => 'Limited', 'display_order' => 3, 'is_active' => true]);
    $johan = User::factory()->create(['name' => 'Johan Nel', 'province_id' => $this->province->id]);
    Membership::create([
        'user_id' => $johan->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);
    Score::create([
        'match_id' => $this->national->id,
        'user_id' => $johan->id,
        'shooter_name' => 'Johan Nel',
        'division_id' => $this->senior->id,
        'raw_score' => 114,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $this->national->match_date,
    ]);
    MatchRegistration::create([
        'match_id' => $this->national->id,
        'user_id' => $johan->id,
        'division_id' => $limited->id,
        'shooter_name' => 'Johan Nel',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
    ]);

    fakeLegacyImpact();

    $this->artisan('scores:legacy-day1-provincial', [
        'match' => $this->national->id,
        '--impact' => 8369,
        '--day1-stages' => 2,
    ])->assertSuccessful();

    $sibling = MatchEvent::query()->where('source_national_match_id', $this->national->id)->first();
    $day1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $johan->id)->first();

    expect($day1->division_id)->toBe($limited->id)
        ->and($day1->status)->toBe('valid');
});

function fakeLegacyImpact(): void
{
    $stages = [
        ['id' => 101, 'name' => 'Stage 1'],
        ['id' => 102, 'name' => 'Stage 2'],
        ['id' => 103, 'name' => 'Stage 3'],
        ['id' => 104, 'name' => 'Stage 4'],
    ];
    $shooters = [
        ['id' => 1, 'name' => 'Johan Nel', 'divisionName' => 'Open'],
        ['id' => 2, 'name' => 'Christiaan Pretorius', 'divisionName' => 'Open'],
        ['id' => 3, 'name' => 'Tinus Cronje', 'divisionName' => 'Seniors'],
    ];
    $points = [
        101 => 1000,
        102 => 500,
        103 => 2000,
        104 => 2000,
    ];

    Http::swap(new Factory);
    Http::fake(function (Request $request) use ($stages, $shooters, $points) {
        $url = $request->url();
        if (str_contains($url, '/rest/website/getLiveScoresForShooter')) {
            $rows = [];
            foreach ($points as $stageId => $raw) {
                $rows[] = [
                    'id' => $stageId,
                    'name' => 'Stage '.($stageId - 100),
                    'points' => $raw,
                    'misses' => 0,
                    'shotsProgressCount' => 10,
                    'maxShots' => 10,
                    'completed' => true,
                ];
            }

            return Http::response(['errorCode' => 0, 'payload' => ['stages' => $rows]], 200);
        }

        if (str_contains($url, '/rest/website/getLiveScores')) {
            return Http::response([
                'errorCode' => 0,
                'payload' => [
                    'match' => ['id' => 8369, 'name' => 'Old Transvaal Takedown'],
                    'stages' => $stages,
                    'shooters' => $shooters,
                    'divisions' => [],
                    'categories' => [],
                ],
            ], 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });
}
