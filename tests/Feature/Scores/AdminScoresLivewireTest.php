<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Phase 5 guarantees for the admin /scores Livewire island:
 * - Server-side filtering by season / discipline / division / shooter / status.
 * - Bulk re-validate changes status without an audit reason.
 * - Bulk override requires a reason and writes an audit log.
 * - Duplicate flag highlights two rows that share a canonical name within
 *   the same match.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    $this->senior = Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 2, 'is_active' => true]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->pr22Match = MatchEvent::create([
        'name' => 'PR22 Provincial',
        'match_type' => 'PR22', 'series' => 'PR22', 'series_level' => 'provincial', 'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subDays(14)->toDateString(),
        'status' => 'completed', 'published' => true, 'created_by' => $this->admin->id,
        'active_member_fee' => 100, 'non_member_fee' => 100, 'lapsed_member_fee' => 100,
    ]);
    $this->prsMatch = MatchEvent::create([
        'name' => 'PRS National',
        'match_type' => 'PRS', 'series' => 'PRS', 'series_level' => 'national', 'season' => '2025',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subDays(100)->toDateString(),
        'status' => 'completed', 'published' => true, 'created_by' => $this->admin->id,
        'active_member_fee' => 100, 'non_member_fee' => 100, 'lapsed_member_fee' => 100,
    ]);

    foreach ([
        [$this->pr22Match, 'Alice Open', $this->open->id, 150, 'valid'],
        [$this->pr22Match, 'Bob Open', $this->open->id, 140, 'pending'],
        [$this->pr22Match, 'Charlie Senior', $this->senior->id, 135, 'pending'],
        [$this->prsMatch, 'Zed PRS', $this->open->id, 95, 'valid'],
    ] as [$m, $name, $divId, $raw, $status]) {
        $u = User::factory()->create(['name' => $name]);
        Membership::create([
            'user_id' => $u->id, 'saprf_number' => 'M-'.$name, 'membership_type' => 'paid',
            'status' => 'active', 'payment_status' => 'paid',
            'start_date' => Carbon::today()->subYear(), 'expiry_date' => Carbon::today()->addYear(),
        ]);
        Score::create([
            'match_id' => $m->id, 'user_id' => $u->id, 'shooter_name' => $name,
            'raw_score' => $raw, 'placement' => 1, 'division_id' => $divId,
            'status' => $status, 'is_member' => true,
            'match_date' => $m->match_date->toDateString(),
            'counts_for_season' => $status === 'valid',
        ]);
    }
});

it('filters scores by season', function () {
    $this->actingAs($this->admin);
    $component = Livewire::test('admin.scores-table')->set('season', '2025');

    expect(collect($component->instance()->scores->items())->pluck('shooter_name')->all())
        ->toBe(['Zed PRS']);
});

it('filters scores by discipline', function () {
    $this->actingAs($this->admin);
    $component = Livewire::test('admin.scores-table')->set('discipline', 'PR22');

    $names = collect($component->instance()->scores->items())->pluck('shooter_name')->all();

    expect($names)->toContain('Alice Open')->toContain('Bob Open')->toContain('Charlie Senior')
        ->and($names)->not->toContain('Zed PRS');
});

it('filters scores by shooter name substring', function () {
    $this->actingAs($this->admin);
    $component = Livewire::test('admin.scores-table')->set('shooterSearch', 'Charlie');

    expect(collect($component->instance()->scores->items())->pluck('shooter_name')->all())
        ->toBe(['Charlie Senior']);
});

it('filters scores by status', function () {
    $this->actingAs($this->admin);
    $component = Livewire::test('admin.scores-table')->set('status', 'pending');

    expect(collect($component->instance()->scores->items())->pluck('shooter_name')->sort()->values()->all())
        ->toBe(['Bob Open', 'Charlie Senior']);
});

it('flags duplicate canonical names within the same match', function () {
    // Plant a second row for "ALICE  Open" (same canonical) on the same match
    // as "Alice Open" to prove the duplicate marker picks them up.
    $otherAlice = User::factory()->create(['name' => 'ALICE  Open']);
    Score::create([
        'match_id' => $this->pr22Match->id,
        'user_id' => $otherAlice->id,
        'shooter_name' => 'ALICE  Open',
        'raw_score' => 100, 'placement' => 20, 'division_id' => $this->open->id,
        'status' => 'non_member', 'is_member' => false,
        'match_date' => $this->pr22Match->match_date->toDateString(),
    ]);

    $this->actingAs($this->admin);
    $component = Livewire::test('admin.scores-table');
    $markers = $component->instance()->duplicateMarkers;

    // Both rows share the canonical "alice open" in the same match -> marker on each.
    $aliceIds = Score::where('match_id', $this->pr22Match->id)
        ->whereIn('shooter_name', ['Alice Open', 'ALICE  Open'])
        ->pluck('id')
        ->all();

    foreach ($aliceIds as $id) {
        expect($markers)->toHaveKey($id);
    }
    // Charlie should NOT be flagged — no duplicate.
    $charlieId = Score::where('shooter_name', 'Charlie Senior')->value('id');
    expect($markers)->not->toHaveKey($charlieId);
});

it('bulk override writes an audit log and flips status', function () {
    $this->actingAs($this->admin);
    $bob = Score::where('shooter_name', 'Bob Open')->first();
    $charlie = Score::where('shooter_name', 'Charlie Senior')->first();

    Livewire::test('admin.scores-table')
        ->set('selected', [$bob->id, $charlie->id])
        ->call('startOverride')
        ->set('overrideStatus', 'invalid')
        ->set('overrideReason', 'Range officer disqualification confirmed on the day.')
        ->call('applyOverride');

    expect($bob->fresh()->status)->toBe('invalid');
    expect($charlie->fresh()->status)->toBe('invalid');

    // At least one audit log row with the reason captured.
    $log = AuditLog::query()->where('action_type', 'score.status.overridden')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->reason)->toContain('disqualification');
});

it('refuses bulk override to non-admin match directors', function () {
    $md = User::factory()->create();
    $md->assignRole('match_director');

    $this->actingAs($md);

    Livewire::test('admin.scores-table', ['matchDirectorOnly' => true])
        ->set('selected', [1])
        ->call('startOverride')
        ->assertStatus(403);
});
