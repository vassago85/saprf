<?php

use App\Jobs\AutoCompletePastMatchesJob;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use App\Services\AuditLogService;
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

    // Match resolution by email is safest under sqlite (no REGEXP_REPLACE).
    $this->alice = User::factory()->create([
        'name' => 'Alice Shooter',
        'email' => 'alice@example.com',
    ]);
});

function autoCompletionCsv(string $name, string $email, float $score = 50.0): UploadedFile
{
    $contents = "shooter_name,email,raw_score,placement,division\n"
        ."{$name},{$email},{$score},1,open\n";

    return UploadedFile::fake()->createWithContent('scores.csv', $contents);
}

function makeMatch(array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Test Provincial',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => (string) now()->year,
        'province_id' => Province::query()->value('id'),
        'match_date' => now()->subDays(2)->toDateString(),
        'status' => 'open',
        'published' => true,
        'created_by' => test()->admin->id,
        'active_member_fee' => 250,
        'non_member_fee' => 400,
        'lapsed_member_fee' => 300,
    ], $overrides));
}

it('auto-completes a single-day match after a successful score import', function () {
    $match = makeMatch(['status' => 'open']);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $match->id,
        'source_type' => 'csv',
        'file' => autoCompletionCsv('Alice Shooter', 'alice@example.com'),
    ])->assertRedirect();

    expect($match->fresh()->status)->toBe('completed');
});

it('does not auto-complete a match whose end date is still in the future', function () {
    // Day-1 upload for a 2-day national that runs today + tomorrow. Even though
    // scores are in for day 1, the event is still running so registration/status
    // must not close under the shooters.
    $match = makeMatch([
        'match_date' => now()->toDateString(),
        'match_end_date' => now()->addDay()->toDateString(),
        'status' => 'open',
    ]);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $match->id,
        'source_type' => 'csv',
        'file' => autoCompletionCsv('Alice Shooter', 'alice@example.com'),
    ])->assertRedirect();

    expect($match->fresh()->status)->toBe('open');
});

it('leaves cancelled matches alone even when scores land after the fact', function () {
    $match = makeMatch(['status' => 'cancelled']);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $match->id,
        'source_type' => 'csv',
        'file' => autoCompletionCsv('Alice Shooter', 'alice@example.com'),
    ])->assertRedirect();

    expect($match->fresh()->status)->toBe('cancelled');
});

it('scheduled job completes any match whose last day has passed', function () {
    $pastSingleDay = makeMatch([
        'name' => 'Yesterday Provincial',
        'match_date' => now()->subDay()->toDateString(),
        'status' => 'open',
    ]);

    $pastTwoDay = makeMatch([
        'name' => 'Two-Day National',
        'match_date' => now()->subDays(3)->toDateString(),
        'match_end_date' => now()->subDays(2)->toDateString(),
        'status' => 'closed',
    ]);

    $runningToday = makeMatch([
        'name' => 'Live Match',
        'match_date' => now()->toDateString(),
        'status' => 'open',
    ]);

    $alreadyCompleted = makeMatch([
        'name' => 'Old Completed',
        'match_date' => now()->subDays(10)->toDateString(),
        'status' => 'completed',
    ]);

    $cancelled = makeMatch([
        'name' => 'Cancelled Match',
        'match_date' => now()->subDay()->toDateString(),
        'status' => 'cancelled',
    ]);

    (new AutoCompletePastMatchesJob)->handle(app(AuditLogService::class));

    expect($pastSingleDay->fresh()->status)->toBe('completed')
        ->and($pastTwoDay->fresh()->status)->toBe('completed')
        ->and($runningToday->fresh()->status)->toBe('open')
        ->and($alreadyCompleted->fresh()->status)->toBe('completed')
        ->and($cancelled->fresh()->status)->toBe('cancelled');
});

it('shows a request-payout prompt on the import page after auto-completion', function () {
    $match = makeMatch(['status' => 'open']);

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $match->id,
        'source_type' => 'csv',
        'file' => autoCompletionCsv('Alice Shooter', 'alice@example.com'),
    ]);

    $import = \App\Models\ScoreImport::latest('id')->first();

    $this->actingAs($this->admin)
        ->get(route('score-imports.show', $import))
        ->assertOk()
        ->assertSee('Match closed — request your payout?')
        ->assertSee('Request match director payout');
});
