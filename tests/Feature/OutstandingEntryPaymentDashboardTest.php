<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use App\Support\Dashboard\Shooter\AttentionBuilder;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->shooter = User::factory()->create(['email_verified_at' => now()]);
    $this->shooter->assignRole('member');

    Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true]
    );

    $this->match = MatchEvent::create([
        'name' => 'Warrior Grind Unpaid',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => (string) now()->year,
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->shooter->id,
        'active_member_fee' => 300,
        'non_member_fee' => 300,
        'lapsed_member_fee' => 300,
    ]);
});

it('surfaces unpaid match entries on the shooter attention list with a Pay now CTA', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => $this->shooter->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 300,
        'saprf_fee' => 50,
        'platform_fee' => 20,
        'gateway_fee' => 0,
        'md_net_amount' => 230,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subWeek(),
    ]);

    $items = app(AttentionBuilder::class)->build($this->shooter, ['PR22']);

    $unpaid = collect($items)->first(fn ($i) => str_contains($i['title'], 'Unpaid'));

    expect($unpaid)->not->toBeNull()
        ->and($unpaid['href_label'])->toBe('Pay now')
        ->and($unpaid['href'])->toBe(route('invoices.index'))
        ->and($unpaid['body'])->toContain('portal');
});

it('lists outstanding unpaid registrations on the invoices page with a pay link', function () {
    $registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->shooter->id,
        'shooter_name' => $this->shooter->name,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 300,
        'saprf_fee' => 50,
        'platform_fee' => 20,
        'gateway_fee' => 0,
        'md_net_amount' => 230,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now()->subWeek(),
    ]);

    $this->actingAs($this->shooter)
        ->get(route('invoices.index'))
        ->assertOk()
        ->assertSee('Payment outstanding')
        ->assertSee($this->match->name)
        ->assertSee('Pay now')
        ->assertSee(route('registrations.show', $registration), false);
});
