<?php

namespace App\Http\Controllers;

use App\Models\RifleConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RifleConfigurationController extends Controller
{
    public function index(Request $request): View
    {
        $rifles = RifleConfiguration::forUser($request->user()->id)
            ->active()
            ->with(['make', 'model', 'calibre', 'opticMake', 'opticModel'])
            ->withCount('registrations')
            ->orderMainsFirst()
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
        $request->merge(['primary_series' => $this->normalizedPrimarySeries($request)]);

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:100'],
            'firearm_make_id' => ['nullable', 'exists:firearm_makes,id'],
            'firearm_model_id' => ['nullable', 'exists:firearm_models,id'],
            'firearm_calibre_id' => ['nullable', 'exists:firearm_calibres,id'],
            'action_description' => ['nullable', 'string', 'max:255'],
            'barrel_description' => ['nullable', 'string', 'max:255'],
            'optic_make_id' => ['nullable', 'exists:optic_makes,id'],
            'optic_model_id' => ['nullable', 'exists:optic_models,id'],
            'chassis_description' => ['nullable', 'string', 'max:255'],
            'barrel_length' => ['nullable', 'string', 'max:50'],
            'twist_rate' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'primary_series' => ['nullable', Rule::in(['PRS', 'PR22'])],
            'show_on_profile' => ['sometimes', 'boolean'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['primary_series'] = $this->normalizedPrimarySeries($request);
        $validated['show_on_profile'] = $this->normalizedShowOnProfile($request, $validated['primary_series']);

        $this->claimPrimarySeries($request->user()->id, $validated['primary_series']);

        RifleConfiguration::create($validated);

        return redirect()->route('rifle-configurations.index')
            ->with('success', "Rifle '{$validated['nickname']}' added.");
    }

    public function show(Request $request, RifleConfiguration $rifleConfiguration): View
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->load(['make', 'model', 'calibre', 'opticMake', 'opticModel', 'ammoLoads' => fn ($q) => $q->active()->orderByDesc('created_at')]);

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

        $shotLog = $rifleConfiguration->registrations()
            ->whereNotNull('shot_count')
            ->with('match:id,name,match_date')
            ->orderByDesc('created_at')
            ->get(['id', 'match_id', 'shot_count', 'created_at']);

        return view('rifle-configurations.show', compact('rifleConfiguration', 'recentScores', 'matchCount', 'shotLog'));
    }

    public function edit(Request $request, RifleConfiguration $rifleConfiguration): View
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->load(['make', 'model', 'calibre', 'opticMake', 'opticModel']);

        return view('rifle-configurations.edit', compact('rifleConfiguration'));
    }

    public function update(Request $request, RifleConfiguration $rifleConfiguration): RedirectResponse
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->merge(['primary_series' => $this->normalizedPrimarySeries($request)]);

        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:100'],
            'firearm_make_id' => ['nullable', 'exists:firearm_makes,id'],
            'firearm_model_id' => ['nullable', 'exists:firearm_models,id'],
            'firearm_calibre_id' => ['nullable', 'exists:firearm_calibres,id'],
            'action_description' => ['nullable', 'string', 'max:255'],
            'barrel_description' => ['nullable', 'string', 'max:255'],
            'optic_make_id' => ['nullable', 'exists:optic_makes,id'],
            'optic_model_id' => ['nullable', 'exists:optic_models,id'],
            'chassis_description' => ['nullable', 'string', 'max:255'],
            'barrel_length' => ['nullable', 'string', 'max:50'],
            'twist_rate' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'primary_series' => ['nullable', Rule::in(['PRS', 'PR22'])],
            'show_on_profile' => ['sometimes', 'boolean'],
        ]);

        $validated['primary_series'] = $this->normalizedPrimarySeries($request);
        $validated['show_on_profile'] = $this->normalizedShowOnProfile($request, $validated['primary_series']);

        $this->claimPrimarySeries($request->user()->id, $validated['primary_series'], $rifleConfiguration->id);

        $rifleConfiguration->update($validated);

        return redirect()->route('rifle-configurations.index')
            ->with('success', "Rifle '{$rifleConfiguration->nickname}' updated.");
    }

    public function destroy(Request $request, RifleConfiguration $rifleConfiguration): RedirectResponse
    {
        if ($rifleConfiguration->user_id !== $request->user()->id) {
            abort(403);
        }

        $rifleConfiguration->update([
            'is_active' => false,
            'primary_series' => null,
            'show_on_profile' => false,
        ]);

        return redirect()->route('rifle-configurations.index')
            ->with('success', "Rifle '{$rifleConfiguration->nickname}' removed.");
    }

    private function normalizedPrimarySeries(Request $request): ?string
    {
        $series = $request->input('primary_series');

        return in_array($series, ['PRS', 'PR22'], true) ? $series : null;
    }

    private function claimPrimarySeries(int $userId, ?string $series, ?int $exceptId = null): void
    {
        if ($series === null) {
            return;
        }

        RifleConfiguration::forUser($userId)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('primary_series', $series)
            ->update([
                'primary_series' => null,
                'show_on_profile' => false,
            ]);
    }

    private function normalizedShowOnProfile(Request $request, ?string $series): bool
    {
        return $series !== null && $request->boolean('show_on_profile');
    }
}
