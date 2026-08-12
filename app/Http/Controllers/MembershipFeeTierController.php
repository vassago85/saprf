<?php

namespace App\Http\Controllers;

use App\Models\MembershipFeeTier;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MembershipFeeTierController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $tiers = MembershipFeeTier::ordered()->get();

        return view('fees.index', compact('tiers'));
    }

    public function create(): View
    {
        return view('fees.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTier($request);

        $tier = MembershipFeeTier::create($this->prepare($request, $validated));

        $this->syncDefault($tier);

        $this->auditLogService->log(
            $request->user(),
            'membership_fee_tier_created',
            'MembershipFeeTier',
            $tier->id,
            null,
            $tier->only(['slug', 'name', 'price', 'is_active', 'is_default']),
            "Fee tier '{$tier->name}' created",
        );

        return redirect()->route('fees.index')->with('success', "Fee '{$tier->name}' created.");
    }

    public function edit(MembershipFeeTier $fee): View
    {
        return view('fees.edit', ['tier' => $fee]);
    }

    public function update(Request $request, MembershipFeeTier $fee): RedirectResponse
    {
        $validated = $this->validateTier($request, $fee);

        $old = $fee->only(['slug', 'name', 'price', 'is_active', 'is_default']);

        $fee->update($this->prepare($request, $validated));

        $this->syncDefault($fee);

        $this->auditLogService->log(
            $request->user(),
            'membership_fee_tier_updated',
            'MembershipFeeTier',
            $fee->id,
            $old,
            $fee->only(['slug', 'name', 'price', 'is_active', 'is_default']),
            "Fee tier '{$fee->name}' updated",
        );

        return redirect()->route('fees.index')->with('success', "Fee '{$fee->name}' updated.");
    }

    public function destroy(Request $request, MembershipFeeTier $fee): RedirectResponse
    {
        if ($fee->memberships()->exists()) {
            return redirect()->route('fees.index')
                ->with('error', "'{$fee->name}' is in use by existing memberships and cannot be deleted. Archive it instead by unticking Active.");
        }

        $name = $fee->name;

        $this->auditLogService->log(
            $request->user(),
            'membership_fee_tier_deleted',
            'MembershipFeeTier',
            $fee->id,
            $fee->only(['slug', 'name', 'price']),
            null,
            "Fee tier '{$name}' deleted",
        );

        $fee->delete();

        return redirect()->route('fees.index')->with('success', "Fee '{$name}' deleted.");
    }

    private function validateTier(Request $request, ?MembershipFeeTier $tier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable', 'string', 'alpha_dash', 'max:50',
                Rule::unique('membership_fee_tiers', 'slug')->ignore($tier?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'display_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function prepare(Request $request, array $validated): array
    {
        $validated['slug'] = filled($validated['slug'] ?? null)
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['is_default'] = $request->boolean('is_default');

        return $validated;
    }

    /**
     * Only one tier can be the default. When this tier is (or was set as) the
     * default, clear the flag on every other tier.
     */
    private function syncDefault(MembershipFeeTier $tier): void
    {
        if ($tier->is_default) {
            MembershipFeeTier::where('id', '!=', $tier->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
