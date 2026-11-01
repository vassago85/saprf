<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1.2: every imported score should land with status = 'pending' at
 * minimum, so admin / match-director reviewers see a meaningful badge.
 * The backfill command fills the gap for legacy rows that predate the
 * default; the make-non-nullable migration locks it down afterwards.
 */
beforeEach(function () {
    seedRoles();
    Storage::fake('local');

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 2, 'is_active' => true]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Status Default Test',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
});

it('every imported score has a non-null status', function () {
    $header = "Place,Last,First,Class,Div,Category,Member Number,Time,Impacts,Match %\n";
    $body = '1,Louw,Japie,,Senior,Senior,,180.5,145,80%'."\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => UploadedFile::fake()->createWithContent('impact.csv', $header.$body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();
    expect($score->status)->not->toBeNull();
});

it('the backfill command reports zero changes when nothing is null', function () {
    $this->artisan('scores:backfill-pending-status')
        ->expectsOutputToContain('Nothing to backfill')
        ->assertExitCode(0);
});

it('the backfill command reports the row count without --apply and updates with --apply', function () {
    // Simulate the pre-Phase-1.2 schema (status nullable) so we can plant a
    // legacy-shaped row with NULL. RefreshDatabase re-adds the NOT NULL
    // constraint before the next test case runs.
    \Illuminate\Support\Facades\Schema::table('scores', function ($table) {
        $table->string('status')->nullable()->change();
    });

    // Plant a legacy-shaped row with NULL status via a direct DB insert so the
    // ->default() does not auto-fill it.
    $user = User::factory()->create(['name' => 'Null Status Shooter']);
    DB::table('scores')->insert([
        'match_id' => $this->match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'raw_score' => 100,
        'placement' => 1,
        'status' => null,
        'match_date' => $this->match->match_date,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Dry run — reports, does not update.
    $this->artisan('scores:backfill-pending-status')
        ->expectsOutputToContain('Scores with NULL:    1')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);
    expect(DB::table('scores')->whereNull('status')->count())->toBe(1);

    // --apply — writes the backfill.
    $this->artisan('scores:backfill-pending-status', ['--apply' => true])
        ->expectsOutputToContain('Updated 1 row')
        ->assertExitCode(0);
    expect(DB::table('scores')->whereNull('status')->count())->toBe(0);
    expect(DB::table('scores')->where('status', 'pending')->count())->toBe(1);
});
