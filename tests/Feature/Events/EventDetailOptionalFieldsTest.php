<?php

declare(strict_types=1);

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

/**
 * Phase 3.3 guarantee: the four optional detail cards (venue notes,
 * schedule, squadding, attachments) only render when their underlying
 * column is populated. An unpopulated match should look exactly like it
 * did before Phase 3.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->director = User::factory()->create();
    $this->director->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Optional Fields Test',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::tomorrow()->toDateString(),
        'status' => 'open',
        'published' => true,
        'created_by' => $this->director->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
});

it('does not render any optional detail card when every field is null', function () {
    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertDontSee('Venue Details');
    $response->assertDontSee('Schedule');
    $response->assertDontSee('Squadding');
    $response->assertDontSee('Attachments');
});

it('renders the venue details card when venue_details is set', function () {
    $this->match->update([
        'venue_details' => 'Parking available at the north gate. Overnight camping R100/night.',
    ]);

    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Venue Details');
    $response->assertSee('north gate');
});

it('renders the schedule card and drops empty rows', function () {
    $this->match->update([
        'schedule_json' => [
            ['time' => '07:30', 'label' => 'Registration & briefing'],
            ['time' => '', 'label' => ''], // junk row — should be filtered out
            ['time' => '08:00', 'label' => 'Squad 1 to bay 1'],
        ],
    ]);

    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Schedule');
    // Blade escapes the ampersand to &amp;, so look for the escaped form.
    $response->assertSee('Registration &amp; briefing', escape: false);
    $response->assertSee('07:30');
    $response->assertSee('08:00');

    // The empty row should not leak into the DOM — assert the accessor
    // dropped it so the view loop never saw it.
    expect(count($this->match->schedule_items))->toBe(2);
});

it('renders the squadding card with per-squad member counts', function () {
    $this->match->update([
        'squadding_json' => [
            ['squad_name' => 'Squad A', 'members' => ['Japie Louw', 'Sarah de Beer']],
            ['squad_name' => '', 'members' => []], // junk row
        ],
    ]);

    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Squadding');
    $response->assertSee('Squad A');
    $response->assertSee('Japie Louw');
    $response->assertSee('Sarah de Beer');

    expect(count($this->match->squadding_items))->toBe(1);
});

it('renders the attachments card and skips rows without a url', function () {
    $this->match->update([
        'attachments_json' => [
            ['label' => 'Course of fire', 'url' => 'https://example.com/cof.pdf'],
            ['label' => 'Rules (no url)', 'url' => ''], // url missing — gets dropped
        ],
    ]);

    $response = $this->get('/events/'.$this->match->id);

    $response->assertOk();
    $response->assertSee('Attachments');
    $response->assertSee('Course of fire');
    $response->assertSee('https://example.com/cof.pdf', escape: false);
    $response->assertDontSee('Rules (no url)');

    expect(count($this->match->attachment_items))->toBe(1);
});
