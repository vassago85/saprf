<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
    Notification::fake();

    $this->province = Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $this->senior = Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 7, 'is_active' => true]);

    $this->national = MatchEvent::create([
        'name' => 'Centrefire WC 2-Day National',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::parse('2026-10-24')->toDateString(),
        'match_end_date' => Carbon::parse('2026-10-25')->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 150,
        'lapsed_member_fee' => 120,
    ]);

    $this->provincial = MatchEvent::create([
        'name' => 'Centrefire WC 2-Day National — Provincial (Day 1)',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::parse('2026-10-24')->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'source_national_match_id' => $this->national->id,
        'active_member_fee' => 0,
        'non_member_fee' => 0,
        'lapsed_member_fee' => 0,
    ]);
});

function day1PaidMember(string $name): User
{
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    Membership::create([
        'user_id' => $user->id,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    return $user;
}

function day1PaidEntry(MatchEvent $match, User $user, Division $division): MatchRegistration
{
    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'division_id' => $division->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 100,
        'saprf_fee' => 50,
        'platform_fee' => 20,
        'gateway_fee' => 0,
        'md_net_amount' => 30,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);
}

it('imports a day-1 provincial from the national entry, not Impact\'s division', function () {
    $etienne = day1PaidMember('Etienne De Waal');
    day1PaidEntry($this->national, $etienne, $this->senior);

    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_contains($url, '/rest/website/getLiveScoresForShooter')) {
            return Http::response([
                'errorCode' => 0,
                'payload' => ['stages' => []],
            ], 200);
        }
        if (str_contains($url, '/rest/website/getLiveScores')) {
            return Http::response([
                'errorCode' => 0,
                'payload' => [
                    'match' => ['id' => 11086, 'name' => 'Darling', 'matchEnded' => true],
                    'stages' => [['id' => 1, 'name' => 'Stage 1']],
                    'shooters' => [[
                        'id' => 9,
                        'name' => 'Etienne De Waal',
                        'rank' => 9,
                        'points' => 6200,
                        'divisionName' => 'Open',
                    ]],
                ],
            ], 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });

    $this->actingAs($this->admin)
        ->post(route('score-imports.impact.store'), [
            'match_id' => $this->provincial->id,
            'impact_ref' => '11086',
            'replace_existing' => 1,
            'links' => [],
        ])
        ->assertRedirect();

    $score = Score::query()->where('match_id', $this->provincial->id)->where('user_id', $etienne->id)->first();

    expect($score)->not->toBeNull()
        ->and($score->division_id)->toBe($this->senior->id)
        ->and((float) $score->raw_score)->toBe(62.0)
        ->and(MatchRegistration::query()->where('match_id', $this->provincial->id)->count())->toBe(0);
});

it('lists the national entry on the day-1 link picker', function () {
    $leriche = day1PaidMember('LeRiche Coetzer Snr');
    day1PaidEntry($this->national, $leriche, $this->senior);

    $stub = User::factory()->create(['name' => 'Le Riche Coetzer Snr']);
    Score::create([
        'match_id' => $this->provincial->id,
        'user_id' => $stub->id,
        'shooter_name' => 'Le Riche Coetzer Snr',
        'division_id' => $this->open->id,
        'raw_score' => 57,
        'day1_raw_score' => 57,
        'status' => 'invalid',
        'is_member' => false,
        'match_date' => $this->provincial->match_date,
        'raw_meta' => ['impact' => ['needs_member_link' => true, 'impact_name' => 'Le Riche Coetzer Snr']],
    ]);

    $this->actingAs($this->admin)
        ->get(route('matches.scores', $this->provincial))
        ->assertOk()
        ->assertSee('LeRiche Coetzer Snr')
        ->assertSee('Senior')
        ->assertSee('does not create a second payment');
});

it('aligns stored day-1 scores to the national entry and links an exact scoreboard name', function () {
    $etienne = day1PaidMember('Etienne De Waal');
    day1PaidEntry($this->national, $etienne, $this->senior);
    Score::create([
        'match_id' => $this->provincial->id,
        'user_id' => $etienne->id,
        'shooter_name' => 'Etienne De Waal',
        'division_id' => $this->open->id,
        'raw_score' => 62,
        'day1_raw_score' => 62,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $this->provincial->match_date,
    ]);

    $leriche = day1PaidMember('LeRiche Coetzer Snr');
    day1PaidEntry($this->national, $leriche, $this->senior);
    $stub = User::factory()->create(['name' => 'Le Riche Coetzer Snr']);
    Score::create([
        'match_id' => $this->provincial->id,
        'user_id' => $stub->id,
        'shooter_name' => 'Le Riche Coetzer Snr',
        'division_id' => $this->open->id,
        'raw_score' => 57,
        'day1_raw_score' => 57,
        'status' => 'invalid',
        'is_member' => false,
        'match_date' => $this->provincial->match_date,
        'raw_meta' => ['impact' => ['needs_member_link' => true, 'impact_name' => 'Le Riche Coetzer Snr']],
    ]);

    $this->artisan('scores:align-day1-provincial', ['match' => $this->provincial->id])
        ->assertSuccessful();

    $etienneScore = Score::query()->where('match_id', $this->provincial->id)->where('shooter_name', 'Etienne De Waal')->first();
    $lericheScore = Score::query()->where('match_id', $this->provincial->id)->where('shooter_name', 'Le Riche Coetzer Snr')->first();

    expect($etienneScore->division_id)->toBe($this->senior->id)
        ->and($lericheScore->user_id)->toBe($leriche->id)
        ->and($lericheScore->division_id)->toBe($this->senior->id)
        ->and($lericheScore->raw_meta['impact']['needs_member_link'])->toBeFalse();
});

it('reads a csv imported onto the day-1 provincial against the national entry', function () {
    $etienne = day1PaidMember('Etienne De Waal');
    day1PaidEntry($this->national, $etienne, $this->senior);

    $header = "Place,Last,First,Class,Div,Category,Member Number,Time,Impacts,Match %\n1,Waal,Etienne De,,Open,,,100,62,80%\n";
    $csv = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('scores.csv', $header);

    $this->actingAs($this->admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('score-imports.csv.preview'), [
            'match_id' => $this->provincial->id,
            'file' => $csv(),
        ])
        ->assertOk()
        ->assertJsonPath('preview.summary.exact', 1)
        ->assertJsonPath('preview.shooters.0.division_slug', 'senior')
        ->assertJsonPath('preview.shooters.0.division_source', 'registration');

    $this->actingAs($this->admin)
        ->post(route('score-imports.store'), [
            'match_id' => $this->provincial->id,
            'source_type' => 'csv',
            'file' => $csv(),
            'assignment_review' => 1,
            'links' => [],
        ])
        ->assertRedirect();

    $score = Score::query()->where('match_id', $this->provincial->id)->where('user_id', $etienne->id)->first();

    expect($score)->not->toBeNull()
        ->and($score->division_id)->toBe($this->senior->id)
        ->and(MatchRegistration::query()->where('match_id', $this->provincial->id)->count())->toBe(0);
});
