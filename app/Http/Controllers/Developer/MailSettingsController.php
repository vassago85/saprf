<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class MailSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $settings = $this->settingsService->all();

        $status = [
            'configured' => ! empty($settings['mailgun_domain'] ?? '') && ! empty($settings['mailgun_secret'] ?? ''),
            'mailer' => Config::get('mail.default'),
            'from_address' => Config::get('mail.from.address'),
            'from_name' => Config::get('mail.from.name'),
        ];

        return view('developer.mail', compact('settings', 'status'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mailgun_domain' => ['nullable', 'string', 'max:255'],
            'mailgun_secret' => ['nullable', 'string', 'max:255'],
            'mailgun_endpoint' => ['required', 'string', 'in:api.eu.mailgun.net,api.mailgun.net'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $oldValues = collect($this->settingsService->all())
            ->only(['mailgun_domain', 'mailgun_secret', 'mailgun_endpoint', 'mail_from_address', 'mail_from_name'])
            ->toArray();

        $this->settingsService->set('mailgun_domain', $validated['mailgun_domain'] ?? '', 'Mailgun sending domain');
        $this->settingsService->set('mailgun_secret', $validated['mailgun_secret'] ?? '', 'Mailgun API key');
        $this->settingsService->set('mailgun_endpoint', $validated['mailgun_endpoint'], 'Mailgun API endpoint (EU or US)');
        $this->settingsService->set('mail_from_address', $validated['mail_from_address'] ?? '', 'Email from address');
        $this->settingsService->set('mail_from_name', $validated['mail_from_name'] ?? '', 'Email from name');

        $newValues = $validated;
        if (isset($newValues['mailgun_secret'])) {
            $newValues['mailgun_secret'] = $newValues['mailgun_secret'] ? '***' : '';
        }
        if (isset($oldValues['mailgun_secret'])) {
            $oldValues['mailgun_secret'] = $oldValues['mailgun_secret'] ? '***' : '';
        }

        $this->auditLogService->log(
            $request->user(),
            'mail_settings_updated',
            'Setting',
            null,
            $oldValues,
            $newValues,
            'Mail settings updated from developer dashboard',
        );

        return redirect()->route('developer.mail.index')
            ->with('success', 'Mail settings saved.');
    }

    public function test(Request $request): RedirectResponse
    {
        $user = $request->user();

        try {
            Mail::raw(
                "This is a test email from SAPRF.\n\nIf you received this, mail is configured correctly.\n\nSent at: " . now()->toDateTimeString(),
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('SAPRF mail test');
                },
            );

            return redirect()->route('developer.mail.index')
                ->with('success', "Test email sent to {$user->email}. Check your inbox.");
        } catch (\Throwable $e) {
            return redirect()->route('developer.mail.index')
                ->with('error', 'Test email failed: ' . $e->getMessage());
        }
    }
}
