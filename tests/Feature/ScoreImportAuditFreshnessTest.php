<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\ScoreImport;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['code' => 'GP'], ['name' => 'Gauteng', 'abbreviation' => 'GP']);
    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'is_active' => true, 'display_order' => 1]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Audit Freshness Match',
        'match_type' => 'PRS',
        'series' => 'PRS',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subWeek(),
        'status' => 'completed',
        'published' => true,
        'active_member_fee' => 500,
        'non_member_fee' => 650,
        'lapsed_member_fee' => 500,
        'created_by' => $this->admin->id,
    ]);
});

it('hides audit rows whose registrations have since been paid or comped', function () {
    // Three pending/unpaid registrations snapshotted at import time.
    $paidLater = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Will Pay Later',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500, 'surcharge_amount' => 0,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 0, 'md_net_amount' => 400,
        'payment_status' => 'paid',   // user has now been mark-settled
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);

    $compedLater = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Will Be Comped',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 0, 'surcharge_amount' => 0,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 0, 'md_net_amount' => -100,
        'payment_status' => 'waived', // user has now been comped
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);

    $stillOwes = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Still Owes',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 650, 'surcharge_amount' => 150,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 25, 'md_net_amount' => 375,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);

    // Simulate the audit snapshot ImpactLiveImportService writes at import time —
    // all three were pending/unpaid when the scores landed.
    $import = ScoreImport::create([
        'match_id' => $this->match->id,
        'source_type' => 'impact_live',
        'original_filename' => 'impact:999',
        'import_status' => 'completed',
        'uploaded_by' => $this->admin->id,
        'raw_payload' => [
            'audit' => [
                ['name' => 'Will Pay Later', 'reason' => 'pending_unpaid', 'registration_id' => $paidLater->id, 'payment_status' => 'pending', 'fee_amount' => 500],
                ['name' => 'Will Be Comped', 'reason' => 'pending_unpaid', 'registration_id' => $compedLater->id, 'payment_status' => 'pending', 'fee_amount' => 500],
                ['name' => 'Still Owes', 'reason' => 'pending_unpaid', 'registration_id' => $stillOwes->id, 'payment_status' => 'pending', 'fee_amount' => 650],
            ],
            'audit_summary' => ['pending_unpaid' => 3, 'walk_in_member' => 0, 'assign_later' => 0, 'skipped_fuzzy' => 0, 'skipped' => 0, 'non_member_stub' => 0],
        ],
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('score-imports.show', $import))
        ->assertOk();

    // Settled rows are gone, only the genuinely-still-outstanding row survives.
    $response->assertSee('Still Owes');
    $response->assertDontSee('Will Pay Later');
    $response->assertDontSee('Will Be Comped');

    // Header chip must reflect the LIVE count — not the stale snapshot of 3.
    $response->assertSee('Unpaid / pending · 1');
    $response->assertDontSee('Unpaid / pending · 3');
});

it('refreshes the Collect R… chip when the live fee has changed since the import', function () {
    $reg = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => User::factory()->create()->id,
        'shooter_name' => 'Price Changed',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500, // live fee is R500 now
        'surcharge_amount' => 0,
        'saprf_fee' => 50, 'platform_fee' => 50, 'gateway_fee' => 0, 'md_net_amount' => 400,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDays(2),
    ]);

    $import = ScoreImport::create([
        'match_id' => $this->match->id,
        'source_type' => 'impact_live',
        'original_filename' => 'impact:1000',
        'import_status' => 'completed',
        'uploaded_by' => $this->admin->id,
        'raw_payload' => [
            'audit' => [[
                'name' => 'Price Changed',
                'reason' => 'pending_unpaid',
                'registration_id' => $reg->id,
                'payment_status' => 'pending',
                'fee_amount' => 650, // snapshot captured R650 (non-member surcharge later removed)
            ]],
            'audit_summary' => ['pending_unpaid' => 1, 'walk_in_member' => 0, 'assign_later' => 0, 'skipped_fuzzy' => 0, 'skipped' => 0, 'non_member_stub' => 0],
        ],
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('score-imports.show', $import))
        ->assertOk();

    // The amber "Collect R…" chip must track the live row, not the frozen fee.
    $response->assertSee('Collect R500.00');
    $response->assertDontSee('Collect R650.00');
});
