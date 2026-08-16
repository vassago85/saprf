<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Selection\EligibilityEvaluator;
use App\Services\Selection\ParticipationEvaluator;
use App\Services\Selection\SelectionAthleteStateService;
use App\Services\Selection\SelectionCriteriaStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SelectionAthleteController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly EligibilityEvaluator $elg,
        private readonly ParticipationEvaluator $part,
        private readonly SelectionAthleteStateService $state,
        private readonly SelectionCriteriaStatus $criteria,
    ) {}

    public function index(Request $request, SelectionCycle $cycle): View
    {
        Gate::authorize('viewAny', SelectionAthlete::class);

        $state = $request->get('state');
        $divisionId = $request->get('division_id');

        $query = SelectionAthlete::forCycle($cycle->id)
            ->with([
                'user.membership', 'user.club', 'declaration',
                'participationSnapshot', 'claimedDivision:id,name',
            ]);
        if ($state) {
            $query->inState($state);
        }
        if ($divisionId) {
            $query->inDivision((int) $divisionId);
        }

        $athletes = $query->orderBy('state')->paginate(50)->withQueryString();
        $divisions = Division::orderBy('display_order')->get();
        $stateCounts = SelectionAthlete::forCycle($cycle->id)
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $progress = $athletes->getCollection()->mapWithKeys(function (SelectionAthlete $a) {
            $c = $this->criteria->for($a);

            return [$a->id => [
                'met' => $c['overall_met'],
                'total' => $c['overall_total'],
                'pct' => $c['overall_pct'],
            ]];
        });

        return view('selection.athletes.index', compact('cycle', 'athletes', 'divisions', 'state', 'divisionId', 'stateCounts', 'progress'));
    }

    public function create(SelectionCycle $cycle): View
    {
        Gate::authorize('create', SelectionAthlete::class);
        $divisions = Division::orderBy('display_order')->get();

        return view('selection.athletes.create', compact('cycle', 'divisions'));
    }

    public function store(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        Gate::authorize('create', SelectionAthlete::class);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id',
                Rule::unique('selection_athletes')->where(fn ($q) => $q->where('selection_cycle_id', $cycle->id))],
            'claimed_division_id' => ['nullable', 'exists:divisions,id'],
        ]);

        $athlete = SelectionAthlete::create([
            'selection_cycle_id' => $cycle->id,
            'user_id' => $data['user_id'],
            'claimed_division_id' => $data['claimed_division_id'] ?? null,
            'state' => SelectionAthlete::STATE_REGISTERED,
        ]);

        $this->audit->log(
            $request->user(),
            'selection_athlete_registered',
            'SelectionAthlete',
            $athlete->id,
            null,
            ['user_id' => $athlete->user_id, 'cycle_id' => $cycle->id],
            "Registered user #{$athlete->user_id} in cycle {$cycle->series} {$cycle->season}",
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', 'Athlete registered.');
    }

    public function show(SelectionCycle $cycle, SelectionAthlete $athlete): View
    {
        Gate::authorize('view', $athlete);
        abort_unless($athlete->selection_cycle_id === $cycle->id, 404);

        $athlete->load([
            'user.membership', 'user.club.province',
            'claimedDivision', 'declaration.capturedBy',
            'participationSnapshot', 'waivers.decidedBy', 'appeals.decidedBy',
        ]);

        $divisions = Division::orderBy('display_order')->get();
        $criteria = $this->criteria->for($athlete);

        // The "Eligibility to Compete" rule id is series-specific
        // (ELG-05 for PR22, ELG-06 for PRS). We compute it here so the
        // Blade view never has to reach into the policy JSON — doing that
        // inside a component slot's @php block was silently swallowing
        // the assignment on production and 500ing the page.
        $policyElg = collect($cycle->activePolicy?->spec_json['eligibility']['rules'] ?? [])
            ->firstWhere('check', 'declaration_form_received');
        $formRuleId = $policyElg['id'] ?? ($cycle->series === 'PR22' ? 'ELG-05' : 'ELG-06');

        return view('selection.athletes.show', compact('cycle', 'athlete', 'divisions', 'criteria', 'formRuleId'));
    }

    public function update(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete): RedirectResponse
    {
        Gate::authorize('update', $athlete);
        abort_unless($athlete->selection_cycle_id === $cycle->id, 404);

        $data = $request->validate([
            'claimed_division_id' => ['nullable', 'exists:divisions,id'],
            'manual_eligibility_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $old = $athlete->only(['claimed_division_id', 'manual_eligibility_notes']);
        $athlete->update($data);

        $this->audit->log(
            $request->user(),
            'selection_athlete_updated',
            'SelectionAthlete',
            $athlete->id,
            $old,
            $athlete->only(['claimed_division_id', 'manual_eligibility_notes']),
            'Selection athlete updated',
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', 'Athlete updated.');
    }

    public function reevaluate(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete): RedirectResponse
    {
        Gate::authorize('reevaluate', $athlete);
        abort_unless($athlete->selection_cycle_id === $cycle->id, 404);

        $this->elg->evaluate($athlete);
        $this->part->evaluate($athlete->fresh(['user.membership', 'user.club', 'cycle.activePolicy', 'declaration']));
        $this->state->recompute($athlete->fresh(['cycle.activePolicy', 'declaration']));

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', 'Re-evaluated.');
    }

    public function bulkRegister(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        Gate::authorize('create', SelectionAthlete::class);

        // Register every user who has at least one qualifying score in the
        // cycle's series/season/period, excluding those already registered.
        $existingUserIds = SelectionAthlete::forCycle($cycle->id)->pluck('user_id')->all();
        $userIds = \App\Models\Score::query()
            ->whereNotNull('user_id')
            ->whereHas('match', fn ($q) => $q
                ->where('series', $cycle->series)
                ->whereBetween('match_date', [$cycle->qualifying_period_start, $cycle->qualifying_period_end])
                ->whereIn('series_level', ['provincial', 'national', 'international', 'final']))
            ->whereNotIn('user_id', $existingUserIds)
            ->distinct()
            ->pluck('user_id');

        $created = 0;
        foreach ($userIds as $userId) {
            $mostUsed = \App\Models\Score::query()
                ->where('user_id', $userId)
                ->whereHas('match', fn ($q) => $q
                    ->where('series', $cycle->series)
                    ->whereBetween('match_date', [$cycle->qualifying_period_start, $cycle->qualifying_period_end]))
                ->selectRaw('division_id, COUNT(*) as c')
                ->groupBy('division_id')
                ->orderByDesc('c')
                ->limit(1)
                ->value('division_id');

            SelectionAthlete::create([
                'selection_cycle_id' => $cycle->id,
                'user_id' => $userId,
                'claimed_division_id' => $mostUsed,
                'state' => SelectionAthlete::STATE_REGISTERED,
            ]);
            $created++;
        }

        $this->audit->log(
            $request->user(),
            'selection_bulk_registered',
            'SelectionCycle',
            $cycle->id,
            null,
            ['created' => $created],
            "Bulk-registered {$created} athletes into cycle {$cycle->series} {$cycle->season}",
        );

        return redirect()->route('selection.cycles.athletes.index', $cycle)
            ->with('success', "Registered {$created} athletes from qualifying scores.");
    }
}
