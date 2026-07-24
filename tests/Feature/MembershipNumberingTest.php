<?php

use App\Models\Membership;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

function makeMembership(?string $saprfNumber, string $type = 'paid'): Membership
{
    $attrs = [
        'user_id' => User::factory()->create()->id,
        'membership_type' => $type,
        'status' => 'active',
    ];

    if ($saprfNumber !== null) {
        $attrs['saprf_number'] = $saprfNumber;
    }

    return Membership::create($attrs);
}

it('picks the next integer after the highest legacy number, ignoring non-numeric values', function () {
    makeMembership('1752');
    makeMembership('2025');
    makeMembership('SAPRF-IMPORT-ABC123', 'free');
    makeMembership('SAPRF-2026-0500');

    expect(Membership::nextSaprfNumber())->toBe('2026');
});

it('auto-assigns the next legacy number when none is provided', function () {
    makeMembership('308');

    $created = makeMembership(null);

    expect($created->saprf_number)->toBe('309');
});

it('preserves an explicitly provided number', function () {
    $created = makeMembership('SAPRF-IMPORT-XYZ', 'free');

    expect($created->saprf_number)->toBe('SAPRF-IMPORT-XYZ');
});

it('handles leading zeros on legacy numbers', function () {
    makeMembership('0165');

    expect(Membership::nextSaprfNumber())->toBe('166');
});

it('starts at 1 when there are no numeric memberships', function () {
    makeMembership('SAPRF-IMPORT-AAA', 'free');

    expect(Membership::nextSaprfNumber())->toBe('1');
});
