<?php

use App\Services\PayFastService;
use App\Services\SettingsService;

/**
 * Live-mode service (sandbox disabled) with hostname resolution turned off so
 * IP checks exercise only the configured CIDR/single-IP ranges — no DNS calls.
 */
function payFastLiveService(): PayFastService
{
    config([
        'payfast.sandbox' => false,
        'payfast.valid_hosts' => [],
        'payfast.valid_ip_ranges' => [
            '197.97.145.144/28',
            '41.74.179.192/27',
            '102.216.36.0/28',
            '102.216.36.128/28',
            '144.126.193.139',
        ],
    ]);

    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('get')->andReturn(null);

    return new PayFastService($settings);
}

it('accepts ITN source IPs inside PayFast extended ranges', function (string $ip) {
    expect(payFastLiveService()->validateItnServerIp($ip))->toBeTrue();
})->with([
    'start of /28' => '197.97.145.144',
    'end of /28' => '197.97.145.159',
    'inside /27' => '41.74.179.200',
    'end of /27' => '41.74.179.223',
    'second /28 block start' => '102.216.36.128',
    'single redundancy IP' => '144.126.193.139',
]);

it('rejects ITN source IPs outside PayFast ranges in live mode', function (string $ip) {
    expect(payFastLiveService()->validateItnServerIp($ip))->toBeFalse();
})->with([
    'just below /28' => '197.97.145.143',
    'just above /28' => '197.97.145.160',
    'just above /27' => '41.74.179.224',
    'gap between /28 blocks' => '102.216.36.16',
    'off-by-one single IP' => '144.126.193.140',
    'unrelated public IP' => '8.8.8.8',
]);

it('always accepts ITN source IPs in sandbox mode', function () {
    config(['payfast.sandbox' => true]);

    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('get')->andReturn(null);

    expect((new PayFastService($settings))->validateItnServerIp('8.8.8.8'))->toBeTrue();
});
