<?php

namespace App\Http\Controllers;

use App\Models\RifleConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RifleConfigurationController extends Controller
{
    public function index(Request $request): View
    {
        $rifles = RifleConfiguration::forUser($request->user()->id)
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->withCount('registrations')
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();

        return view('rifle-configurations.index', compact('rifles'));
    }

    public function create(): View
    {
        return view('rifle-configurations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:100'],
            'firearm_make_id' => ['nullable', 'exists:firearm_makes,id'],
            'firearm_model_id' => ['nullable', 'exists:firearm_models,id'],
            'firearm_calibre_id' => ['nullable', 'exists:firearm_calibres,id'],
            'optic_description' => ['nullable', 'string', 'max:255'],
            'barrel_length' => ['nullable', 'string', 'max:50'],
            'twist_rate' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['is_primary'] = $request->boolean('is_primary');

        if ($validated['is_primary']) {
            RifleConfiguration::forUser($request->user()->id)->update(['is_primary' => false]);
        }

        RifleConfiguration::create($validated);

        return redirect()->route('rifle-configurations.index')
            ->with('success', "Rifle '{$validated['nickname']}' added.");
    }

    public function show(Request $request, RifleConfiguration $rifleConfiguration): View
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->load(['make', 'model', 'calibre', 'ammoLoads' => fn ($q) => $q->active()->orderByDesc('created_at')]);

        $recentScores = $request->user()->scores()
            ->whereHas('match.registrations', function ($q) use ($rifleConfiguration, $request) {
                $q->where('rifle_configuration_id', $rifleConfiguration->id)
                    ->where('user_id', $request->user()->id);
            })
            ->with('match')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $matchCount = $rifleConfiguration->registrations()->count();

        return view('rifle-configurations.show', compact('rifleConfiguration', 'recentScores', 'matchCount'));
    }

    public function edit(Request $request, RifleConfiguration $rifleConfiguration): View
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->load(['make', 'model', 'calibre']);

        return view('rifle-configurations.edit', compact('rifleConfiguration'));
    }

    public function update(Request $request, RifleConfiguration $rifleConfiguration): RedirectResponse
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:100'],
            'firearm_make_id' => ['nullable', 'exists:firearm_makes,id'],
            'firearm_model_id' => ['nullable', 'exists:firearm_models,id'],
            'firearm_calibre_id' => ['nullable', 'exists:firearm_calibres,id'],
            'optic_description' => ['nullable', 'string', 'max:255'],
            'barrel_length' => ['nullable', 'string', 'max:50'],
            'twist_rate' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $validated['is_primary'] = $request->boolean('is_primary');

        if ($validated['is_primary']) {
            RifleConfiguration::forUser($request->user()->id)
                ->where('id', '!=', $rifleConfiguration->id)
                ->update(['is_primary' => false]);
        }

        $rifleConfiguration->update($validated);

        return redirect()->route('rifle-configurations.index')
            ->with('success', "Rifle '{$rifleConfiguration->nickname}' updated.");
    }

    public function destroy(Request $request, RifleConfiguration $rifleConfiguration): RedirectResponse
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->update(['is_active' => false]);

        return redirect()->route('rifle-configurations.index')
            ->with('success', "Rifle '{$rifleConfiguration->nickname}' removed.");
    }
}
