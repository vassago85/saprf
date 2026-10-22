<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InvoiceNinja\CharsleyInvoiceSettings;
use App\Services\InvoiceNinja\InvoiceNinjaClient;
use App\Services\InvoiceNinja\InvoiceNinjaException;
use App\Services\InvoiceNinja\IssueCharsleyInvoice;
use App\Services\SettingsService;
use App\Support\BackupNotificationAddress;
use App\Support\WorldsCountdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $settings = $this->settingsService->all();

        // Secrets must never be rendered into HTML. The password inputs stay
        // empty; saving with a blank field leaves the stored value untouched.
        $mailgunSecretConfigured = filled($settings['mailgun_secret'] ?? '');
        $mailgunWebhookSigningConfigured = filled($settings['mailgun_webhook_signing_key'] ?? '')
            || filled(config('services.mailgun.webhook_signing_key'));
        $invoiceNinjaTokenConfigured = filled($settings['invoice_ninja_api_token'] ?? '');
        $settings['mailgun_secret'] = '';
        $settings['mailgun_webhook_signing_key'] = '';
        $settings['invoice_ninja_api_token'] = '';

        // Owner + developer are the only people who could reasonably receive
        // the platform-fee payout — restrict the picker so we don't offer
        // random shooter accounts.
        $platformOperatorCandidates = User::role(['developer', 'owner'])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('site-settings.index', compact(
            'settings',
            'platformOperatorCandidates',
            'mailgunSecretConfigured',
            'mailgunWebhookSigningConfigured',
            'invoiceNinjaTokenConfigured',
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'non_member_surcharge' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'lapsed_member_surcharge' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'withdrawal_admin_fee' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'withdrawal_deadline_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'withdrawal_developer_fee' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'division_single_select' => ['required', 'boolean'],
            'saprf_fee_type' => ['required', 'in:percentage,fixed'],
            'saprf_fee_value' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'platform_fee_type' => ['nullable', 'in:percentage,fixed'],
            'platform_fee_value' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'billing_start_date' => ['nullable', 'date_format:Y-m-d'],
            'platform_operator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'membership_platform_fee_pct' => ['required', 'numeric', 'min:0', 'max:50'],
            'estimated_gateway_fee_percentage' => ['required', 'numeric', 'min:0', 'max:20'],
            'estimated_gateway_flat_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'payfast_merchant_id' => ['nullable', 'string', 'max:20'],
            'payfast_merchant_key' => ['nullable', 'string', 'max:50'],
            'payfast_passphrase' => ['nullable', 'string', 'max:100'],
            'payfast_sandbox' => ['required', 'boolean'],
            'payments_enabled' => ['required', 'boolean'],
            'mailgun_domain' => ['nullable', 'string', 'max:255'],
            'mailgun_secret' => ['nullable', 'string', 'max:255'],
            'mailgun_webhook_signing_key' => ['nullable', 'string', 'max:255'],
            'mailgun_endpoint' => ['nullable', 'string', 'in:api.eu.mailgun.net,api.mailgun.net'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email', 'max:255'],
            'secretary_email' => ['nullable', 'email', 'max:255'],
            'backup_notification_email' => ['nullable', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (BackupNotificationAddress::isPlaceholder(is_string($value) ? $value : null)) {
                    $fail('Backup notifications need a real inbox. Placeholder addresses such as your@example.com are rejected.');
                }
            }],
            'notifications_enabled' => ['required', 'boolean'],
            // Invoice document customisation. All optional — defaults in
            // InvoiceService kick in when fields are blank. The VAT number
            // field is the switch between "Invoice" (empty) and
            // "Tax Invoice" (populated) on the PDF header.
            'invoice_business_name' => ['nullable', 'string', 'max:255'],
            'invoice_business_address' => ['nullable', 'string', 'max:1000'],
            'invoice_contact_email' => ['nullable', 'email', 'max:255'],
            'invoice_registration_number' => ['nullable', 'string', 'max:100'],
            'invoice_vat_number' => ['nullable', 'string', 'max:50'],
            'invoice_footer_notes' => ['nullable', 'string', 'max:500'],
            'worlds_countdown_at' => ['nullable', 'date'],
        ]);

        $oldValues = $this->settingsService->all();

        $this->settingsService->set('non_member_surcharge', $validated['non_member_surcharge'], 'Extra fee for non-members per match (ZAR)');
        $this->settingsService->set('lapsed_member_surcharge', $validated['lapsed_member_surcharge'], 'Extra fee for lapsed members per match (ZAR)');

        // Matches cache base + surcharge for display; resync open ones so event
        // cards match what checkout charges. Finished matches keep historical fees.
        MatchEvent::whereNotIn('status', ['completed', 'cancelled'])->update([
            'non_member_fee' => DB::raw('COALESCE(active_member_fee, 0) + '.(float) $validated['non_member_surcharge']),
            'lapsed_member_fee' => DB::raw('COALESCE(active_member_fee, 0) + '.(float) $validated['lapsed_member_surcharge']),
        ]);

        $this->settingsService->set('withdrawal_admin_fee', $validated['withdrawal_admin_fee'], 'Admin fee charged on match withdrawal (ZAR)');
        $this->settingsService->set('withdrawal_deadline_hours', $validated['withdrawal_deadline_hours'], 'Hours before match date that withdrawal refunds are cut off');

        if ($request->user()->hasRole('developer') && isset($validated['withdrawal_developer_fee'])) {
            $this->settingsService->set('withdrawal_developer_fee', $validated['withdrawal_developer_fee'], 'Developer share of the withdrawal fee on cash refunds (ZAR); PayFast fee next, SAPRF keeps the rest');
        }

        if ($request->has('cancellation_refunds_enabled')) {
            $this->settingsService->set(
                'cancellation_refunds_enabled',
                $request->boolean('cancellation_refunds_enabled') ? '1' : '0',
                'Allow members to request a cash refund of cancellation credit (1=yes, 0=no). Off until finance is ready to pay them.',
            );
        }

        $this->settingsService->set('division_single_select', $validated['division_single_select'], 'Restrict shooter to one division per match (1=yes, 0=no)');

        $this->settingsService->set('saprf_fee_type', $validated['saprf_fee_type'], 'SAPRF fee type: percentage of match fee or fixed rand amount per shooter');
        $this->settingsService->set('saprf_fee_value', $validated['saprf_fee_value'], 'SAPRF fee value (interpreted by saprf_fee_type)');

        // Platform fee is developer-managed. Only persist when a developer is editing — otherwise
        // owners submitting the form would clear the platform fee.
        if ($request->user()->hasRole('developer') && isset($validated['platform_fee_type'], $validated['platform_fee_value'])) {
            $this->settingsService->set('platform_fee_type', $validated['platform_fee_type'], 'Platform fee type: percentage of match fee or fixed rand amount per shooter');
            $this->settingsService->set('platform_fee_value', $validated['platform_fee_value'], 'Platform fee value (interpreted by platform_fee_type)');
        }

        // Billing start date — the cut-off before which the platform fee is
        // waived (SAPRF R50 is still charged). Blank clears the grace period.
        if (array_key_exists('billing_start_date', $validated)) {
            $this->settingsService->set(
                'billing_start_date',
                $validated['billing_start_date'] ?? '',
                'ISO date. Registrations with registered_at before this date have the platform fee waived (SAPRF R50 is still charged).',
            );
        }

        // Owner + developer can nominate who receives the platform-fee payout.
        // Empty string wipes the setting so payout generation refuses to run
        // until an operator is picked again.
        if (array_key_exists('platform_operator_user_id', $validated)) {
            $this->settingsService->set(
                'platform_operator_user_id',
                $validated['platform_operator_user_id'] ?? '',
                'User ID who receives monthly platform-fee payouts',
            );
        }
        $this->settingsService->set('membership_platform_fee_pct', $validated['membership_platform_fee_pct'], 'Platform fee % on membership and other non-match transactions');
        $this->settingsService->set('estimated_gateway_fee_percentage', $validated['estimated_gateway_fee_percentage'], 'Estimated PayFast gateway fee % (for reporting only)');
        $this->settingsService->set('estimated_gateway_flat_fee', $validated['estimated_gateway_flat_fee'], 'Estimated PayFast flat fee per transaction in ZAR (for reporting only)');

        $this->settingsService->set('payfast_merchant_id', $validated['payfast_merchant_id'] ?? '', 'PayFast Merchant ID');
        // Secrets: only overwrite when a new value was typed. An empty password
        // field on save used to wipe the passphrase and break PayFast signatures.
        if (filled($validated['payfast_merchant_key'] ?? null)) {
            $this->settingsService->set('payfast_merchant_key', $validated['payfast_merchant_key'], 'PayFast Merchant Key');
        }
        if (filled($validated['payfast_passphrase'] ?? null)) {
            $this->settingsService->set('payfast_passphrase', $validated['payfast_passphrase'], 'PayFast Passphrase');
        }
        $this->settingsService->set('payfast_sandbox', $validated['payfast_sandbox'], 'PayFast sandbox mode (1=sandbox, 0=live)');
        $this->settingsService->set('payments_enabled', $validated['payments_enabled'], 'Enable online payments (1=yes, 0=no)');

        $this->settingsService->set('mailgun_domain', $validated['mailgun_domain'] ?? '', 'Mailgun sending domain');
        if (filled($validated['mailgun_secret'] ?? null)) {
            $this->settingsService->set('mailgun_secret', $validated['mailgun_secret'], 'Mailgun API key');
        }
        if (filled($validated['mailgun_webhook_signing_key'] ?? null)) {
            $this->settingsService->set('mailgun_webhook_signing_key', $validated['mailgun_webhook_signing_key'], 'Mailgun webhook signing key');
        }
        $this->settingsService->set('mailgun_endpoint', $validated['mailgun_endpoint'] ?? 'api.eu.mailgun.net', 'Mailgun API endpoint (EU or US)');
        $this->settingsService->set('mail_from_address', $validated['mail_from_address'] ?? '', 'Email from address');
        $this->settingsService->set('mail_from_name', $validated['mail_from_name'] ?? '', 'Email from name');
        $this->settingsService->set('owner_email', $validated['owner_email'] ?? '', 'Owner inbox for owner-only correspondence');
        $this->settingsService->set('secretary_email', $validated['secretary_email'] ?? '', 'Secretary inbox for contact-form enquiries and member Reply-To');
        $this->settingsService->set('backup_notification_email', $validated['backup_notification_email'] ?? '', 'Inbox for database backup failure mail only');
        $this->settingsService->set('notifications_enabled', $validated['notifications_enabled'], 'Send outgoing email notifications (1=yes, 0=paused). Auth OTP + password reset always send regardless.');

        // Invoice document customisation. Blank values are persisted as
        // empty strings so InvoiceService's `null-when-blank` normaliser
        // falls back to the hardcoded defaults.
        $this->settingsService->set('invoice_business_name', $validated['invoice_business_name'] ?? '', 'Business name printed at the top of member invoices');
        $this->settingsService->set('invoice_business_address', $validated['invoice_business_address'] ?? '', 'Business address block for member invoices (multi-line)');
        $this->settingsService->set('invoice_contact_email', $validated['invoice_contact_email'] ?? '', 'Contact email printed on member invoices (defaults to secretary/owner)');
        $this->settingsService->set('invoice_registration_number', $validated['invoice_registration_number'] ?? '', 'Company / NPO registration number on invoices');
        $this->settingsService->set('invoice_vat_number', $validated['invoice_vat_number'] ?? '', 'SA VAT number. When set, invoices become VAT-compliant tax invoices.');
        $this->settingsService->set('invoice_footer_notes', $validated['invoice_footer_notes'] ?? '', 'Optional footer text on invoices (overrides the default "not a VAT invoice" line)');

        if (array_key_exists('worlds_countdown_at', $validated)) {
            $this->settingsService->set(
                WorldsCountdown::SETTING_KEY,
                WorldsCountdown::normalize($validated['worlds_countdown_at'] ?? ''),
                'Landing-page countdown target, South African time. Blank means the final dates are still to be announced.',
            );
        }

        $this->auditLogService->log(
            $request->user(),
            'settings_updated',
            'Setting',
            null,
            $oldValues,
            $validated,
            'Federation settings updated by owner',
        );

        return redirect()->route('site-settings.index')
            ->with('success', 'Settings updated successfully.');
    }

    /**
     * Send a one-off test email to an address of the admin's choosing so they
     * can confirm the Mailgun credentials actually deliver. Uses the mail config
     * already applied from saved settings (AppServiceProvider::applyMailgunSettings)
     * and sends directly via Mail::raw — so it bypasses the notifications master
     * switch (this is a deliberate manual test, not a queued notification) and
     * surfaces the real transport error on failure for easy debugging.
     */
    public function sendTestEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        $to = $validated['test_email'];
        $fromName = Config::get('mail.from.name') ?: 'SAPRF';

        try {
            Mail::raw(
                "This is a test email from the SAPRF platform.\n\n"
                    ."If you received this, your Mailgun email settings are working correctly.\n\n"
                    .'Sent: '.now()->toDayDateTimeString().' (SAST)',
                function ($message) use ($to, $fromName) {
                    $message->to($to)
                        ->subject('SAPRF test email — '.$fromName);
                }
            );
        } catch (\Throwable $e) {
            $this->auditLogService->log(
                $request->user(),
                'settings_test_email_failed',
                'Setting',
                null,
                null,
                ['to' => $to, 'error' => $e->getMessage()],
                'Test email failed to send',
            );

            return redirect()->route('site-settings.index')
                ->with('test_email_error', "Test email to {$to} failed: ".$e->getMessage());
        }

        $this->auditLogService->log(
            $request->user(),
            'settings_test_email_sent',
            'Setting',
            null,
            null,
            ['to' => $to],
            'Test email sent',
        );

        return redirect()->route('site-settings.index')
            ->with('test_email_success', "Test email sent to {$to}. Check the inbox (and spam folder).");
    }

    public function updateInvoiceNinja(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_ninja_enabled' => ['required', 'boolean'],
            'invoice_ninja_api_url' => ['required', 'url', 'max:255'],
            'invoice_ninja_api_token' => ['nullable', 'string', 'max:255'],
            'invoice_ninja_client_id' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === CharsleyInvoiceSettings::NRAPA_CLIENT_ID) {
                    $fail('That client is National Rifle and Pistol Association. Create a SAPRF client in Invoice Ninja and use its id.');
                }
            }],
            'invoice_ninja_entry_fee' => ['required', 'numeric', 'min:0', 'max:99999.99'],
        ]);

        $this->settingsService->set(
            'invoice_ninja_enabled',
            $request->boolean('invoice_ninja_enabled') ? '1' : '0',
            'Invoice SAPRF through Invoice Ninja on the 1st for the previous month (1=yes, 0=no)',
        );
        $this->settingsService->set(
            'invoice_ninja_api_url',
            rtrim($validated['invoice_ninja_api_url'], '/'),
            'Invoice Ninja base URL',
        );
        $this->settingsService->set(
            'invoice_ninja_client_id',
            trim($validated['invoice_ninja_client_id']),
            'Invoice Ninja client id for SAPRF',
        );
        $this->settingsService->set(
            'invoice_ninja_entry_fee',
            $validated['invoice_ninja_entry_fee'],
            'Rand amount Charsley Digital charges SAPRF per paid match entry',
        );

        if (filled($validated['invoice_ninja_api_token'] ?? null)) {
            $this->settingsService->set(
                'invoice_ninja_api_token',
                trim($validated['invoice_ninja_api_token']),
                'Invoice Ninja API token',
            );
        }

        $this->auditLogService->log(
            $request->user(),
            'settings_updated',
            'Setting',
            null,
            null,
            [
                'invoice_ninja_enabled' => $request->boolean('invoice_ninja_enabled') ? '1' : '0',
                'invoice_ninja_api_url' => rtrim($validated['invoice_ninja_api_url'], '/'),
                'invoice_ninja_client_id' => trim($validated['invoice_ninja_client_id']),
                'invoice_ninja_entry_fee' => $validated['invoice_ninja_entry_fee'],
                'invoice_ninja_api_token' => filled($validated['invoice_ninja_api_token'] ?? null) ? '[updated]' : '[kept]',
            ],
            'Invoice Ninja settings updated by developer',
        );

        return redirect()->route('site-settings.index')
            ->with('success', 'Invoice Ninja settings saved.');
    }

    public function testInvoiceNinja(InvoiceNinjaClient $client, CharsleyInvoiceSettings $settings): RedirectResponse
    {
        if (! $settings->isConfigured()) {
            return redirect()->route('site-settings.index')
                ->with('invoice_ninja_error', 'Save the API URL, token, and SAPRF client id before testing the connection.');
        }

        try {
            $remote = $client->client($settings->clientId());
            $name = $remote['display_name'] ?? $remote['name'] ?? 'the client';
        } catch (InvoiceNinjaException $e) {
            return redirect()->route('site-settings.index')
                ->with('invoice_ninja_error', $e->getMessage());
        }

        return redirect()->route('site-settings.index')
            ->with('invoice_ninja_success', 'Connected. Invoices will be billed to '.$name.'.');
    }

    public function invoicePreviousMonth(IssueCharsleyInvoice $issuer): RedirectResponse
    {
        $previous = now()->timezone('Africa/Johannesburg')->subMonth();

        try {
            $invoice = $issuer->issue($previous->year, $previous->month);
        } catch (InvoiceNinjaException $e) {
            return redirect()->route('site-settings.index')
                ->with('invoice_ninja_error', $e->getMessage());
        }

        if ($invoice === null) {
            return redirect()->route('site-settings.index')
                ->with('invoice_ninja_success', 'No paid entries in '.$previous->format('F Y').'. Nothing was sent.');
        }

        $number = $invoice->invoice_number ?: $invoice->invoice_ninja_id;

        return redirect()->route('site-settings.index')
            ->with('invoice_ninja_success', 'Invoice '.$number.' for '.$previous->format('F Y').' is '.$invoice->status.'.');
    }
}
