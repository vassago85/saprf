<?php

/**
 * Coverage for the entrant-list collapse behaviour on the public events
 * page.
 *
 * The panel:
 *   - stays expanded by default while the match is still upcoming/open,
 *   - starts collapsed once the match is completed and has scores, so the
 *     scores section is what the public sees first,
 *   - stays expanded for admins / match directors regardless of state so
 *     they can reconcile at a glance,
 *   - is hidden entirely for imported historic events that have scores but
 *     no registration data (existing behaviour, must not regress),
 *   - carries a small reconciliation chip (N scored / K no-shows / W walk-ins)
 *     once results are up.
 */

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
});

function collapseTestMatch(Province $province, string $status = 'open', bool $future = true): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Collapse Test Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => $future ? Carbon::today()->addMonth() : Carbon::today()->subDay(),
        'status' => $status,
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'created_by' => User::factory()->create()->id,
        'published' => true,
    ]);
}

function collapseTestRegisterShooter(MatchEvent $match, string $name, Division $division, string $status = 'confirmed'): MatchRegistration
{
    $user = User::factory()->create(['name' => $name]);

    return MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'payment_status' => 'paid',
        'registration_status' => $status,
        'division_id' => $division->id,
        'registered_at' => now(),
    ]);
}

function collapseTestScore(MatchEvent $match, User $user, Division $division): Score
{
    return Score::create([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'division_id' => $division->id,
        'raw_score' => 80,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $match->match_date,
        'counts_for_log' => true,
        'counts_for_season' => true,
    ]);
}

it('starts the entrant panel collapsed by default for an upcoming match', function () {
    $match = collapseTestMatch($this->province);
    collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->toContain('x-data="{ open: false }"')
        // The name is still rendered inside the collapsed panel so the DOM
        // stays searchable and Alpine can reveal it on click.
        ->toContain('Alice Zulu');
});

it('starts the entrant panel collapsed for the public once the match is completed and scored', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);
    $reg = collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);
    collapseTestScore($match, $reg->user, $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->toContain('x-data="{ open: false }"');
});

it('starts the entrant panel collapsed for admins too — the toggle is always the way in', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);
    $reg = collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);
    collapseTestScore($match, $reg->user, $this->open);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->toContain('x-data="{ open: false }"');
});

it('starts the entrant panel collapsed for match directors too', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);
    $reg = collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);
    collapseTestScore($match, $reg->user, $this->open);

    $md = User::factory()->create();
    $md->assignRole('match_director');

    $html = $this->actingAs($md)->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->toContain('x-data="{ open: false }"');
});

it('shows a reconciliation chip with scored / no-show counts once results are up', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);

    $shooterA = collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);
    $shooterB = collapseTestRegisterShooter($match, 'Bob Alpha', $this->open);
    $shooterC = collapseTestRegisterShooter($match, 'Cara Mid', $this->open);

    // Two of three scored; one no-show.
    collapseTestScore($match, $shooterA->user, $this->open);
    collapseTestScore($match, $shooterB->user, $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->toContain('2 scored')
        ->toContain('1 no-show');
});

it('does not show walk-in count when there are no walk-ins', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);
    $reg = collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);
    collapseTestScore($match, $reg->user, $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->not->toContain('walk-in');
});

it('shows a walk-in count when a scored shooter has no registration', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);

    $registered = collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);
    collapseTestScore($match, $registered->user, $this->open);

    // Walk-in: score exists but user is not on the entry list.
    $walkIn = User::factory()->create(['name' => 'Walk-In Wanda']);
    collapseTestScore($match, $walkIn, $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->toContain('1 walk-in');
});

it('hides the entrant panel entirely for imported historic matches that have scores but no entries', function () {
    $match = collapseTestMatch($this->province, status: 'completed', future: false);
    // No registrations at all — only scores. Simulates a scraped/imported match.
    $shooter = User::factory()->create(['name' => 'Alice Zulu']);
    collapseTestScore($match, $shooter, $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    // Panel wrapper is dropped entirely — no "Entry List" heading, no toggle.
    expect($html)->not->toContain('Entry List');
});

it('does not render the reconciliation chip when the match is not yet scored', function () {
    $match = collapseTestMatch($this->province);
    collapseTestRegisterShooter($match, 'Alice Zulu', $this->open);

    $html = $this->get(route('events.show', $match))->assertOk()->getContent();

    expect($html)->not->toContain('scored')
        ->not->toContain('no-show');
});
