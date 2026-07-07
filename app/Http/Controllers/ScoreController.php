<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\Score;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScoreController extends Controller
{
    public function __construct(
        private readonly ScoreValidationService $scoreValidationService,
        private readonly StandingsCalculationService $standingsService,
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

    /**
     * MD score-entry screen: shows every registered shooter for a match plus
     * any existing score rows, letting the MD enter/adjust Day 1 and Day 2
     * (Day 2 only for 2-day matches) with a single Save.
     */
    public function entry(MatchEvent $match): View
    {
        $this->authorize('update', $match);

        $match->load(['registrations.user.division', 'registrations.user.categories']);

        // Pull all existing scores for this match keyed by user_id so we can
        // pre-populate the form. Also include "orphan" scores (imported CSV rows
        // that didn't match a registered user) so MDs can still edit them.
        $existingScores = Score::where('match_id', $match->id)
            ->with(['user:id,name', 'division:id,name'])
            ->get();

        $scoresByUserId = $existingScores->whereNotNull('user_id')->keyBy('user_id');

        // Combine registered shooters + unregistered users with imported scores.
        $rows = collect();
        foreach ($match->registrations as $registration) {
            if (! $registration->user_id) {
                continue;
            }
            $rows->push([
                'user_id' => $registration->user_id,
                'name' => $registration->user?->name ?? $registration->shooter_name,
                'division' => $registration->user?->division?->name,
                'score' => $scoresByUserId->get($registration->user_id),
            ]);
        }

        $registeredUserIds = $rows->pluck('user_id')->all();
        foreach ($existingScores as $score) {
            if ($score->user_id && ! in_array($score->user_id, $registeredUserIds, true)) {
                $rows->push([
                    'user_id' => $score->user_id,
                    'name' => $score->user?->name ?? $score->shooter_name,
                    'division' => $score->division?->name,
                    'score' => $score,
                ]);
            }
        }

        $rows = $rows->sortBy(fn ($r) => strtolower((string) $r['name']))->values();

        return view('scores.entry', [
            'match' => $match,
            'rows' => $rows,
            'isTwoDay' => $match->isMultiDay(),
            'orphanScores' => $existingScores->whereNull('user_id')->values(),
        ]);
    }

    /**
     * Save the manual score entries for a match. Rows with no day1/day2 input
     * are skipped. Any existing score with the same match+user is updated.
     */
    public function storeEntry(Request $request, MatchEvent $match): RedirectResponse
    {
        $this->authorize('update', $match);

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'entries.*.day1' => ['nullable', 'numeric', 'min:0'],
            'entries.*.day2' => ['nullable', 'numeric', 'min:0'],
        ]);

        $isTwoDay = $match->isMultiDay();
        $touched = 0;

        DB::transaction(function () use ($match, $validated, $isTwoDay, &$touched): void {
            foreach ($validated['entries'] as $entry) {
                $day1 = isset($entry['day1']) && $entry['day1'] !== '' && $entry['day1'] !== null
                    ? (float) $entry['day1']
                    : null;
                $day2 = $isTwoDay && isset($entry['day2']) && $entry['day2'] !== '' && $entry['day2'] !== null
                    ? (float) $entry['day2']
                    : null;

                // Skip completely empty rows.
                if ($day1 === null && $day2 === null) {
                    continue;
                }

                $user = \App\Models\User::with('division')->find($entry['user_id']);
                if (! $user) {
                    continue;
                }

                $score = Score::firstOrNew([
                    'match_id' => $match->id,
                    'user_id' => $user->id,
                ]);

                $score->fill([
                    'shooter_name' => $user->name,
                    'day1_raw_score' => $day1,
                    'day2_raw_score' => $day2,
                    'division_id' => $score->division_id ?? $user->division_id,
                    'status' => 'pending',
                    'is_member' => true,
                    'match_date' => $match->match_date,
                    'counts_for_log' => true,
                    'counts_for_season' => true,
                ]);
                // raw_score + provincial_raw_score are auto-computed via the model booted() hook.
                $score->save();

                // Attach shooter's active categories (idempotent — sync replaces).
                $categoryIds = $user->categories->pluck('id')->all();
                if (! empty($categoryIds)) {
                    $score->categories()->syncWithoutDetaching($categoryIds);
                }

                $touched++;
            }

            // Validate each score (membership status, etc.) and then rank the match.
            Score::where('match_id', $match->id)->get()->each(
                fn (Score $s) => $this->scoreValidationService->evaluateScoreStatus($s),
            );
            $this->standingsService->recalculateForMatch($match);
        });

        return redirect()->route('scores.entry', $match)
            ->with('success', "Saved {$touched} score entry".($touched === 1 ? '' : 'ies').".");
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
