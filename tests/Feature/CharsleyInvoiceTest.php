<?php

use App\Models\CharsleyInvoice;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\MembershipPayment;
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
    // The migration zeroes membership_platform_fee_pct; most tests should
    // see that default. Tests that want to exercise a non-zero percent set
    // the setting themselves.
    $settings->set('membership_platform_fee_pct', '0', 'test');

    if ($entryFee !== null) {
        $settings->set('invoice_ninja_entry_fee', $entryFee, 'test');
    }
}

function charsleyEntry(MatchEvent $match, array $extra = []): MatchRegistration
{
    // Default platform_fee mirrors a normal paid online entry (R50). Tests
    // that want a waived / off-platform / club row override it explicitly.
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'shooter_name' => 'Paid Shooter',
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250,
        'platform_fee' => 50,
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

test('the monthly invoice sums the actual platform_fee stored on paid rows', function () {
    charsleyEntry($this->match);
    charsleyEntry($this->match, ['shooter_name' => 'Walk-in', 'fee_amount' => 0, 'platform_fee' => 0]);
    charsleyEntry($this->match, ['registered_at' => '2026-09-30 23:59:59']);
    charsleyEntry($this->match, ['payment_status' => 'pending']);
    charsleyEntry($this->match, ['payment_status' => 'failed']);
    charsleyEntry($this->match, ['payment_status' => 'unpaid', 'fee_amount' => 0, 'platform_fee' => 0]);
    charsleyEntry($this->match, ['payment_status' => 'waived']);
    charsleyEntry($this->match, ['payment_status' => 'refunded']);
    charsleyEntry($this->match, ['registered_at' => '2026-08-31 23:00:00']);
    charsleyEntry($this->match, ['registered_at' => '2026-10-01 00:00:00']);
    // Shooter the MD settled off-platform in September: platform fee was
    // zeroed when they were marked paid, so Charsley must NOT bill them.
    charsleyEntry($this->match, ['shooter_name' => 'Cash settled', 'platform_fee' => 0]);
    // Per-match fee override: a national match with R30 platform fee still
    // bills exactly R30, not the stock R50.
    charsleyEntry($this->match, ['shooter_name' => 'Override', 'platform_fee' => 30]);

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);

    expect(app(CharsleyInvoiceSettings::class)->clientId())->toBe('')
        ->and(app(CharsleyInvoiceSettings::class)->entryFee())->toBe(50.0)
        ->and($preview['match_entries'])->toBe(3)
        ->and($preview['entries'])->toBe(3)
        ->and($preview['match_total'])->toBe(130.0)
        ->and($preview['total'])->toBe(130.0);
});

test('membership payments are billed at the configured percent of gross', function () {
    charsleyEntry($this->match);

    $member = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $member->id,
        'saprf_number' => 'SAPRF-CHARSLEY-1',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-09-01',
        'expiry_date' => '2027-09-01',
    ]);

    MembershipPayment::create([
        'membership_id' => $membership->id,
        'amount' => 500,
        'payment_date' => '2026-09-12',
        'payment_method' => 'payfast',
        'status' => 'confirmed',
    ]);
    MembershipPayment::create([
        'membership_id' => $membership->id,
        'amount' => 500,
        'payment_date' => '2026-08-20',
        'payment_method' => 'payfast',
        'status' => 'confirmed',
    ]);
    MembershipPayment::create([
        'membership_id' => $membership->id,
        'amount' => 500,
        'payment_date' => '2026-09-18',
        'payment_method' => 'eft',
        'status' => 'pending',
    ]);

    configureCharsleyInvoicing();
    // Override the default-zero to prove a non-zero percent still works end
    // to end. Site Settings is the real-world knob operators would turn.
    app(SettingsService::class)->set('membership_platform_fee_pct', '2.5', 'test');
    fakeInvoiceNinja();

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);
    app(IssueCharsleyInvoice::class)->issue(2026, 9);

    // Match: R50 platform fee. Membership: R500 × 2.5% = R12.50.
    expect($preview['match_entries'])->toBe(1)
        ->and($preview['match_total'])->toBe(50.0)
        ->and($preview['membership_payments'])->toBe(1)
        ->and($preview['membership_percent'])->toBe(2.5)
        ->and($preview['membership_total'])->toBe(12.5)
        ->and($preview['total'])->toBe(62.5);

    $creates = collect(Http::recorded())->filter(
        fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/api/v1/invoices')
    );
    $items = $creates->first()[0]->data()['line_items'];

    expect($items)->toHaveCount(2)
        ->and($items[0]['product_key'])->toBe('SAPRF match entries')
        ->and((float) $items[0]['cost'])->toBe(50.0)
        ->and($items[1]['product_key'])->toBe('SAPRF memberships')
        ->and((float) $items[1]['cost'])->toBe(12.5);
});

test('club entries are billed at the stored platform_fee, with 0 excluded', function () {
    charsleyEntry($this->match);
    charsleyEntry($this->match, [
        'shooter_name' => 'Club shooter',
        'membership_fee_category' => 'club_entry',
        'fee_amount' => 200,
        'platform_fee' => 6,
    ]);
    charsleyEntry($this->match, [
        'shooter_name' => 'Cash club shooter',
        'membership_fee_category' => 'club_entry',
        'fee_amount' => 200,
        'platform_fee' => 0,
    ]);

    configureCharsleyInvoicing();
    fakeInvoiceNinja();

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);

    expect($preview['match_entries'])->toBe(1)
        ->and($preview['match_total'])->toBe(50.0)
        ->and($preview['club_entries'])->toBe(1)
        ->and($preview['club_percent'])->toBe(3.0)
        ->and($preview['club_total'])->toBe(6.0)
        ->and($preview['total'])->toBe(56.0);

    app(IssueCharsleyInvoice::class)->issue(2026, 9);

    $creates = collect(Http::recorded())->filter(
        fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/api/v1/invoices')
    );
    $items = $creates->first()[0]->data()['line_items'];

    expect($items)->toHaveCount(2)
        ->and($items[0]['product_key'])->toBe('SAPRF match entries')
        ->and((float) $items[0]['cost'])->toBe(50.0)
        ->and($items[1]['product_key'])->toBe('SAPRF club entries')
        ->and((float) $items[1]['cost'])->toBe(6.0);
});

test('memberships add nothing when the platform percent is 0 (the default)', function () {
    charsleyEntry($this->match);

    $member = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $member->id,
        'saprf_number' => 'SAPRF-CHARSLEY-ZERO',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => '2026-09-01',
        'expiry_date' => '2027-09-01',
    ]);
    MembershipPayment::create([
        'membership_id' => $membership->id,
        'amount' => 500,
        'payment_date' => '2026-09-12',
        'payment_method' => 'payfast',
        'status' => 'confirmed',
    ]);

    configureCharsleyInvoicing();
    fakeInvoiceNinja();

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);
    app(IssueCharsleyInvoice::class)->issue(2026, 9);

    expect($preview['membership_payments'])->toBe(1)
        ->and($preview['membership_percent'])->toBe(0.0)
        ->and($preview['membership_total'])->toBe(0.0)
        ->and($preview['total'])->toBe(50.0);

    $items = collect(Http::recorded())
        ->first(fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/api/v1/invoices'))[0]
        ->data()['line_items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['product_key'])->toBe('SAPRF match entries');
});

test('voiding an invoice lets issue() replace it in the same month', function () {
    configureCharsleyInvoicing();
    fakeInvoiceNinja();
    charsleyEntry($this->match);

    $issuer = app(IssueCharsleyInvoice::class);

    $first = $issuer->issue(2026, 9);
    expect($first->status)->toBe('sent')
        ->and($first->period)->toBe('2026-09')
        ->and((float) $first->total)->toBe(50.0);

    // Operator credited the first invoice in Invoice Ninja — mark it voided
    // on the SAPRF ledger. New paid rows arrive before we re-issue.
    $first->update(['voided_at' => now(), 'voided_reason' => 'Credited']);

    charsleyEntry($this->match, ['shooter_name' => 'Late paid', 'platform_fee' => 50]);
    charsleyEntry($this->match, ['shooter_name' => 'Later paid', 'platform_fee' => 50]);

    $preview = $issuer->preview(2026, 9);
    expect($preview['total'])->toBe(150.0);

    $second = $issuer->issue(2026, 9);

    expect($second->id)->not->toBe($first->id)
        ->and($second->period)->toBe('2026-09')
        ->and($second->status)->toBe('sent')
        ->and((float) $second->total)->toBe(150.0)
        ->and(\App\Models\CharsleyInvoice::count())->toBe(2)
        ->and(\App\Models\CharsleyInvoice::whereNull('voided_at')->where('period', '2026-09')->count())->toBe(1);
});

test('the entry fee setting is surfaced in the preview even though the total uses stored platform_fee', function () {
    configureCharsleyInvoicing(entryFee: '80');
    charsleyEntry($this->match, ['platform_fee' => 80]);
    charsleyEntry($this->match, ['platform_fee' => 80]);

    $preview = app(IssueCharsleyInvoice::class)->preview(2026, 9);

    expect($preview['entry_fee'])->toBe(80.0)
        ->and($preview['match_total'])->toBe(160.0)
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
        ->and($item['product_key'])->toBe('SAPRF match entries')
        // One summary line per category: quantity 1, cost = sum of stored
        // platform_fee. Both charsleyEntry rows carry the R50 default, so
        // the line totals R100.
        ->and($item['quantity'])->toBe(1)
        ->and((float) $item['cost'])->toBe(100.0)
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
        ->expectsOutputToContain('1 match (R50.00) + 0 club (R0.00) + 0 membership (R0.00) = R50.00')
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

test('a developer can re-issue a voided invoice for a past month from the dashboard', function () {
    $this->travelTo('2026-11-01 08:00:00');
    configureCharsleyInvoicing();
    fakeInvoiceNinja();

    // One October entry — the wrong invoice sent before had way more rows,
    // so the correct re-issue should only bill for the real paid platform
    // transactions sitting in the ledger right now.
    charsleyEntry($this->match, [
        'registered_at' => '2026-10-15 10:00:00',
        'shooter_name' => 'October Paid',
    ]);

    // Simulate the "already sent at wrong total, then credited manually"
    // state: an invoice row for Oct exists, is voided.
    $voided = CharsleyInvoice::create([
        'period' => '2026-10',
        'invoice_ninja_id' => 'abc',
        'invoice_number' => '0016',
        'entries' => 198,
        'entry_fee' => 50,
        'total' => 9900,
        'status' => 'sent',
        'sent_at' => now()->subDays(2),
        'voided_at' => now()->subDay(),
        'voided_reason' => 'Credited in Invoice Ninja',
    ]);

    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->post(route('financials.charsley.issue'), ['period' => '2026-10'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $active = CharsleyInvoice::where('period', '2026-10')->whereNull('voided_at')->first();

    expect($active)->not->toBeNull()
        ->and($active->id)->not->toBe($voided->id)
        ->and((float) $active->total)->toBe(50.0)
        ->and($active->status)->toBe('sent')
        ->and(CharsleyInvoice::where('period', '2026-10')->count())->toBe(2);
});

test('issuing for a past period with nothing billable is a no-op and does not create a row', function () {
    $this->travelTo('2026-11-01 08:00:00');
    configureCharsleyInvoicing();
    fakeInvoiceNinja();

    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->post(route('financials.charsley.issue'), ['period' => '2026-07'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(CharsleyInvoice::where('period', '2026-07')->count())->toBe(0);
    Http::assertNothingSent();
});

test('the issue-for-period route rejects a malformed period', function () {
    configureCharsleyInvoicing();

    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->post(route('financials.charsley.issue'), ['period' => 'nope'])
        ->assertSessionHasErrors('period');
});

test('non-developer finance roles cannot issue or void Charsley invoices', function () {
    configureCharsleyInvoicing();
    fakeInvoiceNinja();

    $existing = CharsleyInvoice::create([
        'period' => '2026-10',
        'invoice_ninja_id' => 'abc',
        'invoice_number' => '0016',
        'entries' => 198,
        'entry_fee' => 50,
        'total' => 9900,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    // Admin, owner, and exco can see the finance dashboard but must not be
    // able to touch invoice issuance / voiding by posting directly.
    foreach (['admin', 'owner', 'exco'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)
            ->post(route('financials.charsley.void', $existing))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('financials.charsley.issue'), ['period' => '2026-10'])
            ->assertForbidden();
    }

    // Nothing was voided and no new invoice row was created.
    expect($existing->fresh()->voided_at)->toBeNull()
        ->and(CharsleyInvoice::count())->toBe(1);
});
