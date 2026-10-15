<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Regression harness for 2026_10_15_150300_add_role_and_team_to_national_team_appearances.
 *
 * The production rollout failed because the backfill:
 *   (a) left half-eaten UTF-8 bytes in `division_label` after a byte-mode
 *       regex split through the middle of an em-dash (U+2014);
 *   (b) wasn't re-runnable after the half-apply, so the entrypoint retry
 *       loop crashed on "Duplicate column name 'role'".
 *
 * These tests pin down both failure modes against a reset database.
 */

use App\Models\Division;
use App\Models\Membership;
use App\Models\User;

// The RefreshDatabase trait inherited from TestCase already applies the
// migration before each test. We insert legacy-shaped rows AFTER that and
// re-run only the backfill's up() to verify both correctness and idempotence.

function seedCaptainRowWithEmDash(): int
{
    $open = Division::create([
        'slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true,
    ]);

    $u = User::factory()->create();
    Membership::create([
        'user_id' => $u->id, 'saprf_number' => '9000',
        'membership_type' => 'paid', 'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // Insert WITHOUT touching the Eloquent model so we exercise the raw
    // migration backfill the same way the legacy prod rows do.
    return DB::table('national_team_appearances')->insertGetId([
        'user_id' => $u->id,
        'year' => 2026,
        'division_id' => $open->id,
        'division_label' => "Open \u{2014} Team Captain", // em-dash
        'role' => 'shooter',
        'championship_name' => 'Worlds 2026',
        'awarded_colours' => true,
        'appeared_at' => '2026-09-23',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('the role backfill promotes an em-dash captain label to role=captain + ASCII base label', function () {
    $id = seedCaptainRowWithEmDash();

    // The migration already ran as part of migrate:fresh in beforeEach.
    // Re-run only the UP() of this migration manually to assert its effect
    // on the row we just inserted (which was inserted AFTER migrate:fresh).
    $migration = require database_path('migrations/2026_10_15_150300_add_role_and_team_to_national_team_appearances.php');
    $migration->up();

    $row = DB::table('national_team_appearances')->where('id', $id)->first();

    expect($row->role)->toBe('captain');
    expect($row->division_label)->toBe('Open');

    // Byte-level invariant: no stray multi-byte sequence hiding in there.
    expect(preg_match('//u', $row->division_label))->toBe(1); // valid UTF-8
    expect($row->division_label)->toMatch('/^[\x20-\x7E]+$/'); // pure ASCII
});

it('the role backfill promotes a Team Manager label to role=manager', function () {
    $u = User::factory()->create();
    Membership::create([
        'user_id' => $u->id, 'saprf_number' => '9001',
        'membership_type' => 'paid', 'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    $id = DB::table('national_team_appearances')->insertGetId([
        'user_id' => $u->id,
        'year' => 2026,
        'division_id' => null,
        'division_label' => 'Team Manager',
        'role' => 'shooter',
        'championship_name' => 'Worlds 2026',
        'awarded_colours' => false,
        'appeared_at' => '2026-09-23',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_15_150300_add_role_and_team_to_national_team_appearances.php');
    $migration->up();

    $row = DB::table('national_team_appearances')->where('id', $id)->first();

    expect($row->role)->toBe('manager');
    expect($row->division_label)->toBe('Team Manager');
});

it('the migration is idempotent — re-running it on an already-migrated DB is a no-op', function () {
    // Already applied by migrate:fresh. Running up() again must not crash
    // on "Duplicate column" and must not re-process rows.
    $migration = require database_path('migrations/2026_10_15_150300_add_role_and_team_to_national_team_appearances.php');

    // These two calls were the failure signature on prod:
    //   SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'role'
    expect(fn () => $migration->up())->not->toThrow(Throwable::class);
    expect(fn () => $migration->up())->not->toThrow(Throwable::class);

    expect(Schema::hasColumn('national_team_appearances', 'role'))->toBeTrue();
    expect(Schema::hasColumn('national_team_appearances', 'national_team_id'))->toBeTrue();
});

it('the migration links appearances to NationalTeam by (year, championship_name)', function () {
    $open = Division::create([
        'slug' => 'open', 'name' => 'Open', 'display_order' => 1, 'is_active' => true,
    ]);
    $u = User::factory()->create();
    Membership::create([
        'user_id' => $u->id, 'saprf_number' => '9002',
        'membership_type' => 'paid', 'status' => 'active', 'payment_status' => 'paid',
        'start_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    ]);

    // Insert an appearance that migrate:fresh never saw.
    $id = DB::table('national_team_appearances')->insertGetId([
        'user_id' => $u->id,
        'year' => 2025,
        'division_id' => $open->id,
        'division_label' => 'Open',
        'role' => 'shooter',
        'championship_name' => 'Worlds 2025',
        'host_country' => 'France',
        'awarded_colours' => true,
        'appeared_at' => '2025-09-10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_15_150300_add_role_and_team_to_national_team_appearances.php');
    $migration->up();

    $row = DB::table('national_team_appearances')->where('id', $id)->first();
    expect($row->national_team_id)->not->toBeNull();

    $team = DB::table('national_teams')->where('id', $row->national_team_id)->first();
    expect($team->year)->toEqual(2025);
    expect($team->championship_name)->toBe('Worlds 2025');
});
