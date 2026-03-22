<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\MatchExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MatchExpenseController extends Controller
{
    public function store(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorizeExpenseAccess($request, $match);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'cost_type' => ['nullable', 'in:fixed,per_shooter'],
            'category' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['cost_type'] ??= 'fixed';

        $match->expenses()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('matches.show', $match)
            ->with('success', 'Expense added.');
    }

    public function update(Request $request, MatchEvent $match, MatchExpense $expense): RedirectResponse
    {
        $this->authorizeExpenseAccess($request, $match);
        abort_unless($expense->match_id === $match->id, 404);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'cost_type' => ['nullable', 'in:fixed,per_shooter'],
            'category' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['cost_type'] ??= 'fixed';
        $expense->update($validated);

        return redirect()->route('matches.show', $match)
            ->with('success', 'Expense updated.');
    }

    public function destroy(Request $request, MatchEvent $match, MatchExpense $expense): RedirectResponse
    {
        $this->authorizeExpenseAccess($request, $match);
        abort_unless($expense->match_id === $match->id, 404);

        $expense->delete();

        return redirect()->route('matches.show', $match)
            ->with('success', 'Expense removed.');
    }

    private function authorizeExpenseAccess(Request $request, MatchEvent $match): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasAnyRole(['owner', 'admin']) || $match->created_by === $user->id,
            403
        );
    }
}
