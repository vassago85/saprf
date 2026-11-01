<?php

declare(strict_types=1);

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\ScoreImport;
use App\Models\User;
use Carbon\Carbon;

/**
 * Phase 4.2 guarantee: the import-attribution trail on the public event
 * page is admin-only. Anonymous and ordinary logged-in users never see
 * uploader names or filenames.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->uploader = User::factory()->create(['name' => 'Riaan Uploader']);
    $this->other = User::factory()->create();

    $this->match = MatchEvent::create([
        'name' => 'Attribution Test',
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

    ScoreImport::create([
        'match_id' => $this->match->id,
        'uploaded_by' => $this->uploader->id,
        'source_type' => 'impact_csv',
        'original_filename' => 'secret-impact-upload.csv',
        'import_status' => 'complete',
    ]);
});

it('hides the import attribution block from anonymous visitors', function () {
    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertDontSee('Import attribution');
    $response->assertDontSee('secret-impact-upload.csv');
    $response->assertDontSee('Riaan Uploader');
});

it('hides the import attribution block from logged-in non-admins', function () {
    $response = $this->actingAs($this->other)->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertDontSee('Import attribution');
    $response->assertDontSee('secret-impact-upload.csv');
});

it('shows the import attribution block to admins', function () {
    $response = $this->actingAs($this->admin)->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Import attribution');
    $response->assertSee('secret-impact-upload.csv');
    $response->assertSee('Riaan Uploader');
});
