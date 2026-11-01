<?php

declare(strict_types=1);

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 6: `events:audit` must be read-only, exit 0 regardless of
 * findings, and surface rows under the four categories defined in the
 * plan — inconsistent names, test matches, TBC venues, missing fields.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

function auditTestMatch(array $overrides, Province $province, User $creator): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Audit Match',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $creator->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
        'venue_name' => 'Real Range',
    ], $overrides));
}

it('flags matches whose names canonicalise identically', function () {
    auditTestMatch(['name' => '2026 GP Provincial #3 PR22'], $this->province, $this->admin);
    auditTestMatch(['name' => '2026   GP   provincial  #3  pr22'], $this->province, $this->admin);
    auditTestMatch(['name' => 'Totally Unique Match'], $this->province, $this->admin);

    $this->artisan('events:audit')
        ->expectsOutputToContain('A. Inconsistent match names')
        ->expectsOutputToContain('2026 GP Provincial #3 PR22')
        ->doesntExpectOutputToContain('Totally Unique Match')
        ->assertExitCode(0);
});

it('flags matches with test-like names or slugs', function () {
    auditTestMatch(['name' => 'TEST Match For Imports', 'slug' => 'test-match'], $this->province, $this->admin);
    auditTestMatch(['name' => 'Sandbox 2026'], $this->province, $this->admin);
    auditTestMatch(['name' => 'Production Championship 2026'], $this->province, $this->admin);

    $this->artisan('events:audit')
        ->expectsOutputToContain('B. Likely test / fixture matches')
        ->expectsOutputToContain('TEST Match For Imports')
        ->expectsOutputToContain('Sandbox 2026')
        ->doesntExpectOutputToContain('Production Championship 2026')
        ->assertExitCode(0);
});

it('flags published matches with TBC/blank venue data', function () {
    $tbc = auditTestMatch([
        'name' => 'Published TBC Venue',
        'venue_name' => 'TBC', 'venue_location' => null, 'city' => null,
    ], $this->province, $this->admin);
    auditTestMatch([
        'name' => 'Published Real Venue',
        'venue_name' => 'Vereeniging Range', 'city' => 'Vereeniging',
    ], $this->province, $this->admin);
    // Draft row with blank venues must NOT be flagged.
    auditTestMatch([
        'name' => 'Draft Blank Venue',
        'venue_name' => null, 'venue_location' => null, 'city' => null,
        'status' => 'draft',
    ], $this->province, $this->admin);

    Artisan::call('events:audit');
    $output = Artisan::output();

    // Section C heading always prints; the flagged ID must appear in the C block.
    expect($output)->toContain('C. Published matches with TBC/blank venue data');
    expect($output)->toMatch('/C\. Published matches with TBC.+'.$tbc->id.'/s');
    // Real venue and draft row must NOT appear anywhere in the C table.
    [$beforeD] = explode('D. Published matches missing required fields', $output);
    [, $cBlock] = explode('C. Published matches with TBC/blank venue data', $beforeD);
    expect($cBlock)->not->toContain('Published Real Venue');
    expect($cBlock)->not->toContain('Draft Blank Venue');
});

it('flags published matches missing required fields', function () {
    $missing = auditTestMatch(['name' => 'Missing Season', 'season' => null], $this->province, $this->admin);
    auditTestMatch(['name' => 'All Fields Present'], $this->province, $this->admin);

    Artisan::call('events:audit');
    $output = Artisan::output();

    expect($output)->toContain('D. Published matches missing required fields');
    // The row for the missing-season match must appear in the D block with
    // 'season' as the missing field.
    [, $dBlock] = explode('D. Published matches missing required fields', $output);
    expect($dBlock)->toMatch('/'.$missing->id.'.+season/s');
    expect($dBlock)->not->toContain('All Fields Present');
});

it('writes a CSV report when --csv is passed', function () {
    Storage::fake('local');

    auditTestMatch(['name' => 'TEST For CSV'], $this->province, $this->admin);

    $this->artisan('events:audit', ['--csv' => true])->assertExitCode(0);

    $files = Storage::disk('local')->files('reports');
    expect($files)->not->toBeEmpty();

    $csv = Storage::disk('local')->get($files[0]);
    expect($csv)->toContain('section,id,name');
    expect($csv)->toContain('test_match');
    expect($csv)->toContain('TEST For CSV');
});

it('is read-only — no match rows change', function () {
    $m = auditTestMatch(['name' => 'TEST Audit Should Not Touch Me'], $this->province, $this->admin);
    $before = $m->only(['name', 'published', 'status', 'venue_name']);

    $this->artisan('events:audit')->assertExitCode(0);

    expect($m->fresh()->only(['name', 'published', 'status', 'venue_name']))->toEqual($before);
});
