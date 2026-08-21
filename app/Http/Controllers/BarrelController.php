<?php

namespace App\Http\Controllers;

use App\Models\Barrel;
use App\Models\RifleConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BarrelController extends Controller
{
    public function index(Request $request): View
    {
        $barrels = Barrel::forUser($request->user()->id)
            ->with('rifleConfiguration')
            ->orderByRaw('retired_on IS NULL DESC')
            ->orderByDesc('installed_on')
            ->orderByDesc('created_at')
            ->get();

        return view('barrels.index', compact('barrels'));
    }

    public function create(Request $request): View
    {
        $rifles = RifleConfiguration::forUser($request->user()->id)
            ->active()
            ->orderBy('nickname')
            ->get();

        return view('barrels.create', compact('rifles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['user_id'] = $request->user()->id;
        $validated['round_count'] = $validated['starting_round_count'];

        $barrel = Barrel::create($validated);

        return redirect()->route('barrels.show', $barrel)
            ->with('success', "Barrel '{$validated['label']}' added.");
    }

    public function show(Request $request, Barrel $barrel): View
    {
        $this->authorize('view', $barrel);

        $barrel->load('rifleConfiguration');

        $shotEntries = $barrel->shotEntries()
            ->orderByDesc('fired_on')
            ->orderByDesc('id')
            ->get();

        return view('barrels.show', compact('barrel', 'shotEntries'));
    }

    public function edit(Request $request, Barrel $barrel): View
    {
        $this->authorize('update', $barrel);

        $rifles = RifleConfiguration::forUser($request->user()->id)
            ->active()
            ->orderBy('nickname')
            ->get();

        return view('barrels.edit', compact('barrel', 'rifles'));
    }

    public function update(Request $request, Barrel $barrel): RedirectResponse
    {
        $this->authorize('update', $barrel);

        $barrel->update($this->validated($request));
        $barrel->recalculateRoundCount();

        return redirect()->route('barrels.show', $barrel)
            ->with('success', "Barrel '{$barrel->label}' updated.");
    }

    public function destroy(Request $request, Barrel $barrel): RedirectResponse
    {
        $this->authorize('delete', $barrel);

        $barrel->delete();

        return redirect()->route('barrels.index')
            ->with('success', "Barrel '{$barrel->label}' removed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'chambering' => ['nullable', 'string', 'max:60'],
            'maker' => ['nullable', 'string', 'max:80'],
            'length_mm' => ['nullable', 'integer', 'min:100', 'max:1500'],
            'twist_rate' => ['nullable', 'string', 'max:20'],
            'starting_round_count' => ['nullable', 'integer', 'min:0', 'max:200000'],
            'installed_on' => ['nullable', 'date'],
            'retired_on' => ['nullable', 'date', 'after_or_equal:installed_on'],
            'rifle_configuration_id' => ['nullable', 'integer', 'exists:rifle_configurations,id'],
        ]);

        // Ensure the rifle they picked (if any) belongs to them.
        if (! empty($data['rifle_configuration_id'])) {
            $ownsRifle = RifleConfiguration::whereKey($data['rifle_configuration_id'])
                ->where('user_id', $request->user()->id)
                ->exists();
            if (! $ownsRifle) {
                abort(403);
            }
        }

        $data['starting_round_count'] = $data['starting_round_count'] ?? 0;

        return $data;
    }
}
