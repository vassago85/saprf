<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ShooterLog;
use App\Models\Standing;
use App\Models\User;
use App\Services\FederationColoursReport;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->open = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true]
    );

    $this->match = day1National($this->admin, $this->province);
});

it('keeps a two-day championship as a final and makes day 1 a provincial', function () {
    $final = day1National($this->admin, $this->province, [
        'name' => 'Centrefire GP 2-Day National Championship',
        'series_level' => 'final',
    ]);

    $this->actingAs($this->admin)
        ->get(route('matches.scores', $final))
        ->assertOk()
        ->assertSee('Submit day 1 as the provincial');

    $this->actingAs($this->admin)
        ->get(route('matches.settings', $final))
        ->assertOk()
        ->assertSee('Day 1 of this match is a provincial');

    $sibling = $final->findOrCreateProvincialDay1Sibling();

    expect($sibling->series_level)->toBe('provincial')
        ->and($sibling->source_national_match_id)->toBe($final->id)
        ->and($final->fresh()->series_level)->toBe('final');
});

it('shows the day 1 provincial control on a live two-day national, including after scores are complete', function () {
    $this->actingAs($this->admin)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Submit day 1 as the provincial')
        ->assertSee('Review day 1 stages');

    $this->match->update(['scores_finalized_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Submit day 1 as the provincial')
        ->assertSee('The championship stays marked complete');

    $club = day1National($this->admin, $this->province, [
        'name' => 'Club Saturday',
        'series_level' => 'club_only',
        'match_end_date' => null,
    ]);

    $this->actingAs($this->admin)
        ->get(route('matches.scores', $club))
        ->assertOk()
        ->assertDontSee('Submit day 1 as the provincial');
});

it('imports finished day 1 stages in one click and updates provincial standings', function () {
    fakeDay1Api(completedThrough: 8);
    $member = day1Member('Kevin Goncalves', $this->province);
    day1Register($this->match, $member, $this->open);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match))
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('success');

    $sibling = MatchEvent::where('source_national_match_id', $this->match->id)->first();
    $score = Score::where('match_id', $sibling->id)->where('user_id', $member->id)->first();
    $saved = $this->match->fresh()->impact_stage_days;

    expect($sibling->series_level)->toBe('provincial')
        ->and((float) $score->raw_score)->toBe(80.0)
        ->and((int) $saved[101])->toBe(1)
        ->and((int) $saved[108])->toBe(1)
        ->and((int) $saved[109])->toBe(2)
        ->and(Standing::where('user_id', $member->id)->whereNull('province_id')->exists())->toBeFalse()
        ->and(Standing::where('user_id', $member->id)->whereNotNull('province_id')->exists())->toBeTrue();

    $this->match->update([
        'match_date' => now()->subDay()->toDateString(),
        'match_end_date' => now()->toDateString(),
    ]);
    fakeDay1Api(completedThrough: 16);
    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect((float) $score->fresh()->raw_score)->toBe(80.0)
        ->and((int) $this->match->fresh()->impact_stage_days[109])->toBe(2);
});

it('keeps widening day 1 until the day is over, then freezes it', function () {
    $final = day1National($this->admin, $this->province, [
        'name' => 'Centrefire GP 2-Day National Championship',
        'series_level' => 'final',
    ]);
    $member = day1Member('Kevin Goncalves', $this->province);
    $guest = User::factory()->create(['name' => 'Guest Shooter', 'province_id' => $this->province->id]);
    day1Register($final, $member, $this->open);
    day1Register($final, $guest, $this->open);
    $national = Score::create([
        'match_id' => $final->id,
        'user_id' => $member->id,
        'division_id' => $this->open->id,
        'shooter_name' => $member->name,
        'day1_raw_score' => 160,
        'status' => 'pending',
        'counts_for_season' => false,
        'counts_for_log' => false,
        'match_date' => $final->match_date,
    ]);

    fakeDay1Api(completedThrough: 4);
    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $final))
        ->assertRedirect()
        ->assertSessionHas('success');

    $sibling = MatchEvent::where('source_national_match_id', $final->id)->first();
    $score = Score::where('match_id', $sibling->id)->where('user_id', $member->id)->first();
    expect($final->fresh()->series_level)->toBe('final')
        ->and($sibling->series_level)->toBe('provincial')
        ->and((float) $score->raw_score)->toBe(40.0)
        ->and((float) $national->fresh()->raw_score)->toBe(160.0)
        ->and(Score::where('match_id', $sibling->id)->where('user_id', $guest->id)->exists())->toBeTrue()
        ->and(Standing::where('user_id', $guest->id)->exists())->toBeFalse()
        ->and(session('success'))->toContain('Unmatched Walker')
        ->and(User::where('email', 'like', '%@saprf.walkin')->exists())->toBeFalse();

    fakeDay1Api(completedThrough: 8, shotButOpen: [109]);
    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $final))
        ->assertSessionHas('success');

    expect((float) $score->fresh()->raw_score)->toBe(90.0)
        ->and((int) $final->fresh()->impact_stage_days[109])->toBe(1)
        ->and((int) $final->fresh()->impact_stage_days[110])->toBe(2);

    $final->update([
        'match_date' => now()->subDay()->toDateString(),
        'match_end_date' => now()->toDateString(),
    ]);
    fakeDay1Api(completedThrough: 16);
    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $final))
        ->assertSessionHas('success');

    expect((float) $score->fresh()->raw_score)->toBe(90.0)
        ->and((int) $final->fresh()->impact_stage_days[110])->toBe(2)
        ->and(Standing::where('user_id', $member->id)->where('province_id', $this->province->id)->exists())->toBeTrue()
        ->and(Standing::where('user_id', $member->id)->whereNull('province_id')->exists())->toBeFalse();
});

it('writes nothing when impact is down or day 1 has not started', function () {
    Http::swap(new Factory);
    Http::fake(fn () => Http::response(['errorCode' => -1, 'errorLabel' => 'DOWN'], 500));

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(MatchEvent::where('source_national_match_id', $this->match->id)->exists())->toBeFalse()
        ->and(session('error'))->toContain('Nothing was written');

    fakeDay1Api(completedThrough: 0);
    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match))
        ->assertSessionHas('error');

    expect(MatchEvent::where('source_national_match_id', $this->match->id)->exists())->toBeFalse();
});

it('does not guess a day 1 split once every stage is finished', function () {
    $this->match->update([
        'match_date' => now()->subDay()->toDateString(),
        'match_end_date' => now()->toDateString(),
    ]);
    fakeDay1Api(completedThrough: 16);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match))
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('error');

    expect(MatchEvent::where('source_national_match_id', $this->match->id)->exists())->toBeFalse();
});

it('previews stages in impact order with no day guessed and writes nothing', function () {
    fakeDay1Api();

    $this->actingAs($this->admin)
        ->postJson(route('matches.scores.day1-provincial.preview', $this->match))
        ->assertOk()
        ->assertJsonPath('counts.unset', 16)
        ->assertJsonPath('counts.day1', 0)
        ->assertJsonPath('counts.day2', 0)
        ->assertJsonPath('stages.0.number', 1)
        ->assertJsonPath('stages.0.name', 'Stage 1')
        ->assertJsonPath('stages.0.day', null)
        ->assertJsonPath('stages.7.id', 108)
        ->assertJsonPath('stages.15.number', 16);

    expect(MatchEvent::where('source_national_match_id', $this->match->id)->exists())->toBeFalse()
        ->and(Score::count())->toBe(0)
        ->and($this->match->fresh()->impact_stage_days)->toBeNull();
});

it('restores saved stage days and drops a stage impact no longer sends', function () {
    $this->match->update([
        'impact_stage_days' => ['101' => 1, '999' => 2],
    ]);
    fakeDay1Api();

    $this->actingAs($this->admin)
        ->postJson(route('matches.scores.day1-provincial.preview', $this->match))
        ->assertOk()
        ->assertJsonPath('stages.0.day', 1)
        ->assertJsonPath('counts.day1', 1)
        ->assertJsonPath('counts.unset', 15);

    $saved = $this->match->fresh()->impact_stage_days;
    expect($saved)->toHaveCount(1)
        ->and((int) $saved[101])->toBe(1)
        ->and(array_key_exists(999, $saved))->toBeFalse();
});

it('submits day 1 stage totals onto the provincial and leaves the national running', function () {
    Notification::fake();
    fakeDay1Api();

    $member = day1Member('Kevin Goncalves', $this->province);
    day1Register($this->match, $member, $this->open);
    $guest = User::factory()->create(['name' => 'Guest Shooter', 'province_id' => $this->province->id]);
    day1Register($this->match, $guest, $this->open);

    $national = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $member->id,
        'division_id' => $this->open->id,
        'shooter_name' => $member->name,
        'day1_raw_score' => 160,
        'status' => 'pending',
        'is_member' => true,
        'counts_for_season' => false,
        'counts_for_log' => false,
        'match_date' => $this->match->match_date,
    ]);

    $usersBefore = User::count();

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('success');

    $sibling = MatchEvent::where('source_national_match_id', $this->match->id)->first();
    expect($sibling)->not->toBeNull()
        ->and($sibling->series_level)->toBe('provincial')
        ->and($sibling->everyone_counts)->toBeFalse()
        ->and($sibling->name)->toBe($this->match->name.' — Provincial (Day 1)')
        ->and($this->match->fresh()->impact_live_sync)->toBeTrue()
        ->and($this->match->fresh()->impact_live_ended_at)->toBeNull()
        ->and($this->match->fresh()->also_counts_for_provincial)->toBeFalse()
        ->and($this->match->fresh()->scores_finalized_at)->toBeNull()
        ->and((float) $national->fresh()->raw_score)->toBe(160.0)
        ->and($national->fresh()->counts_for_season)->toBeFalse()
        ->and(Score::where('match_id', $this->match->id)->count())->toBe(1)
        ->and(ScoreImport::count())->toBe(0)
        ->and(User::count())->toBe($usersBefore);

    $memberScore = Score::where('match_id', $sibling->id)->where('user_id', $member->id)->first();
    $guestScore = Score::where('match_id', $sibling->id)->where('user_id', $guest->id)->first();

    expect($memberScore)->not->toBeNull()
        ->and((float) $memberScore->day1_raw_score)->toBe(80.0)
        ->and((float) $memberScore->raw_score)->toBe(80.0)
        ->and($memberScore->day2_raw_score)->toBeNull()
        ->and($memberScore->counts_for_season)->toBeTrue()
        ->and($memberScore->status)->toBe('valid')
        ->and($guestScore)->not->toBeNull()
        ->and($guestScore->status)->toBe('non_member')
        ->and($guestScore->counts_for_season)->toBeTrue()
        ->and(Score::where('match_id', $sibling->id)->where('shooter_name', 'Unmatched Walker')->exists())->toBeFalse();

    expect(session('success'))->toContain('Unmatched Walker');

    $standing = Standing::where('user_id', $member->id)->where('division_id', $this->open->id)->first();
    expect($standing)->not->toBeNull()
        ->and(Standing::where('user_id', $guest->id)->exists())->toBeFalse();

    $colours = app(FederationColoursReport::class)->build('PRS', '2026');
    $names = collect($colours['divisions'])->flatMap(fn ($division) => collect($division['provinces'])->flatMap(
        fn ($province) => collect($province['rows'])->pluck('name')
    ));
    expect($names)->toContain('Kevin Goncalves')
        ->and($names)->not->toContain('Guest Shooter');

    Notification::assertNothingSent();
});

it('links a shooter through the director name map and ignores a fuzzy name', function () {
    fakeDay1Api([
        3 => day1Points(10),
    ], [
        ['id' => 3, 'name' => 'Kev G', 'rank' => 1, 'points' => 16000, 'divisionName' => 'Open'],
        ['id' => 4, 'name' => 'Kevin Goncalez', 'rank' => 2, 'points' => 1000, 'divisionName' => 'Open'],
    ]);
    $member = day1Member('Kevin Goncalves', $this->province);
    $registration = day1Register($this->match, $member, $this->open);
    $this->match->update([
        'impact_live_name_map' => ['Kev G' => $registration->id],
    ]);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertRedirect();

    $sibling = MatchEvent::where('source_national_match_id', $this->match->id)->first();
    expect(Score::where('match_id', $sibling->id)->where('user_id', $member->id)->exists())->toBeTrue()
        ->and(Score::where('match_id', $sibling->id)->where('shooter_name', 'Kevin Goncalves')->count())->toBe(1)
        ->and(User::where('email', 'like', '%@saprf.walkin')->exists())->toBeFalse();
});

it('rebuilds the provincial from a new day 1 cut without touching the national', function () {
    fakeDay1Api();
    $member = day1Member('Kevin Goncalves', $this->province);
    day1Register($this->match, $member, $this->open);
    $national = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $member->id,
        'division_id' => $this->open->id,
        'shooter_name' => $member->name,
        'day1_raw_score' => 160,
        'status' => 'pending',
        'counts_for_season' => false,
        'counts_for_log' => false,
        'match_date' => $this->match->match_date,
    ]);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 7),
        ])
        ->assertRedirect();

    $sibling = MatchEvent::where('source_national_match_id', $this->match->id)->first();
    $score = Score::where('match_id', $sibling->id)->where('user_id', $member->id)->first();

    expect((float) $score->raw_score)->toBe(70.0)
        ->and((float) $national->fresh()->raw_score)->toBe(160.0)
        ->and((int) $this->match->fresh()->impact_stage_days[108])->toBe(2)
        ->and(MatchEvent::where('source_national_match_id', $this->match->id)->count())->toBe(1);
});

it('removes a shooter whose day 1 stages add up to zero', function () {
    fakeDay1Api([
        1 => day1Points(10),
        2 => day1Points(0),
    ], [
        ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 16000, 'divisionName' => 'Open'],
        ['id' => 2, 'name' => 'Zero Shooter', 'rank' => 2, 'points' => 8000, 'divisionName' => 'Open'],
    ]);
    $keeper = day1Member('Kevin Goncalves', $this->province);
    $zero = day1Member('Zero Shooter', $this->province);
    day1Register($this->match, $keeper, $this->open);
    day1Register($this->match, $zero, $this->open);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ]);

    $sibling = MatchEvent::where('source_national_match_id', $this->match->id)->first();
    $zeroScore = Score::create([
        'match_id' => $sibling->id,
        'user_id' => $zero->id,
        'division_id' => $this->open->id,
        'shooter_name' => $zero->name,
        'day1_raw_score' => 40,
        'status' => 'valid',
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $sibling->match_date,
    ]);
    ShooterLog::create([
        'score_id' => $zeroScore->id,
        'user_id' => $zero->id,
        'match_id' => $sibling->id,
        'counted' => true,
    ]);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertRedirect();

    expect(Score::where('match_id', $sibling->id)->where('user_id', $zero->id)->exists())->toBeFalse()
        ->and(ShooterLog::where('user_id', $zero->id)->exists())->toBeFalse()
        ->and(Score::where('match_id', $sibling->id)->where('user_id', $keeper->id)->exists())->toBeTrue();
});

it('holds a shooter whose stage breakdown did not come back', function () {
    fakeDay1Api([
        1 => day1Points(10),
        2 => null,
    ], [
        ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 16000, 'divisionName' => 'Open'],
        ['id' => 2, 'name' => 'Held Shooter', 'rank' => 2, 'points' => 7000, 'divisionName' => 'Open'],
    ]);
    $keeper = day1Member('Kevin Goncalves', $this->province);
    $held = day1Member('Held Shooter', $this->province);
    day1Register($this->match, $keeper, $this->open);
    day1Register($this->match, $held, $this->open);

    $sibling = $this->match->findOrCreateProvincialDay1Sibling();
    Score::create([
        'match_id' => $sibling->id,
        'user_id' => $held->id,
        'division_id' => $this->open->id,
        'shooter_name' => $held->name,
        'day1_raw_score' => 55,
        'status' => 'valid',
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $sibling->match_date,
    ]);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $heldScore = Score::where('match_id', $sibling->id)->where('user_id', $held->id)->first();
    expect((float) $heldScore->raw_score)->toBe(55.0)
        ->and((float) Score::where('match_id', $sibling->id)->where('user_id', $keeper->id)->value('raw_score'))->toBe(80.0)
        ->and(session('success'))->toContain('Held Shooter');
});

it('rejects an incomplete stage map and an all day 2 map without writing', function () {
    fakeDay1Api();
    $member = day1Member('Kevin Goncalves', $this->province);
    day1Register($this->match, $member, $this->open);
    $this->match->update(['impact_stage_days' => ['101' => 1]]);

    $missing = day1StageMap(1, 8);
    unset($missing[116]);

    $this->actingAs($this->admin)
        ->from(route('matches.scores', $this->match))
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => $missing,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $allDay2 = [];
    foreach (range(101, 116) as $id) {
        $allDay2[$id] = 2;
    }

    $this->actingAs($this->admin)
        ->from(route('matches.scores', $this->match))
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => $allDay2,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(MatchEvent::where('source_national_match_id', $this->match->id)->exists())->toBeFalse()
        ->and(Score::count())->toBe(0)
        ->and($this->match->fresh()->impact_stage_days)->toBe(['101' => 1]);
});

it('leaves the provincial and the saved marks alone when impact is down', function () {
    $member = day1Member('Kevin Goncalves', $this->province);
    day1Register($this->match, $member, $this->open);
    $sibling = $this->match->findOrCreateProvincialDay1Sibling();
    Score::create([
        'match_id' => $sibling->id,
        'user_id' => $member->id,
        'division_id' => $this->open->id,
        'shooter_name' => $member->name,
        'day1_raw_score' => 80,
        'status' => 'valid',
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $sibling->match_date,
    ]);
    $this->match->update(['impact_stage_days' => day1StageMap(1, 8)]);

    Http::swap(new Factory);
    Http::fake(fn () => Http::response(['errorCode' => -1, 'errorLabel' => 'DOWN'], 500));

    $this->actingAs($this->admin)
        ->from(route('matches.scores', $this->match))
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 7),
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect((float) Score::where('match_id', $sibling->id)->value('raw_score'))->toBe(80.0)
        ->and((int) $this->match->fresh()->impact_stage_days[108])->toBe(1)
        ->and(session('error'))->toContain('Nothing was written');
});

it('writes day 1 onto the provincial after the national scores are marked complete', function () {
    Notification::fake();
    fakeDay1Api();
    $finalizedAt = now();
    $this->match->update(['scores_finalized_at' => $finalizedAt]);

    $member = day1Member('Kevin Goncalves', $this->province);
    day1Register($this->match, $member, $this->open);
    $national = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $member->id,
        'division_id' => $this->open->id,
        'shooter_name' => $member->name,
        'day1_raw_score' => 160,
        'status' => 'pending',
        'is_member' => true,
        'counts_for_season' => true,
        'counts_for_log' => true,
        'match_date' => $this->match->match_date,
    ]);

    $this->actingAs($this->admin)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('success');

    $sibling = MatchEvent::where('source_national_match_id', $this->match->id)->first();
    $provincial = Score::where('match_id', $sibling->id)->where('user_id', $member->id)->first();

    expect($sibling)->not->toBeNull()
        ->and($this->match->fresh()->scores_finalized_at)->not->toBeNull()
        ->and((float) $national->fresh()->raw_score)->toBe(160.0)
        ->and($provincial)->not->toBeNull()
        ->and((float) $provincial->day1_raw_score)->toBe(80.0);
});

it('blocks a member who cannot update the match', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->post(route('matches.scores.day1-provincial.store', $this->match), [
            'stage_days' => day1StageMap(1, 8),
        ])
        ->assertForbidden();
});

function day1National(User $owner, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Gauteng National',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $province->id,
        'venue_name' => 'Range',
        'match_date' => now()->toDateString(),
        'match_end_date' => now()->addDay()->toDateString(),
        'status' => 'closed',
        'published' => true,
        'created_by' => $owner->id,
        'active_member_fee' => 100,
        'impact_match_id' => 11086,
        'impact_live_sync' => true,
    ], $overrides));
}

function day1Member(string $name, Province $province): User
{
    $user = User::factory()->create([
        'name' => $name,
        'province_id' => $province->id,
        'email_verified_at' => now(),
    ]);
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

function day1Register(MatchEvent $match, User $user, Division $division): MatchRegistration
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

/**
 * Stage ids 101-116. Day 1 hits are 10 each for shooter 1 on stages 1-8 (80)
 * and 10 each on 9-16 as well, so the combined total is 160.
 *
 * @param  array<int, array<int, int>|null>|null  $pointsByShooter  shooter id => stage id => raw points, or null for a failed breakdown
 * @param  list<array<string, mixed>>|null  $shooters
 */
function fakeDay1Api(?array $pointsByShooter = null, ?array $shooters = null, ?int $completedThrough = null, array $shotButOpen = []): void
{
    $pointsByShooter ??= [
        1 => day1Points(10),
        2 => day1Points(10),
    ];
    $shooters ??= [
        ['id' => 1, 'name' => 'Kevin Goncalves', 'rank' => 1, 'points' => 16000, 'divisionName' => 'Open'],
        ['id' => 2, 'name' => 'Guest Shooter', 'rank' => 2, 'points' => 16000, 'divisionName' => 'Open'],
        ['id' => 3, 'name' => 'Kev G', 'rank' => 3, 'points' => 16000, 'divisionName' => 'Open'],
        ['id' => 4, 'name' => 'Unmatched Walker', 'rank' => 4, 'points' => 9000, 'divisionName' => 'Open'],
        ['id' => 5, 'name' => 'Zero Shooter', 'rank' => 5, 'points' => 8000, 'divisionName' => 'Open'],
        ['id' => 6, 'name' => 'Held Shooter', 'rank' => 6, 'points' => 7000, 'divisionName' => 'Open'],
    ];

    Http::swap(new Factory);
    Http::fake(function (Request $request) use ($pointsByShooter, $shooters, $completedThrough, $shotButOpen) {
        $url = $request->url();
        if (str_contains($url, '/rest/website/getLiveScoresForShooter')) {
            $shooterId = (int) $request->data()['shooterId'];
            if (! array_key_exists($shooterId, $pointsByShooter) || $pointsByShooter[$shooterId] === null) {
                return Http::response(['errorCode' => -1, 'errorLabel' => 'NO_STAGES'], 200);
            }

            $stages = [];
            foreach ($pointsByShooter[$shooterId] as $stageId => $points) {
                $open = in_array($stageId, $shotButOpen, true);
                $finished = $open || $completedThrough === null || ($stageId - 100) <= $completedThrough;
                $stages[] = [
                    'id' => $stageId,
                    'name' => 'Stage '.($stageId - 100),
                    'points' => $finished ? $points : 0,
                    'misses' => 0,
                    'shotsProgressCount' => $finished ? 10 : 0,
                    'maxShots' => 10,
                    'completed' => $finished && ! $open,
                ];
            }

            return Http::response(['errorCode' => 0, 'payload' => ['stages' => $stages]], 200);
        }

        if (str_contains($url, '/rest/website/getLiveScores')) {
            return Http::response([
                'errorCode' => 0,
                'errorLabel' => 'SUCCESS',
                'payload' => [
                    'match' => ['id' => 11086, 'name' => 'Gauteng National', 'matchEnded' => false],
                    'stages' => collect(range(101, 116))->map(fn (int $id) => [
                        'id' => $id,
                        'name' => 'Stage '.($id - 100),
                    ])->all(),
                    'shooters' => $shooters,
                    'divisions' => [],
                    'categories' => [],
                ],
            ], 200);
        }

        return Http::response(['errorCode' => -1], 404);
    });
}

/**
 * @return array<int, int>
 */
function day1Points(int $hits): array
{
    $points = [];
    foreach (range(101, 116) as $id) {
        $points[$id] = $hits * 100;
    }

    return $points;
}

/**
 * @return array<int, int>
 */
function day1StageMap(int $day1Through, int $day1Last): array
{
    $map = [];
    foreach (range(101, 116) as $offset => $id) {
        $number = $offset + 1;
        $map[$id] = ($number >= $day1Through && $number <= $day1Last) ? 1 : 2;
    }

    return $map;
}
