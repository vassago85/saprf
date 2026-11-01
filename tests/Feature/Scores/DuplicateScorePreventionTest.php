<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1.1 guarantees: the CSV importer and the Impact live importer
 * MUST NOT create a second score row for the same (match_id, user_id).
 * A re-import updates the existing row instead.
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
        'name' => 'Duplicate Guard Test',
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

    $this->shooter = User::factory()->create(['name' => 'Japie Louw']);
});

function dupGuardCsv(string $body): UploadedFile
{
    $header = "Place,Last,First,Class,Div,Category,Member Number,Time,Impacts,Match %\n";

    return UploadedFile::fake()->createWithContent('impact.csv', $header.$body);
}

it('upserts rather than inserting when the same CSV is uploaded twice for the same shooter', function () {
    $firstBody = '1,Louw,Japie,,Senior,Senior,,180.5,145,80%'."\n";
    $secondBody = '1,Louw,Japie,,Senior,Senior,,180.5,150,83%'."\n";

    // First import — one row.
    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => dupGuardCsv($firstBody),
        'replace_existing' => 0,
    ]);

    expect(Score::where('match_id', $this->match->id)->count())->toBe(1);

    // Second import of the same shooter — must UPDATE, not duplicate.
    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => dupGuardCsv($secondBody),
        'replace_existing' => 0,
    ]);

    expect(Score::where('match_id', $this->match->id)->count())->toBe(1);
    $score = Score::where('match_id', $this->match->id)->first();
    expect((float) $score->raw_score)->toBe(150.0);
});

it('still produces separate rows for different linked shooters on the same match', function () {
    $other = User::factory()->create(['name' => 'Jaco Smith']);

    $body = '1,Louw,Japie,,Senior,Senior,,180.5,145,80%'."\n"
          .'2,Smith,Jaco,,Open,,,185.1,140,78%'."\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => dupGuardCsv($body),
        'replace_existing' => 0,
    ]);

    expect(Score::where('match_id', $this->match->id)->count())->toBe(2);
});

it('the find-duplicates command exits non-zero and reports every group when duplicates exist', function () {
    // Pre-constraint state: temporarily drop the Phase 1.1 unique index so
    // we can plant the legacy-shaped duplicate the report is designed to
    // find. RefreshDatabase re-adds it before the next test case.
    \Illuminate\Support\Facades\Schema::table('scores', function ($table) {
        $table->dropUnique('scores_match_user_unique');
    });

    \Illuminate\Support\Facades\DB::table('scores')->insert([
        [
            'match_id' => $this->match->id,
            'user_id' => $this->shooter->id,
            'shooter_name' => $this->shooter->name,
            'raw_score' => 145,
            'placement' => 66,
            'status' => 'pending',
            'match_date' => $this->match->match_date,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'match_id' => $this->match->id,
            'user_id' => $this->shooter->id,
            'shooter_name' => $this->shooter->name,
            'raw_score' => 145,
            'placement' => 72,
            'status' => 'pending',
            'match_date' => $this->match->match_date,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->artisan('scores:find-duplicates')
        ->expectsOutputToContain('duplicate group(s) found')
        ->assertExitCode(1);
});

it('the find-duplicates command exits zero and says nothing is wrong when the table is clean', function () {
    $this->artisan('scores:find-duplicates')
        ->expectsOutputToContain('No duplicate')
        ->assertExitCode(0);
});
