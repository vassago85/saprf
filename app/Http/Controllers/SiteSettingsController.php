<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('site-settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'annual_membership_fee' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'non_member_surcharge' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'lapsed_member_surcharge' => ['required', 'numeric', 'min:0', 'max:99999.99'],
        ]);

        $oldValues = $this->settingsService->all();

        $this->settingsService->set('annual_membership_fee', $validated['annual_membership_fee'], 'Annual membership fee (ZAR)');
        $this->settingsService->set('non_member_surcharge', $validated['non_member_surcharge'], 'Extra fee for non-members per match (ZAR)');
        $this->settingsService->set('lapsed_member_surcharge', $validated['lapsed_member_surcharge'], 'Extra fee for lapsed members per match (ZAR)');

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
}
