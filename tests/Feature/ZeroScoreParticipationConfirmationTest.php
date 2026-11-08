<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\User;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->md = User::factory()->create(['name' => 'Match Director']);
    $this->md->assignRole('match_director');

    $this->otherMd = User::factory()->create(['name' => 'Other MD']);
    $this->otherMd->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Test Provincial',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subDay()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->md->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);

    $this->import = ScoreImport::create([
        'match_id' => $this->match->id,
        'uploaded_by' => $this->md->id,
        'source_type' => 'csv',
        'original_filename' => 'test.csv',
        'import_status' => 'completed',
    ]);
});

function makeZeroConfirmationScore(int $matchId, int $importId, string $name, float $day1): Score
{
    return Score::create([
        'match_id' => $matchId,
        'score_import_id' => $importId,
        'shooter_name' => $name,
        'day1_raw_score' => $day1,
        'status' => 'pending',
        'is_member' => false,
        'match_date' => now()->subDay()->toDateString(),
    ]);
}

it('shows the review banner when the import contains zero-score shooters', function () {
    makeZeroConfirmationScore($this->match->id, $this->import->id, 'Zero Zach', 0);
    makeZeroConfirmationScore($this->match->id, $this->import->id, 'Real Rachel', 42.5);

    $response = $this->actingAs($this->md)
        ->get(route('score-imports.show', $this->import));

    $response->assertOk()
        ->assertSee('Please confirm the shooters below actually participated')
        ->assertSee('Zero Zach');
});

it('does not show the banner when there are no zero scores', function () {
    makeZeroConfirmationScore($this->match->id, $this->import->id, 'Real Rachel', 42.5);

    $response = $this->actingAs($this->md)
        ->get(route('score-imports.show', $this->import));

    $response->assertOk()
        ->assertDontSee('Please confirm the shooters below actually participated');
});

it('does not show the banner while the import is still processing', function () {
    $this->import->update(['import_status' => 'processing']);
    makeZeroConfirmationScore($this->match->id, $this->import->id, 'Zero Zach', 0);

    $response = $this->actingAs($this->md)
        ->get(route('score-imports.show', $this->import));

    $response->assertOk()
        ->assertDontSee('Please confirm the shooters below actually participated');
});

it('lets the match director confirm a zero-score shooter (keeps the row, stamps confirmation)', function () {
    $score = makeZeroConfirmationScore($this->match->id, $this->import->id, 'Zero Zach', 0);

    $response = $this->actingAs($this->md)
        ->post(route('score-imports.scores.confirm-participation', [
            'scoreImport' => $this->import,
            'score' => $score,
        ]));

    $response->assertRedirect(route('score-imports.show', $this->import));

    $score->refresh();
    expect($score->exists)->toBeTrue()
        ->and($score->participation_confirmed_at)->not->toBeNull()
        ->and($score->participation_confirmed_by)->toBe($this->md->id);

    // Banner disappears once confirmed.
    $this->actingAs($this->md)
        ->get(route('score-imports.show', $this->import))
        ->assertDontSee('Please confirm the shooters below actually participated');
});

it('lets the match director mark a zero-score shooter as absent (deletes the row)', function () {
    $score = makeZeroConfirmationScore($this->match->id, $this->import->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->md)
        ->delete(route('score-imports.scores.mark-absent', [
            'scoreImport' => $this->import,
            'score' => $score,
        ]));

    $response->assertRedirect(route('score-imports.show', $this->import));

    expect(Score::find($score->id))->toBeNull();
});

it('blocks a match director who does not own the match from confirming', function () {
    $score = makeZeroConfirmationScore($this->match->id, $this->import->id, 'Zero Zach', 0);

    $response = $this->actingAs($this->otherMd)
        ->post(route('score-imports.scores.confirm-participation', [
            'scoreImport' => $this->import,
            'score' => $score,
        ]));

    $response->assertForbidden();

    $score->refresh();
    expect($score->participation_confirmed_at)->toBeNull();
});

it('blocks a match director who does not own the match from marking absent', function () {
    $score = makeZeroConfirmationScore($this->match->id, $this->import->id, 'Ghost Gary', 0);

    $response = $this->actingAs($this->otherMd)
        ->delete(route('score-imports.scores.mark-absent', [
            'scoreImport' => $this->import,
            'score' => $score,
        ]));

    $response->assertForbidden();

    expect(Score::find($score->id))->not->toBeNull();
});

it('lets an admin confirm zero scores on any match', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $score = makeZeroConfirmationScore($this->match->id, $this->import->id, 'Zero Zach', 0);

    $response = $this->actingAs($admin)
        ->post(route('score-imports.scores.confirm-participation', [
            'scoreImport' => $this->import,
            'score' => $score,
        ]));

    $response->assertRedirect();
    expect($score->fresh()->participation_confirmed_at)->not->toBeNull();
});

it('lets the uploader confirm even when they no longer own the match', function () {
    // Uploader is otherMd this time; the match owner is $this->md.
    $import = ScoreImport::create([
        'match_id' => $this->match->id,
        'uploaded_by' => $this->otherMd->id,
        'source_type' => 'csv',
        'original_filename' => 'other.csv',
        'import_status' => 'completed',
    ]);
    $score = makeZeroConfirmationScore($this->match->id, $import->id, 'Zero Zach', 0);

    $response = $this->actingAs($this->otherMd)
        ->post(route('score-imports.scores.confirm-participation', [
            'scoreImport' => $import,
            'score' => $score,
        ]));

    $response->assertRedirect();
    expect($score->fresh()->participation_confirmed_at)->not->toBeNull();
});

it('rejects a score that belongs to a different import', function () {
    $otherImport = ScoreImport::create([
        'match_id' => $this->match->id,
        'uploaded_by' => $this->md->id,
        'source_type' => 'csv',
        'original_filename' => 'other.csv',
        'import_status' => 'completed',
    ]);
    $score = makeZeroConfirmationScore($this->match->id, $otherImport->id, 'Zero Zach', 0);

    $response = $this->actingAs($this->md)
        ->post(route('score-imports.scores.confirm-participation', [
            'scoreImport' => $this->import,
            'score' => $score,
        ]));

    $response->assertNotFound();
});

// Impact live sync writes scores directly with no parent ScoreImport, so
// the closeout wizard must render for them and the standalone
// scores.confirm-participation endpoint must stamp the confirmation.
it('lets the director confirm a zero that came in without a CSV import (Impact live or manual entry)', function () {
    $score = Score::create([
        'match_id' => $this->match->id,
        'score_import_id' => null,
        'shooter_name' => 'Live Larry',
        'raw_score' => 0,
        'status' => 'pending',
        'is_member' => false,
        'match_date' => now()->subDay()->toDateString(),
    ]);

    $response = $this->actingAs($this->md)
        ->post(route('scores.confirm-participation', ['score' => $score]));

    $response->assertRedirect();

    $score->refresh();
    expect($score->exists)->toBeTrue()
        ->and($score->participation_confirmed_at)->not->toBeNull()
        ->and($score->participation_confirmed_by)->toBe($this->md->id);
});

it('rejects a non-zero score through the standalone confirm-participation action', function () {
    $score = Score::create([
        'match_id' => $this->match->id,
        'score_import_id' => null,
        'shooter_name' => 'Real Rachel',
        'raw_score' => 42,
        'status' => 'pending',
        'is_member' => false,
        'match_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($this->md)
        ->post(route('scores.confirm-participation', ['score' => $score]))
        ->assertStatus(422);
});

it('renders the scores closeout wizard when a zero-score row has no CSV parent', function () {
    // Regression: the wizard view used to call route(..., ['scoreImport' =>
    // $zero->score_import_id]) unconditionally, which blew up with a
    // UrlGenerationException for an Impact live pull (score_import_id null).
    Score::create([
        'match_id' => $this->match->id,
        'score_import_id' => null,
        'shooter_name' => 'Live Larry',
        'raw_score' => 0,
        'status' => 'pending',
        'is_member' => false,
        'match_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($this->md)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Live Larry')
        ->assertSee('Yes, participated');
});
