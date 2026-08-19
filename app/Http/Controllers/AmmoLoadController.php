<?php

namespace App\Http\Controllers;

use App\Models\AmmoLoad;
use App\Models\RifleConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmmoLoadController extends Controller
{
    public function index(Request $request): View
    {
        $rifles = RifleConfiguration::forUser($request->user()->id)
            ->active()
            ->with(['make', 'model', 'calibre', 'ammoLoads' => fn ($q) => $q->active()])
            ->withCount('registrations')
            ->orderMainsFirst()
            ->orderByDesc('created_at')
            ->get();

        return view('ammo-loads.index', compact('rifles'));
    }

    public function create(Request $request, RifleConfiguration $rifleConfiguration): View
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->load(['make', 'model', 'calibre']);

        return view('ammo-loads.create', compact('rifleConfiguration'));
    }

    public function store(Request $request, RifleConfiguration $rifleConfiguration): RedirectResponse
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:100'],
            'bullet_make' => ['nullable', 'string', 'max:100'],
            'bullet_model' => ['nullable', 'string', 'max:100'],
            'bullet_weight' => ['nullable', 'string', 'max:30'],
            'bullet_type' => ['nullable', 'string', 'max:100'],
            'brass' => ['nullable', 'string', 'max:100'],
            'primer' => ['nullable', 'string', 'max:100'],
            'powder' => ['nullable', 'string', 'max:100'],
            'charge_weight' => ['nullable', 'string', 'max:30'],
            'coal' => ['nullable', 'string', 'max:30'],
            'cbto' => ['nullable', 'string', 'max:30'],
            'velocity' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['rifle_configuration_id'] = $rifleConfiguration->id;
        $validated['firearm_calibre_id'] = $rifleConfiguration->firearm_calibre_id;

        AmmoLoad::create($validated);

        return redirect()->route('rifle-configurations.show', $rifleConfiguration)
            ->with('success', "Ammo load '{$validated['nickname']}' added to {$rifleConfiguration->nickname}.");
    }

    public function edit(Request $request, AmmoLoad $ammoLoad): View
    {
        if ($ammoLoad->user_id !== $request->user()->id) {
            abort(403);
        }

        $ammoLoad->load(['calibre', 'rifleConfiguration.make', 'rifleConfiguration.model', 'rifleConfiguration.calibre']);

        return view('ammo-loads.edit', compact('ammoLoad'));
    }

    public function update(Request $request, AmmoLoad $ammoLoad): RedirectResponse
    {
        if ($ammoLoad->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:100'],
            'bullet_make' => ['nullable', 'string', 'max:100'],
            'bullet_model' => ['nullable', 'string', 'max:100'],
            'bullet_weight' => ['nullable', 'string', 'max:30'],
            'bullet_type' => ['nullable', 'string', 'max:100'],
            'brass' => ['nullable', 'string', 'max:100'],
            'primer' => ['nullable', 'string', 'max:100'],
            'powder' => ['nullable', 'string', 'max:100'],
            'charge_weight' => ['nullable', 'string', 'max:30'],
            'coal' => ['nullable', 'string', 'max:30'],
            'cbto' => ['nullable', 'string', 'max:30'],
            'velocity' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ammoLoad->update($validated);

        return redirect()->route('rifle-configurations.show', $ammoLoad->rifle_configuration_id)
            ->with('success', "Ammo load '{$ammoLoad->nickname}' updated.");
    }

    public function destroy(Request $request, AmmoLoad $ammoLoad): RedirectResponse
    {
        if ($ammoLoad->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleId = $ammoLoad->rifle_configuration_id;
        $ammoLoad->update(['is_active' => false]);

        return redirect()->route('rifle-configurations.show', $rifleId)
            ->with('success', "Ammo load '{$ammoLoad->nickname}' removed.");
    }
}
