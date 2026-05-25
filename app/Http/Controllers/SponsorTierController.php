<?php

namespace App\Http\Controllers;

use App\Models\SponsorTier;
use App\Services\AuditLogService;
use App\Services\SponsorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SponsorTierController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SponsorService $sponsorService,
    ) {}

    public function index(): View
    {
        $tiers = SponsorTier::withCount('sponsors')->ordered()->get();

        return view('sponsor-tiers.index', compact('tiers'));
    }

    public function create(): View
    {
        $placements = self::availablePlacements();

        return view('sponsor-tiers.create', compact('placements'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:sponsor_tiers,name'],
            'display_order' => ['required', 'integer', 'min:0'],
            'price_per_year' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'logo_max_height' => ['required', 'integer', 'min:16', 'max:200'],
            'placement' => ['required', 'array', 'min:1'],
            'placement.*' => ['string', 'in:landing_hero,landing_section,app_sidebar,match_pages,standings_pages,live_scoring,leaderboard,results_pages'],
        ]);

        $tier = SponsorTier::create($validated);
        $this->sponsorService->clearCache();

        $this->auditLogService->log(
            $request->user(),
            'sponsor_tier_created',
            'SponsorTier',
            $tier->id,
            null,
            ['name' => $tier->name, 'price_per_year' => $tier->price_per_year],
            "Sponsor tier '{$tier->name}' created",
        );

        return redirect()->route('sponsor-tiers.index')->with('success', "Tier '{$tier->name}' created.");
    }

    public function edit(SponsorTier $sponsorTier): View
    {
        $placements = self::availablePlacements();

        return view('sponsor-tiers.edit', compact('sponsorTier', 'placements'));
    }

    public function update(Request $request, SponsorTier $sponsorTier): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:sponsor_tiers,name,' . $sponsorTier->id],
            'display_order' => ['required', 'integer', 'min:0'],
            'price_per_year' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'logo_max_height' => ['required', 'integer', 'min:16', 'max:200'],
            'placement' => ['required', 'array', 'min:1'],
            'placement.*' => ['string', 'in:landing_hero,landing_section,app_sidebar,match_pages,standings_pages,live_scoring,leaderboard,results_pages'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $sponsorTier->only(['name', 'price_per_year', 'is_active']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $sponsorTier->update($validated);
        $this->sponsorService->clearCache();

        $this->auditLogService->log(
            $request->user(),
            'sponsor_tier_updated',
            'SponsorTier',
            $sponsorTier->id,
            $old,
            $sponsorTier->only(['name', 'price_per_year', 'is_active']),
            "Sponsor tier '{$sponsorTier->name}' updated",
        );

        return redirect()->route('sponsor-tiers.index')->with('success', "Tier '{$sponsorTier->name}' updated.");
    }

    public static function availablePlacements(): array
    {
        return [
            'landing_hero' => 'Landing Page — Hero Area',
            'landing_section' => 'Landing Page — Sponsors Section',
            'app_sidebar' => 'App — Sidebar',
            'match_pages' => 'Match Detail / Event Sponsor',
            'standings_pages' => 'Standings — General',
            'live_scoring' => 'Live Scoring Pages',
            'leaderboard' => 'Leaderboard / Public Standings Hero',
            'results_pages' => 'Results Pages (post-match)',
        ];
    }
}
