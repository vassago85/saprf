<?php

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\Province;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->md = User::factory()->create(['name' => 'Match Director Marco']);
    $this->md->assignRole('match_director');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('takes comp and cash before the match and pays late online entries afterwards', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');

    $match = matchForPayoutPhase($this->md, $this->province, '2026-11-10');
    $online = paidOnlineEntry($match, 'Online Olga', 380);
    $comp = compEntry($match, 'Comp Chris');
    $cash = cashEntry($match, 'Cash Karel');
    $waiting = pendingOnlineEntry($match, 'Waiting Willem');
    $walkIn = walkInEntry($match, 'Walk In Wian');

    $banking = [
        'account_holder' => 'Marco Director',
        'bank_name' => 'Standard Bank',
        'account_number' => '0123456789',
        'branch_code' => '051001',
    ];

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $match), $banking)
        ->assertRedirect();

    $before = Payout::where('match_id', $match->id)->firstOrFail();
    expect($before->payout_phase)->toBe('before_match')
        ->and((float) $before->net_amount)->toBe(180.0)
        ->and(includedRegistrationIds($before))->toEqualCanonicalizing([$online->id, $comp->id, $cash->id]);

    $this->actingAs($this->md)
        ->from(route('matches.request-payout', $match))
        ->post(route('matches.complete-and-request-payout', $match), $banking)
        ->assertRedirect(route('matches.request-payout', $match))
        ->assertSessionHas('error', 'The before-match payout is already requested. The post-match payout opens on match day, for entries that pay after this one.');

    expect(Payout::where('match_id', $match->id)->count())->toBe(1);

    Carbon::setTestNow('2026-11-10 08:00:00');
    markPaidOnline($waiting, 380);

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $match), $banking)
        ->assertRedirect();

    $after = Payout::where('match_id', $match->id)->where('payout_phase', 'after_match')->firstOrFail();
    expect((float) $after->net_amount)->toBe(280.0)
        ->and(includedRegistrationIds($after))->toEqualCanonicalizing([$waiting->id, $walkIn->id]);

    $this->actingAs($this->md)
        ->from(route('matches.finances', $match))
        ->post(route('matches.complete-and-request-payout', $match), $banking)
        ->assertSessionHas('error', 'This match already has its before-match and post-match payouts.');

    expect(Payout::where('match_id', $match->id)->count())->toBe(2);
});

it('includes comp and cash on a post-match payout when no before-match payout was requested', function () {
    Carbon::setTestNow('2026-11-12 09:00:00');

    $match = matchForPayoutPhase($this->md, $this->province, '2026-11-10');
    $online = paidOnlineEntry($match, 'Online Olga', 380);
    $comp = compEntry($match, 'Comp Chris');
    $cash = cashEntry($match, 'Cash Karel');

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $match), [
            'account_holder' => 'Marco Director',
            'bank_name' => 'Standard Bank',
            'account_number' => '0123456789',
            'branch_code' => '051001',
        ])
        ->assertRedirect();

    $payout = Payout::where('match_id', $match->id)->firstOrFail();
    expect($payout->payout_phase)->toBe('after_match')
        ->and((float) $payout->net_amount)->toBe(180.0)
        ->and(includedRegistrationIds($payout))->toEqualCanonicalizing([$online->id, $comp->id, $cash->id]);
});

it('lets a payout raised before this split be followed by one post-match top-up', function () {
    Carbon::setTestNow('2026-11-12 09:00:00');

    $match = matchForPayoutPhase($this->md, $this->province, '2026-11-10');
    $online = paidOnlineEntry($match, 'Online Olga', 380);
    $comp = compEntry($match, 'Comp Chris');
    $waiting = pendingOnlineEntry($match, 'Waiting Willem');

    $legacy = Payout::create([
        'reference' => 'PO-LEGACY-1',
        'payee_type' => 'match_director',
        'payee_user_id' => $this->md->id,
        'match_id' => $match->id,
        'gross_amount' => 500,
        'fees_deducted' => 220,
        'net_amount' => 280,
        'status' => 'paid',
        'created_by' => $this->md->id,
    ]);
    foreach ([$online, $comp] as $registration) {
        PayoutItem::create([
            'payout_id' => $legacy->id,
            'source_type' => 'match_registration',
            'source_id' => $registration->id,
            'match_registration_id' => $registration->id,
            'description' => $registration->shooter_name,
            'shooter_name' => $registration->shooter_name,
            'net_amount' => $registration->md_net_amount,
            'excluded' => false,
        ]);
    }

    markPaidOnline($waiting, 380);

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $match), [
            'account_holder' => 'Marco Director',
            'bank_name' => 'Standard Bank',
            'account_number' => '0123456789',
            'branch_code' => '051001',
        ])
        ->assertRedirect();

    $topUp = Payout::where('match_id', $match->id)->where('id', '!=', $legacy->id)->firstOrFail();
    expect($topUp->payout_phase)->toBe('after_match')
        ->and((float) $topUp->net_amount)->toBe(380.0)
        ->and(includedRegistrationIds($topUp))->toBe([$waiting->id]);
});

function matchForPayoutPhase(User $md, Province $province, string $matchDate): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Two Phase Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => $matchDate,
        'status' => 'closed',
        'active_member_fee' => 500,
        'non_member_fee' => 750,
        'lapsed_member_fee' => 650,
        'created_by' => $md->id,
        'match_director' => $md->name,
    ]);
}

function paidOnlineEntry(MatchEvent $match, string $name, float $mdNet): MatchRegistration
{
    $registration = entryForPayoutPhase($match, $name, [
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 20,
        'md_net_amount' => $mdNet,
        'payment_status' => 'paid',
    ]);
    markPaidOnline($registration, $mdNet);

    return $registration;
}

function markPaidOnline(MatchRegistration $registration, float $mdNet): void
{
    $registration->update([
        'payment_status' => 'paid',
        'md_net_amount' => $mdNet,
    ]);

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $registration->id,
        'user_id' => $registration->user_id,
        'amount' => 500,
        'm_payment_id' => 'REG-'.$registration->id,
        'status' => 'completed',
        'gateway' => 'payfast',
    ]);
}

function compEntry(MatchEvent $match, string $name): MatchRegistration
{
    return entryForPayoutPhase($match, $name, [
        'fee_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => -100,
        'payment_status' => 'waived',
    ]);
}

function cashEntry(MatchEvent $match, string $name): MatchRegistration
{
    return entryForPayoutPhase($match, $name, [
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => -100,
        'payment_status' => 'paid',
    ]);
}

function pendingOnlineEntry(MatchEvent $match, string $name): MatchRegistration
{
    return entryForPayoutPhase($match, $name, [
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 20,
        'md_net_amount' => 380,
        'payment_status' => 'pending',
    ]);
}

function walkInEntry(MatchEvent $match, string $name): MatchRegistration
{
    return entryForPayoutPhase($match, $name, [
        'fee_amount' => 0,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 0,
        'md_net_amount' => -100,
        'payment_status' => 'paid',
        'registration_source' => 'walk_in',
    ]);
}

function entryForPayoutPhase(MatchEvent $match, string $name, array $overrides): MatchRegistration
{
    $shooter = User::factory()->create(['name' => $name]);

    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'surcharge_amount' => 0,
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ], $overrides));
}

function includedRegistrationIds(Payout $payout): array
{
    return $payout->items()
        ->where('excluded', false)
        ->pluck('match_registration_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}
