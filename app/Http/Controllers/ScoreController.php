<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\Score;
use App\Services\ScoreValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScoreController extends Controller
{
    public function __construct(
        private readonly ScoreValidationService $scoreValidationService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $matchDirectorOnly = $user->hasRole('match_director')
            && ! $user->hasAnyRole(['developer', 'owner', 'admin']);

        $matchesQuery = MatchEvent::query();
        if ($matchDirectorOnly) {
            $matchesQuery->where('created_by', $user->id);
        }
        $matches = $matchesQuery->orderBy('match_date', 'desc')->get(['id', 'name']);

        $scores = Score::query()
            ->with(['match', 'user'])
            ->when($matchDirectorOnly, fn ($q) => $q->whereHas('match', fn ($m) => $m->where('created_by', $user->id)))
            ->when($request->filled('match_id'), fn ($q) => $q->where('match_id', $request->input('match_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(30);

        return view('scores.index', compact('scores', 'matches'));
    }

    public function show(Score $score): View
    {
        $this->authorize('view', $score);

        $score->load(['match', 'user', 'import', 'shooterLog']);

        return view('scores.show', compact('score'));
    }

    public function override(Request $request, Score $score): RedirectResponse
    {
        $this->authorize('override', $score);

        $validated = $request->validate([
            'status' => ['required', 'in:valid,invalid'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->scoreValidationService->overrideScoreStatus(
            $score,
            $validated['status'],
            $validated['reason'],
            $request->user(),
        );

        return redirect()->route('scores.show', $score)
            ->with('success', 'Score status overridden.');
    }
}
