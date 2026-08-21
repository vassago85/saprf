<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('local');

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // Pre-create shooters by email so the importer never hits MySQL-only
    // REGEXP_REPLACE name matching under sqlite.
    $this->alice = User::factory()->create(['name' => 'Alice Shooter', 'email' => 'alice@example.com']);
    $this->bob = User::factory()->create(['name' => 'Bob Shooter', 'email' => 'bob@example.com']);
    $this->carol = User::factory()->create(['name' => 'Carol Shooter', 'email' => 'carol@example.com']);
    $this->dave = User::factory()->create(['name' => 'Dave Shooter', 'email' => 'dave@example.com']);

    $this->national = MatchEvent::create([
        'name' => 'Clash of the Legends PR22',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'venue_name' => 'Legends Adventure Farm',
        'match_date' => '2026-06-06',
        'match_end_date' => '2026-06-07',
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'lapsed_member_fee' => 600,
    ]);
});

function totalsOnlyCsv(string $name, string $email, float $score = 42.0): UploadedFile
{
    $contents = "shooter_name,email,raw_score,placement,division\n"
        ."{$name},{$email},{$score},1,open\n";

    return UploadedFile::fake()->createWithContent('scores.csv', $contents);
}

it('creates a provincial Day 1 sibling and imports totals-only scores onto it', function () {
    $response = $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'day1',
        'file' => totalsOnlyCsv('Alice Shooter', 'alice@example.com', 42),
        'replace_existing' => 0,
    ]);

    $sibling = MatchEvent::query()
        ->where('source_national_match_id', $this->national->id)
        ->first();

    expect($sibling)->not->toBeNull()
        ->and($sibling->name)->toBe('Clash of the Legends PR22 — Provincial (Day 1)')
        ->and($sibling->series_level)->toBe('provincial')
        ->and($sibling->everyone_counts)->toBeTrue()
        ->and($sibling->match_end_date)->toBeNull();

    $import = \App\Models\ScoreImport::latest('id')->first();
    $response->assertRedirect(route('score-imports.show', $import));

    expect($import->import_status)->toBe('completed')
        ->and(Score::where('match_id', $sibling->id)->count())->toBe(1)
        ->and(Score::where('match_id', $this->national->id)->count())->toBe(0)
        ->and((float) Score::where('match_id', $sibling->id)->value('raw_score'))->toBe(42.0);
});

it('reuses the same provincial sibling on a second Day 1 upload', function () {
    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'day1',
        'file' => totalsOnlyCsv('Alice Shooter', 'alice@example.com', 40),
    ]);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'day1',
        'file' => totalsOnlyCsv('Bob Shooter', 'bob@example.com', 38),
        'replace_existing' => 0,
    ]);

    expect(MatchEvent::where('source_national_match_id', $this->national->id)->count())->toBe(1)
        ->and(Score::where('match_id', MatchEvent::where('source_national_match_id', $this->national->id)->value('id'))->count())->toBe(2);
});

it('imports overall scores onto the national match only', function () {
    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'overall',
        'file' => totalsOnlyCsv('Alice Shooter', 'alice@example.com', 85),
    ]);

    expect(MatchEvent::where('source_national_match_id', $this->national->id)->exists())->toBeFalse()
        ->and(Score::where('match_id', $this->national->id)->count())->toBe(1)
        ->and((float) Score::where('match_id', $this->national->id)->value('raw_score'))->toBe(85.0);
});

it('replacing Day 1 scores does not wipe national overall scores', function () {
    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'overall',
        'file' => totalsOnlyCsv('Alice Shooter', 'alice@example.com', 85),
    ])->assertRedirect();

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'day1',
        'file' => totalsOnlyCsv('Alice Shooter', 'alice@example.com', 40),
    ])->assertRedirect();

    $siblingId = MatchEvent::where('source_national_match_id', $this->national->id)->value('id');
    expect(Score::where('match_id', $siblingId)->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'score_scope' => 'day1',
        'file' => totalsOnlyCsv('Carol Shooter', 'carol@example.com', 41),
        'replace_existing' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Score::where('match_id', $this->national->id)->count())->toBe(1)
        ->and((float) Score::where('match_id', $this->national->id)->value('raw_score'))->toBe(85.0)
        ->and(Score::where('match_id', $siblingId)->count())->toBe(1)
        ->and(Score::where('match_id', $siblingId)->value('shooter_name'))->toBe('Carol Shooter');
});

it('imports a totals-only CSV onto a single-day provincial without a score_scope', function () {
    $provincial = MatchEvent::create([
        'name' => 'GP Provincial One-Day',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => '2026-04-11',
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 250,
        'non_member_fee' => 400,
        'lapsed_member_fee' => 300,
    ]);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $provincial->id,
        'source_type' => 'csv',
        'file' => totalsOnlyCsv('Dave Shooter', 'dave@example.com', 33),
    ])->assertRedirect();

    expect(Score::where('match_id', $provincial->id)->count())->toBe(1)
        ->and((float) Score::where('match_id', $provincial->id)->value('raw_score'))->toBe(33.0);
});

it('requires score_scope for a 2-day national', function () {
    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->national->id,
        'source_type' => 'csv',
        'file' => totalsOnlyCsv('Alice Shooter', 'alice@example.com'),
    ])->assertSessionHasErrors('score_scope');
});
