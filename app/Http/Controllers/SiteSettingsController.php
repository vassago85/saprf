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
            'withdrawal_admin_fee' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'withdrawal_deadline_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'season_locked_age_categories' => ['required', 'boolean'],
            'age_classification_date_mode' => ['required', 'in:first_day_of_calendar_year,season_start_date,custom_date'],
            'age_classification_custom_date' => ['nullable', 'date', 'required_if:age_classification_date_mode,custom_date'],
            'prs_junior_max_age' => ['required', 'integer', 'min:1', 'max:99'],
            'pr22_junior_max_age' => ['required', 'integer', 'min:1', 'max:99'],
            'senior_min_age' => ['required', 'integer', 'min:1', 'max:99'],
            'category_multi_select' => ['required', 'boolean'],
            'division_single_select' => ['required', 'boolean'],
            'category_rankings_enabled' => ['required', 'boolean'],
            'division_awards_enabled' => ['required', 'boolean'],
            'category_awards_enabled' => ['required', 'boolean'],
            'saprf_fee_percentage' => ['required', 'numeric', 'min:0', 'max:50'],
            'platform_fee_percentage' => ['required', 'numeric', 'min:0', 'max:50'],
            'estimated_gateway_fee_percentage' => ['required', 'numeric', 'min:0', 'max:20'],
            'estimated_gateway_flat_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'payfast_merchant_id' => ['nullable', 'string', 'max:20'],
            'payfast_merchant_key' => ['nullable', 'string', 'max:50'],
            'payfast_passphrase' => ['nullable', 'string', 'max:100'],
            'payfast_sandbox' => ['required', 'boolean'],
            'payments_enabled' => ['required', 'boolean'],
        ]);

        $oldValues = $this->settingsService->all();

        $this->settingsService->set('annual_membership_fee', $validated['annual_membership_fee'], 'Annual membership fee (ZAR)');
        $this->settingsService->set('non_member_surcharge', $validated['non_member_surcharge'], 'Extra fee for non-members per match (ZAR)');
        $this->settingsService->set('lapsed_member_surcharge', $validated['lapsed_member_surcharge'], 'Extra fee for lapsed members per match (ZAR)');
        $this->settingsService->set('withdrawal_admin_fee', $validated['withdrawal_admin_fee'], 'Admin fee charged on match withdrawal (ZAR)');
        $this->settingsService->set('withdrawal_deadline_hours', $validated['withdrawal_deadline_hours'], 'Hours before match date that withdrawal refunds are cut off');

        $this->settingsService->set('season_locked_age_categories', $validated['season_locked_age_categories'], 'Lock age-based categories for the full season (1=yes, 0=no)');
        $this->settingsService->set('age_classification_date_mode', $validated['age_classification_date_mode'], 'How to determine the age classification date (first_day_of_calendar_year, season_start_date, custom_date)');
        $this->settingsService->set('age_classification_custom_date', $validated['age_classification_custom_date'] ?? '', 'Custom classification date (YYYY-MM-DD) used when mode is custom_date');
        $this->settingsService->set('prs_junior_max_age', $validated['prs_junior_max_age'], 'Centrefire (PRS) junior category: maximum age on classification date');
        $this->settingsService->set('pr22_junior_max_age', $validated['pr22_junior_max_age'], 'Rimfire (PR22) junior category: maximum age on classification date');
        $this->settingsService->set('senior_min_age', $validated['senior_min_age'], 'Senior category: minimum age on classification date');
        $this->settingsService->set('category_multi_select', $validated['category_multi_select'], 'Allow shooters to have multiple categories per match (1=yes, 0=no)');
        $this->settingsService->set('division_single_select', $validated['division_single_select'], 'Restrict shooter to one division per match (1=yes, 0=no)');
        $this->settingsService->set('category_rankings_enabled', $validated['category_rankings_enabled'], 'Enable category-based standings and rankings (1=yes, 0=no)');
        $this->settingsService->set('division_awards_enabled', $validated['division_awards_enabled'], 'Enable division awards and placements (1=yes, 0=no)');
        $this->settingsService->set('category_awards_enabled', $validated['category_awards_enabled'], 'Enable category awards and placements (1=yes, 0=no)');

        $this->settingsService->set('saprf_fee_percentage', $validated['saprf_fee_percentage'], 'SAPRF federation fee as % of base match fee');
        $this->settingsService->set('platform_fee_percentage', $validated['platform_fee_percentage'], 'Platform operator fee as % of base match fee');
        $this->settingsService->set('estimated_gateway_fee_percentage', $validated['estimated_gateway_fee_percentage'], 'Estimated PayFast gateway fee % (for reporting only)');
        $this->settingsService->set('estimated_gateway_flat_fee', $validated['estimated_gateway_flat_fee'], 'Estimated PayFast flat fee per transaction in ZAR (for reporting only)');

        $this->settingsService->set('payfast_merchant_id', $validated['payfast_merchant_id'] ?? '', 'PayFast Merchant ID');
        $this->settingsService->set('payfast_merchant_key', $validated['payfast_merchant_key'] ?? '', 'PayFast Merchant Key');
        $this->settingsService->set('payfast_passphrase', $validated['payfast_passphrase'] ?? '', 'PayFast Passphrase');
        $this->settingsService->set('payfast_sandbox', $validated['payfast_sandbox'], 'PayFast sandbox mode (1=sandbox, 0=live)');
        $this->settingsService->set('payments_enabled', $validated['payments_enabled'], 'Enable online payments (1=yes, 0=no)');

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
