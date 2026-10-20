<?php

use App\Models\CharsleyInvoice;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceNinja\CharsleyInvoiceSettings;
use App\Services\InvoiceNinja\InvoiceNinjaException;
use App\Services\InvoiceNinja\IssueCharsleyInvoice;
use App\Services\SettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    seedRoles();

    $this->director = User::factory()->create();
    $this->match = MatchEvent::create([
        'name' => 'Charsley Month Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'match_date' => '2026-09-20',
        'status' => 'open',
        'created_by' => $this->director->id,
    ]);
});

function configureCharsleyInvoicing(bool $enabled = true, ?string $entryFee = null): void
{
    $settings = app(SettingsService::class);
    $settings->set('invoice_ninja_enabled', $enabled ? '1' : '0', 'test');
    $settings->set('invoice_ninja_api_url', 'https://invoice.charsley.co.za', 'test');
    $settings->set('invoice_ninja_api_token', 'test-token', 'test');
    $settings->set('invoice_ninja_client_id', 'SaprfClient', 'test');

    if ($entryFee !== null) {
        $settings->set('invoice_ninja_entry_fee', $entryFee, 'test');
    }
}

function charsleyEntry(MatchEvent $match, array $extra = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'shooter_name' => 'Paid Shooter',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => '2026-09-15 10:00:00',
    ], $extra));
}

function fakeInvoiceNinja(int $emailStatus = 200): void
{
    Http::fake([
        'https://invoice.charsley.co.za/api/v1/invoices/bulk' => Http::response([
            'message' => $emailStatus === 200 ? 'Success' : 'Client has no email',
        ], $emailStatus),
        'https://invoice.charsley.co.za/api/v1/invoices' => Http::response([
            'data' => ['id' => 'inv_1', 'number' => '0042'],
        ], 200),
        'https://invoice.charsley.co.za/api/v1/clients/*' => Http::response([
            'data' => ['id' => 'SaprfClient', 'display_name' => 'South African Precision Rifle Federation'],
        ], 200),
    ]);
}

test('the monthly invoice is the configured fee for each paid entry', function () {
    charsleyEntry($this->match);
    charsleyEntry($this->match, ['shooter_name' => 'Walk-in', 'fee_amount' => 0]);
    charsleyEntry($this->match, ['registered_at' => '2026-09-30 23:59:59']);
    charsleyEntry($this->match, ['payment_status' => 'pending']);
    charsleyEntry($this->match, ['payment_status' => 'failed']);
    charsleyEntry($this->match, ['payment_status' => 'unpaid', 'fee_amount' => 0]);
    charsleyEntry($this->match, ['payment_status' => 'waived']);
    charsleyEntry($this->match, ['payment_status' => 'refunded']);
    charsleyEntry($this->match, ['registered_at' => '2026-08-31 23:00:00']);
    charsleyEntry($this->match, ['registered_at' => '2026-10-01 00:00:00']);

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);

    expect(app(CharsleyInvoiceSettings::class)->clientId())->toBe('')
        ->and(app(CharsleyInvoiceSettings::class)->entryFee())->toBe(50.0)
        ->and($preview['entries'])->toBe(3)
        ->and($preview['entry_fee'])->toBe(50.0)
        ->and($preview['total'])->toBe(150.0);
});

test('a changed entry fee is used instead of the default', function () {
    configureCharsleyInvoicing(entryFee: '80');
    charsleyEntry($this->match);
    charsleyEntry($this->match);

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);

    expect($preview['entry_fee'])->toBe(80.0)
        ->and($preview['total'])->toBe(160.0);
});

test('a month with no paid entries sends nothing', function () {
    configureCharsleyInvoicing();
    fakeInvoiceNinja();

    $invoice = app(IssueCharsleyInvoice::class)->issue(2026, 7);

    expect($invoice)->toBeNull()
        ->and(CharsleyInvoice::count())->toBe(0);

    Http::assertNothingSent();
});

test('the invoice is created and emailed once for the month', function () {
    configureCharsleyInvoicing();
    fakeInvoiceNinja();
    charsleyEntry($this->match);
    charsleyEntry($this->match, ['fee_amount' => 0, 'shooter_name' => 'Walk-in']);

    $issuer = app(IssueCharsleyInvoice::class);
    $first = $issuer->issue(2026, 9);
    $second = $issuer->issue(2026, 9);

    expect($first->status)->toBe('sent')
        ->and($first->invoice_number)->toBe('0042')
        ->and($second->id)->toBe($first->id)
        ->and(CharsleyInvoice::count())->toBe(1);

    $creates = collect(Http::recorded())->filter(
        fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/api/v1/invoices')
    );
    expect($creates)->toHaveCount(1);

    $request = $creates->first()[0];
    $payload = $request->data();
    $item = $payload['line_items'][0];

    expect($payload['line_items'])->toHaveCount(1)
        ->and($payload['client_id'])->toBe('SaprfClient')
        ->and($payload['date'])->toBe('2026-10-01')
        ->and($payload['due_date'])->toBe('2026-10-31')
        ->and($payload['po_number'])->toBe('SAPRF-2026-09')
        ->and($payload['public_notes'])->toContain('September 2026')
        ->and($item['product_key'])->toBe('SAPRF entries')
        ->and($item['quantity'])->toBe(2)
        ->and((float) $item['cost'])->toBe(50.0)
        ->and((float) $item['tax_rate1'])->toBe(0.0)
        ->and((float) $item['tax_rate2'])->toBe(0.0)
        ->and((float) $item['tax_rate3'])->toBe(0.0)
        ->and($item['notes'])->toContain('September 2026')
        ->and($request->hasHeader('X-API-TOKEN', 'test-token'))
        ->and($request->hasHeader('X-Requested-With', 'XMLHttpRequest'));
});

test('a failed email is retried without creating a second invoice', function () {
    configureCharsleyInvoicing();
    Http::fake([
        'https://invoice.charsley.co.za/api/v1/invoices/bulk' => Http::sequence()
            ->push(['message' => 'Client has no email'], 422)
            ->push(['message' => 'Success'], 200),
        'https://invoice.charsley.co.za/api/v1/invoices' => Http::response([
            'data' => ['id' => 'inv_1', 'number' => '0042'],
        ], 200),
    ]);
    charsleyEntry($this->match);

    $issuer = app(IssueCharsleyInvoice::class);

    expect(fn () => $issuer->issue(2026, 9))->toThrow(InvoiceNinjaException::class);
    expect(CharsleyInvoice::first()->status)->toBe(CharsleyInvoice::STATUS_CREATED);

    $invoice = $issuer->issue(2026, 9);

    expect($invoice->status)->toBe(CharsleyInvoice::STATUS_SENT)
        ->and(CharsleyInvoice::count())->toBe(1);

    $creates = collect(Http::recorded())->filter(
        fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/api/v1/invoices')
    );
    expect($creates)->toHaveCount(1);
});

test('the scheduled command invoices the previous month only when enabled', function () {
    configureCharsleyInvoicing(enabled: false);
    fakeInvoiceNinja();
    charsleyEntry($this->match);

    $this->artisan('saprf:invoice-charsley', ['period' => '2026-09'])
        ->expectsOutputToContain('turned off')
        ->assertSuccessful();

    Http::assertNothingSent();

    configureCharsleyInvoicing();

    $this->artisan('saprf:invoice-charsley', ['period' => '2026-09', '--dry-run' => true])
        ->expectsOutputToContain('1 entry × R50.00 = R50.00')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Http::assertNothingSent();

    $this->artisan('saprf:invoice-charsley', ['period' => '2026-09'])
        ->expectsOutputToContain('0042')
        ->assertSuccessful();

    expect(CharsleyInvoice::where('period', '2026-09')->value('status'))->toBe('sent');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'saprf:invoice-charsley'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 7 1 * *');
});

test('a blank token does not wipe a saved one and the nrapa client is rejected', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $payload = [
        'invoice_ninja_enabled' => '0',
        'invoice_ninja_api_url' => 'https://invoice.charsley.co.za',
        'invoice_ninja_api_token' => 'secret-token',
        'invoice_ninja_client_id' => 'SaprfClient',
        'invoice_ninja_entry_fee' => '50',
    ];

    $this->actingAs($developer)
        ->put(route('site-settings.invoice-ninja.update'), $payload)
        ->assertRedirect(route('site-settings.index'));

    expect(app(SettingsService::class)->get('invoice_ninja_api_token'))->toBe('secret-token');

    $this->actingAs($developer)
        ->put(route('site-settings.invoice-ninja.update'), [
            ...$payload,
            'invoice_ninja_api_token' => '',
            'invoice_ninja_entry_fee' => '75',
        ])
        ->assertRedirect(route('site-settings.index'));

    expect(app(SettingsService::class)->get('invoice_ninja_api_token'))->toBe('secret-token')
        ->and((string) app(SettingsService::class)->get('invoice_ninja_entry_fee'))->toBe('75');

    $this->actingAs($developer)
        ->from(route('site-settings.index'))
        ->put(route('site-settings.invoice-ninja.update'), [
            ...$payload,
            'invoice_ninja_api_token' => '',
            'invoice_ninja_client_id' => 'VolejRejNm',
        ])
        ->assertRedirect(route('site-settings.index'))
        ->assertSessionHasErrors('invoice_ninja_client_id');

    expect(Setting::query()->where('key', 'invoice_ninja_client_id')->value('value'))->toBe('SaprfClient');

    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->put(route('site-settings.invoice-ninja.update'), $payload)
        ->assertForbidden();
});

test('finance shows the selected month entry count and total', function () {
    charsleyEntry($this->match);
    charsleyEntry($this->match);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('financials.dashboard', ['from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertOk()
        ->assertSee('Charsley Digital')
        ->assertSee('September 2026')
        ->assertSee('R100.00');
});

test('a developer can test the connection and invoice the previous month', function () {
    $this->travelTo('2026-10-20 08:00:00');
    configureCharsleyInvoicing();
    fakeInvoiceNinja();
    charsleyEntry($this->match);

    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->post(route('site-settings.invoice-ninja.test'))
        ->assertRedirect(route('site-settings.index'))
        ->assertSessionHas('invoice_ninja_success');

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_ends_with($request->url(), '/api/v1/clients/SaprfClient');
    });

    $this->actingAs($developer)
        ->post(route('site-settings.invoice-ninja.issue'))
        ->assertRedirect(route('site-settings.index'))
        ->assertSessionHas('invoice_ninja_success');

    expect(CharsleyInvoice::where('period', '2026-09')->value('status'))->toBe('sent');
});
