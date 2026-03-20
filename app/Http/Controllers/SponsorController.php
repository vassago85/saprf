<?php

namespace App\Http\Controllers;

use App\Models\Sponsor;
use App\Models\SponsorTier;
use App\Services\AuditLogService;
use App\Services\SponsorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SponsorController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SponsorService $sponsorService,
    ) {}

    public function index(Request $request): View
    {
        $query = Sponsor::with('tier');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->input('status') === 'active') {
            $query->active();
        } elseif ($request->input('status') === 'expired') {
            $query->where(function ($q) {
                $q->where('is_active', false)->orWhere('expires_at', '<', now()->toDateString());
            });
        }

        $sponsors = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $tiers = SponsorTier::active()->ordered()->get();

        return view('sponsors.index', compact('sponsors', 'tiers', 'search'));
    }

    public function create(): View
    {
        $tiers = SponsorTier::active()->ordered()->get();

        return view('sponsors.create', compact('tiers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sponsor_tier_id' => ['required', 'exists:sponsor_tiers,id'],
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'website_url' => ['nullable', 'url', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $logoPath = $request->file('logo')->store('sponsors', 'public');

        $sponsor = Sponsor::create([
            ...$validated,
            'logo_path' => $logoPath,
            'is_active' => true,
        ]);

        $this->sponsorService->clearCache();

        $this->auditLogService->log(
            $request->user(),
            'sponsor_created',
            'Sponsor',
            $sponsor->id,
            null,
            ['name' => $sponsor->name, 'tier' => $sponsor->tier->name],
            "Sponsor '{$sponsor->name}' created",
        );

        return redirect()->route('sponsors.index')->with('success', "Sponsor '{$sponsor->name}' created.");
    }

    public function edit(Sponsor $sponsor): View
    {
        $tiers = SponsorTier::active()->ordered()->get();

        return view('sponsors.edit', compact('sponsor', 'tiers'));
    }

    public function update(Request $request, Sponsor $sponsor): RedirectResponse
    {
        $validated = $request->validate([
            'sponsor_tier_id' => ['required', 'exists:sponsor_tiers,id'],
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'website_url' => ['nullable', 'url', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $old = $sponsor->only(['name', 'sponsor_tier_id', 'is_active']);

        if ($request->hasFile('logo')) {
            if ($sponsor->logo_path) {
                Storage::disk('public')->delete($sponsor->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')->store('sponsors', 'public');
        }

        $validated['is_active'] = $request->boolean('is_active');

        $sponsor->update($validated);
        $this->sponsorService->clearCache();

        $this->auditLogService->log(
            $request->user(),
            'sponsor_updated',
            'Sponsor',
            $sponsor->id,
            $old,
            $sponsor->only(['name', 'sponsor_tier_id', 'is_active']),
            "Sponsor '{$sponsor->name}' updated",
        );

        return redirect()->route('sponsors.index')->with('success', "Sponsor '{$sponsor->name}' updated.");
    }

    public function destroy(Request $request, Sponsor $sponsor): RedirectResponse
    {
        $name = $sponsor->name;

        if ($sponsor->logo_path) {
            Storage::disk('public')->delete($sponsor->logo_path);
        }

        $sponsor->update(['is_active' => false]);
        $this->sponsorService->clearCache();

        $this->auditLogService->log(
            $request->user(),
            'sponsor_deactivated',
            'Sponsor',
            $sponsor->id,
            ['is_active' => true],
            ['is_active' => false],
            "Sponsor '{$name}' deactivated",
        );

        return redirect()->route('sponsors.index')->with('success', "Sponsor '{$name}' deactivated.");
    }
}
