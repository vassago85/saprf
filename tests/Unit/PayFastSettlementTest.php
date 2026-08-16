<?php

use App\Models\Payment;

it('treats a negative PayFast amount_fee as a positive cost', function () {
    $settlement = Payment::settlementFromItn([
        'amount_gross' => '200.00',
        'amount_fee' => '-4.60',
        'amount_net' => '195.40',
    ]);

    expect($settlement['gross'])->toBe(200.00)
        ->and($settlement['fee'])->toBe(4.60)
        ->and($settlement['net'])->toBe(195.40);
});

it('returns nulls when PayFast omits settlement fields', function () {
    expect(Payment::settlementFromItn([]))->toBe([
        'gross' => null,
        'fee' => null,
        'net' => null,
    ]);
});
