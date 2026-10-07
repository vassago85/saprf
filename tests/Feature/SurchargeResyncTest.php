<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
});

function surchargeMatch(string $status, int $province, int $creator): MatchEvent
{
    return MatchEvent::create([
        'name' => "Surcharge {$status}",
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province,
        'match_date' => Carbon::today()->addMonth(),
        'status' => $status,
        'active_member_fee' => 650,
        'non_member_fee' => 850,
        'lapsed_member_fee' => 750,
        'created_by' => $creator,
    ]);
}

it('resyncs stored non-member and lapsed fees on open matches when surcharges change', function () {
    $open = surchargeMatch('open', $this->province->id, $this->owner->id);
    $completed = surchargeMatch('completed', $this->province->id, $this->owner->id);

    $this->actingAs($this->owner)
        ->put(route('site-settings.update'), [
            'non_member_surcharge' => '100',
            'lapsed_member_surcharge' => '50',
            'withdrawal_admin_fee' => '100',
            'withdrawal_deadline_hours' => '72',
            'division_single_select' => '1',
            'saprf_fee_type' => 'fixed',
            'saprf_fee_value' => '50',
            'membership_platform_fee_pct' => '2.5',
            'estimated_gateway_fee_percentage' => '3.5',
            'estimated_gateway_flat_fee' => '2.00',
            'payfast_sandbox' => '1',
            'payments_enabled' => '1',
            'notifications_enabled' => '1',
        ])
        ->assertRedirect(route('site-settings.index'));

    expect((float) $open->fresh()->non_member_fee)->toBe(750.0)
        ->and((float) $open->fresh()->lapsed_member_fee)->toBe(700.0)
        ->and((float) $completed->fresh()->non_member_fee)->toBe(850.0)
        ->and((float) $completed->fresh()->lapsed_member_fee)->toBe(750.0);
});
