<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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

        // Owner + developer are the only people who could reasonably receive
        // the platform-fee payout — restrict the picker so we don't offer
        // random shooter accounts.
        $platformOperatorCandidates = User::role(['developer', 'owner'])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('site-settings.index', compact('settings', 'platformOperatorCandidates'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'non_member_surcharge' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'lapsed_member_surcharge' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'withdrawal_admin_fee' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'withdrawal_deadline_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'division_single_select' => ['required', 'boolean'],
            'division_awards_enabled' => ['required', 'boolean'],
            'saprf_fee_type' => ['required', 'in:percentage,fixed'],
            'saprf_fee_value' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'platform_fee_type' => ['nullable', 'in:percentage,fixed'],
            'platform_fee_value' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
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
            'mailgun_endpoint' => ['nullable', 'string', 'in:api.eu.mailgun.net,api.mailgun.net'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'notifications_enabled' => ['required', 'boolean'],
        ]);

        $oldValues = $this->settingsService->all();

        $this->settingsService->set('non_member_surcharge', $validated['non_member_surcharge'], 'Extra fee for non-members per match (ZAR)');
        $this->settingsService->set('lapsed_member_surcharge', $validated['lapsed_member_surcharge'], 'Extra fee for lapsed members per match (ZAR)');
        $this->settingsService->set('withdrawal_admin_fee', $validated['withdrawal_admin_fee'], 'Admin fee charged on match withdrawal (ZAR)');
        $this->settingsService->set('withdrawal_deadline_hours', $validated['withdrawal_deadline_hours'], 'Hours before match date that withdrawal refunds are cut off');

        $this->settingsService->set('division_single_select', $validated['division_single_select'], 'Restrict shooter to one division per match (1=yes, 0=no)');
        $this->settingsService->set('division_awards_enabled', $validated['division_awards_enabled'], 'Enable division awards and placements (1=yes, 0=no)');

        $this->settingsService->set('saprf_fee_type', $validated['saprf_fee_type'], 'SAPRF fee type: percentage of match fee or fixed rand amount per shooter');
        $this->settingsService->set('saprf_fee_value', $validated['saprf_fee_value'], 'SAPRF fee value (interpreted by saprf_fee_type)');

        // Platform fee is developer-managed. Only persist when a developer is editing — otherwise
        // owners submitting the form would clear the platform fee.
        if ($request->user()->hasRole('developer') && isset($validated['platform_fee_type'], $validated['platform_fee_value'])) {
            $this->settingsService->set('platform_fee_type', $validated['platform_fee_type'], 'Platform fee type: percentage of match fee or fixed rand amount per shooter');
            $this->settingsService->set('platform_fee_value', $validated['platform_fee_value'], 'Platform fee value (interpreted by platform_fee_type)');
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
        $this->settingsService->set('mailgun_endpoint', $validated['mailgun_endpoint'] ?? 'api.eu.mailgun.net', 'Mailgun API endpoint (EU or US)');
        $this->settingsService->set('mail_from_address', $validated['mail_from_address'] ?? '', 'Email from address');
        $this->settingsService->set('mail_from_name', $validated['mail_from_name'] ?? '', 'Email from name');
        $this->settingsService->set('notifications_enabled', $validated['notifications_enabled'], 'Send outgoing email notifications (1=yes, 0=paused). Auth OTP + password reset always send regardless.');

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
}
