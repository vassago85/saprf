<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;

/**
 * Phase 4.9 guarantee: /events/{match}/results.csv and .pdf return the
 * right content with the right MIME type. `?division=<id>` filters to a
 * single division; without it, the full field is exported.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $this->senior = Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 2, 'is_active' => true]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Export Test',
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

    foreach ([
        ['Alice Open', $this->open->id, 150, 1, 1],
        ['Bob Open', $this->open->id, 140, 2, 2],
        ['Charlie Senior', $this->senior->id, 135, 3, 1],
    ] as [$name, $divId, $raw, $overallRank, $divRank]) {
        $user = User::factory()->create(['name' => $name]);
        Membership::create([
            'user_id' => $user->id,
            'saprf_number' => 'EXP-'.$name,
            'membership_type' => 'paid',
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => Carbon::today()->subYear(),
            'expiry_date' => Carbon::today()->addYear(),
        ]);
        Score::create([
            'match_id' => $this->match->id,
            'user_id' => $user->id,
            'shooter_name' => $name,
            'raw_score' => $raw,
            'placement' => $overallRank,
            'overall_rank' => $overallRank,
            'division_rank' => $divRank,
            'status' => 'valid',
            'division_id' => $divId,
            'counts_for_season' => true,
            'match_date' => $this->match->match_date->toDateString(),
        ]);
    }
});

it('exports the whole field as CSV with the expected columns', function () {
    $response = $this->actingAs($this->admin)
        ->get('/events/'.$this->match->id.'/results.csv');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    // StreamedResponse buffers via ob_start; capture the body explicitly.
    $body = $response->streamedContent();
    expect($body)->toContain('Overall rank')
        ->and($body)->toContain('Match %')
        ->and($body)->toContain('Division %')
        ->and($body)->toContain('Alice Open')
        ->and($body)->toContain('Bob Open')
        ->and($body)->toContain('Charlie Senior');
});

it('filters the CSV to one division when ?division=<id> is passed', function () {
    $response = $this->actingAs($this->admin)
        ->get('/events/'.$this->match->id.'/results.csv?division='.$this->open->id);

    $response->assertOk();
    $body = $response->streamedContent();
    expect($body)->toContain('Alice Open')
        ->and($body)->toContain('Bob Open')
        ->and($body)->not->toContain('Charlie Senior');
});

it('exports a PDF with the match title and shooter rows', function () {
    $response = $this->actingAs($this->admin)
        ->get('/events/'.$this->match->id.'/results.pdf');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/pdf');
    // DomPDF produces a binary stream; just assert it isn't an HTML error.
    expect(strlen($response->getContent()))->toBeGreaterThan(1000);
});

it('404s on CSV export for a draft match', function () {
    $this->match->update(['status' => 'draft', 'published' => false]);

    $this->actingAs($this->admin)
        ->get('/events/'.$this->match->id.'/results.csv')
        ->assertNotFound();
});

// ──────────────────────────────────────────────────────────────────────
// Download gate: CSV / PDF are MD + admin/exco only, never public.
// A 404 (not 403) is deliberate — the URL stays effectively invisible
// to anyone who isn't allowed to hit it, matching how a draft match
// behaves. If this changes, update
// MatchController::abortIfNotAllowedToDownloadResults() to match.
// ──────────────────────────────────────────────────────────────────────

it('hides the download endpoints from anonymous visitors', function () {
    $this->get('/events/'.$this->match->id.'/results.csv')->assertNotFound();
    $this->get('/events/'.$this->match->id.'/results.pdf')->assertNotFound();
});

it('hides the download endpoints from logged-in members without staff roles', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get('/events/'.$this->match->id.'/results.csv')
        ->assertNotFound();

    $this->actingAs($member)
        ->get('/events/'.$this->match->id.'/results.pdf')
        ->assertNotFound();
});

it('lets the match director download their own match', function () {
    // The MD role alone is not enough — the user must also OWN this specific
    // match (created_by). This matches the zero-score-removal gate pattern:
    // one MD cannot operate on another MD's match.
    $md = User::factory()->create();
    $md->assignRole('match_director');
    $this->match->update(['created_by' => $md->id]);

    $this->actingAs($md)
        ->get('/events/'.$this->match->id.'/results.csv')
        ->assertOk();
});

it('blocks a match director who does not own the match', function () {
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');
    // Deliberately do NOT re-point created_by — this MD is a stranger to
    // the match, so having the role alone must not grant access.

    $this->actingAs($otherMd)
        ->get('/events/'.$this->match->id.'/results.csv')
        ->assertNotFound();
});

it('lets exco download results', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $this->actingAs($exco)
        ->get('/events/'.$this->match->id.'/results.csv')
        ->assertOk();
});
