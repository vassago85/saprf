<?php

namespace App\Http\Controllers;

use App\Models\Barrel;
use App\Models\BarrelShotEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Practice and non-SAPRF shot log for a barrel. Ownership check leans on
 * BarrelPolicy: if the shooter can update the barrel, they can add and edit
 * its shot entries. Everything is private to the owner.
 */
class BarrelShotEntryController extends Controller
{
    public function store(Request $request, Barrel $barrel): RedirectResponse
    {
        $this->authorize('update', $barrel);

        $validated = $this->validated($request);

        $barrel->shotEntries()->create([
            ...$validated,
            'user_id' => $barrel->user_id,
        ]);

        $barrel->recalculateRoundCount();

        return redirect()->route('barrels.show', $barrel)
            ->with('success', "Logged {$validated['shot_count']} rounds.");
    }

    public function update(Request $request, Barrel $barrel, BarrelShotEntry $shotEntry): RedirectResponse
    {
        $this->authorize('update', $barrel);
        $this->ensureBelongsToBarrel($barrel, $shotEntry);

        $shotEntry->update($this->validated($request));
        $barrel->recalculateRoundCount();

        return redirect()->route('barrels.show', $barrel)
            ->with('success', 'Shot entry updated.');
    }

    public function destroy(Request $request, Barrel $barrel, BarrelShotEntry $shotEntry): RedirectResponse
    {
        $this->authorize('update', $barrel);
        $this->ensureBelongsToBarrel($barrel, $shotEntry);

        $shotEntry->delete();
        $barrel->recalculateRoundCount();

        return redirect()->route('barrels.show', $barrel)
            ->with('success', 'Shot entry removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'fired_on' => ['required', 'date', 'before_or_equal:today'],
            'shot_count' => ['required', 'integer', 'min:1', 'max:9999'],
            'type' => ['required', Rule::in(BarrelShotEntry::TYPES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function ensureBelongsToBarrel(Barrel $barrel, BarrelShotEntry $shotEntry): void
    {
        if ($shotEntry->barrel_id !== $barrel->id) {
            abort(404);
        }
    }
}
