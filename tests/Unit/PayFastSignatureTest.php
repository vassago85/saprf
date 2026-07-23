<?php

use App\Services\PayFastService;
use App\Services\SettingsService;

function payFastService(): PayFastService
{
    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('get')->andReturn(null);

    return new PayFastService($settings);
}

/**
 * Signature must match PayFast's documented algorithm exactly.
 *
 * @see https://developers.payfast.co.za/docs#step_2_signature
 */
it('matches the PayFast documented sandbox signature for a known payload', function () {
    $service = payFastService();

    $data = [
        'merchant_id' => '10000100',
        'merchant_key' => '46f0cd694581a',
        'return_url' => 'http://www.yourdomain.co.za/return.php',
        'cancel_url' => 'http://www.yourdomain.co.za/cancel.php',
        'notify_url' => 'http://www.yourdomain.co.za/notify.php',
        'name_first' => 'First Name',
        'name_last' => 'Last Name',
        'email_address' => 'test@test.com',
        'm_payment_id' => '1234',
        'amount' => '10.00',
        'item_name' => 'Order#123',
    ];

    // Independently compute using PayFast's published PHP sample.
    $pfOutput = '';
    foreach ($data as $key => $val) {
        if ($val !== '') {
            $pfOutput .= $key.'='.urlencode(trim($val)).'&';
        }
    }
    $getString = substr($pfOutput, 0, -1).'&passphrase='.urlencode(trim('jt7NOE43FZPn'));
    $expected = md5($getString);

    expect($service->generateSignature($data, 'jt7NOE43FZPn'))->toBe($expected);
});

it('excludes blank fields from the signature string', function () {
    $service = payFastService();

    $withBlank = [
        'merchant_id' => '10000100',
        'merchant_key' => '46f0cd694581a',
        'name_first' => 'Jane',
        'name_last' => '',
        'amount' => '50.00',
        'item_name' => 'Test',
    ];

    $withoutBlank = [
        'merchant_id' => '10000100',
        'merchant_key' => '46f0cd694581a',
        'name_first' => 'Jane',
        'amount' => '50.00',
        'item_name' => 'Test',
    ];

    expect($service->generateSignature($withBlank, 'jt7NOE43FZPn'))
        ->toBe($service->generateSignature($withoutBlank, 'jt7NOE43FZPn'));
});

it('does not append passphrase when it is empty', function () {
    $service = payFastService();

    $data = [
        'merchant_id' => '10000100',
        'amount' => '10.00',
        'item_name' => 'Test',
    ];

    $withEmpty = $service->generateSignature($data, '');

    // Empty passphrase must not add "&passphrase=" (would break the hash).
    expect($withEmpty)->toBe(md5('merchant_id=10000100&amount=10.00&item_name=Test'));
});
