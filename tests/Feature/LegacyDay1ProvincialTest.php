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

it('posts stages from a score sheet and keeps the entered division', function () {
    $limited = Division::firstOrCreate(['slug' => 'limited'], ['name' => 'Limited', 'display_order' => 3, 'is_active' => true]);
    $dirk = User::factory()->create(['name' => 'Dirk Van Staden', 'province_id' => $this->province->id]);
    Membership::create([
        'user_id' => $dirk->id,
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'start_date' => '2025-01-01',
        'expiry_date' => '2025-12-31',
    ]);
    $derick = User::factory()->create(['name' => 'Diederick Koekemoer', 'province_id' => $this->province->id]);
    Membership::create([
        'user_id' => $derick->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);
    $kim = User::factory()->create(['name' => 'Kim-Leigh Ferreira', 'province_id' => $this->province->id]);

    Score::create([
        'match_id' => $this->national->id,
        'user_id' => $dirk->id,
        'shooter_name' => 'Dirk Van Staden',
        'division_id' => $this->open->id,
        'raw_score' => 112,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $this->national->match_date,
    ]);
    Score::create([
        'match_id' => $this->national->id,
        'user_id' => $derick->id,
        'shooter_name' => 'Diederick Koekemoer',
        'division_id' => $this->senior->id,
        'raw_score' => 34,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $this->national->match_date,
    ]);
    Score::create([
        'match_id' => $this->national->id,
        'user_id' => $kim->id,
        'shooter_name' => 'Kim-Leigh Ferreira',
        'division_id' => $this->open->id,
        'raw_score' => 68,
        'status' => 'valid',
        'is_member' => true,
        'counts_for_season' => true,
        'match_date' => $this->national->match_date,
    ]);
    MatchRegistration::create([
        'match_id' => $this->national->id,
        'user_id' => $derick->id,
        'division_id' => $limited->id,
        'shooter_name' => 'Diederick Koekemoer',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
    ]);

    $path = storage_path('framework/wc-day1-stages.csv');
    file_put_contents($path, implode("\n", [
        'Shooter,Stage 1,Stage 2,Stage 3,Totaal',
        'Dirk v Staden,7,9,8,112',
        'Derick Koekemoer,3,7,7,34',
        'Kim leigh Ferreira,8,7,4,68',
    ]));

    $this->artisan('scores:legacy-day1-provincial', [
        'match' => $this->national->id,
        '--csv' => $path,
        '--day1-stages' => 2,
    ])->assertSuccessful();

    $sibling = MatchEvent::query()->where('source_national_match_id', $this->national->id)->first();
    $dirkDay1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $dirk->id)->first();
    $derickDay1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $derick->id)->first();
    $kimDay1 = Score::query()->where('match_id', $sibling->id)->where('user_id', $kim->id)->first();

    expect((float) $dirkDay1->raw_score)->toBe(16.0)
        ->and($dirkDay1->status)->toBe('valid')
        ->and($dirkDay1->division_id)->toBe($this->open->id)
        ->and((float) $derickDay1->raw_score)->toBe(10.0)
        ->and($derickDay1->division_id)->toBe($limited->id)
        ->and($kimDay1->status)->toBe('non_member')
        ->and((float) $kimDay1->raw_score)->toBe(15.0)
        ->and((float) Score::query()->where('match_id', $this->national->id)->where('user_id', $dirk->id)->value('raw_score'))->toBe(112.0);
});

it('links shortened stage-sheet names to the national scoreboard', function () {
    $pairs = [
        'Dirk v Staden' => 'Dirk Van Staden',
        'Schalk vd Merwe' => 'Schalk van der Merwe',
        'Russel Ferreira' => 'Russell Ferreira',
        'Le Riche Coetzer' => 'Le Riche Coetzer Snr',
        'Dennis vd Merwe' => 'Dennis Van der merwe',
        'Hanno v Niekerk' => 'Hanno Van Niekerk',
        'Aj Deysel' => 'AJ Deysel',
        'Tony v Heerden' => 'Tony Van Heerden',
        'Derick Koekemoer' => 'Diederick Koekemoer',
        'Nick Harlow' => 'Nicholas Harlow',
        'TW v Zyl' => 'TW Van Zyl',
        'Patrick capes' => 'Patrick Capes',
        'Kim leigh Ferreira' => 'Kim-Leigh Ferreira',
    ];

    $lines = ['Shooter,Stage 1,Stage 2'];
    $expected = [];
    $hits = 1;
    foreach ($pairs as $sheet => $board) {
        $user = User::factory()->create(['name' => $board, 'province_id' => $this->province->id]);
        Membership::create([
            'user_id' => $user->id,
            'membership_type' => 'paid',
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => '2026-01-01',
            'expiry_date' => '2026-12-31',
        ]);
        Score::create([
            'match_id' => $this->national->id,
            'user_id' => $user->id,
            'shooter_name' => $board,
            'division_id' => $this->open->id,
            'raw_score' => 10,
            'status' => 'valid',
            'is_member' => true,
            'counts_for_season' => true,
            'match_date' => $this->national->match_date,
        ]);
        $lines[] = $sheet.','.$hits.',1';
        $expected[$user->id] = $hits + 1;
        $hits++;
    }

    $path = storage_path('framework/wc-day1-names.csv');
    file_put_contents($path, implode("\n", $lines));

    $this->artisan('scores:legacy-day1-provincial', [
        'match' => $this->national->id,
        '--csv' => $path,
        '--day1-stages' => 2,
    ])->assertSuccessful();

    $sibling = MatchEvent::query()->where('source_national_match_id', $this->national->id)->first();
    expect(Score::query()->where('match_id', $sibling->id)->count())->toBe(count($pairs));
    foreach ($expected as $userId => $sum) {
        $score = Score::query()->where('match_id', $sibling->id)->where('user_id', $userId)->first();
        expect($score)->not->toBeNull()
            ->and((float) $score->raw_score)->toBe((float) $sum)
            ->and($score->division_id)->toBe($this->open->id)
            ->and($score->status)->toBe('valid');
    }
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
