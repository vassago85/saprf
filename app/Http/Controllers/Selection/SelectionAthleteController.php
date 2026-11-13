<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Selection\DeclarationAttestations;
use App\Services\Selection\DeclarationReminderService;
use App\Services\Selection\EligibilityEvaluator;
use App\Services\Selection\ParticipationEvaluator;
use App\Services\Selection\SelectionAthleteStateService;
use App\Services\Selection\SelectionCriteriaStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $search = trim($request->string('q')->toString());
        $sort = $request->string('sort')->toString();
        if (! in_array($sort, ['criteria_desc', 'criteria_asc', 'name', 'state'], true)) {
            $sort = 'criteria_desc';
        }

        $query = SelectionAthlete::forCycle($cycle->id)
            ->with([
                'user.membership', 'user.club', 'declaration',
                'participationSnapshot', 'claimedDivision:id,name',
                'cycle.activePolicy',
            ]);
        if ($state) {
            $query->inState($state);
        }
        if ($divisionId) {
            $query->inDivision((int) $divisionId);
        }
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('user', function ($user) use ($like) {
                $user->where(function ($match) use ($like) {
                    $match->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            });
        }

        // Criteria met is computed live, so sort (and the page) happen in
        // memory after the status pass. A cycle's athlete list stays small.
        $all = $query->get();
        $progress = $all->mapWithKeys(function (SelectionAthlete $a) {
            $c = $this->criteria->for($a);

            return [$a->id => [
                'met' => $c['overall_met'],
                'total' => $c['overall_total'],
                'pct' => $c['overall_pct'],
            ]];
        });

        $sorted = match ($sort) {
            'criteria_asc' => $all->sortBy(fn (SelectionAthlete $a) => [
                $progress[$a->id]['met'],
                mb_strtolower($a->user?->name ?? ''),
                $a->id,
            ]),
            'name' => $all->sortBy(fn (SelectionAthlete $a) => [
                mb_strtolower($a->user?->name ?? ''),
                $a->id,
            ]),
            'state' => $all->sortBy(fn (SelectionAthlete $a) => [
                $a->state,
                mb_strtolower($a->user?->name ?? ''),
                $a->id,
            ]),
            default => $all->sortBy(fn (SelectionAthlete $a) => [
                -$progress[$a->id]['met'],
                mb_strtolower($a->user?->name ?? ''),
                $a->id,
            ]),
        };

        $sorted = $sorted->values();
        $perPage = 50;
        $page = max(1, (int) $request->get('page', 1));
        $athletes = (new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url()],
        ))->withQueryString();

        $divisions = Division::orderBy('display_order')->get();
        $stateCounts = SelectionAthlete::forCycle($cycle->id)
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        return view('selection.athletes.index', compact(
            'cycle',
            'athletes',
            'divisions',
            'state',
            'divisionId',
            'stateCounts',
            'progress',
            'search',
            'sort',
        ));
    }

    public function remindDeclarations(Request $request, SelectionCycle $cycle, DeclarationReminderService $reminders): RedirectResponse
    {
        Gate::authorize('create', SelectionAthlete::class);

        if ($cycle->status !== 'open') {
            return redirect()->route('selection.cycles.athletes.index', $cycle)
                ->with('error', 'This cycle is not open, so shooters cannot submit the declaration. No reminders were sent.');
        }

        $result = $reminders->sendForCycle($cycle->loadMissing('activePolicy'));
        $sent = $result['sent'];
        $undeliverable = $result['undeliverable'];

        $this->audit->log(
            $request->user(),
            'selection_declaration_reminders_sent',
            'SelectionCycle',
            $cycle->id,
            null,
            ['sent' => $sent, 'undeliverable' => $undeliverable],
            "Queued {$sent} declaration reminders for {$cycle->series} {$cycle->season}",
        );

        $message = match (true) {
            $sent === 0 && $undeliverable === 0 => 'No shooters are waiting only on the declaration.',
            $sent === 0 && $undeliverable === 1 => '1 shooter is only missing the declaration, but their email address has bounced or was marked as spam.',
            $sent === 0 => "{$undeliverable} shooters are only missing the declaration, but their email addresses have bounced or were marked as spam.",
            $sent === 1 && $undeliverable === 0 => 'Queued a declaration reminder for 1 shooter.',
            $undeliverable === 0 => "Queued declaration reminders for {$sent} shooters.",
            default => "Queued declaration reminders for {$sent} shooters. {$undeliverable} more could not be emailed because the address bounced or was marked as spam.",
        };

        return redirect()->route('selection.cycles.athletes.index', $cycle)
            ->with('success', $message);
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
            'cycle.activePolicy',
        ]);

        $divisions = Division::orderBy('display_order')->get();
        $criteria = $this->criteria->for($athlete);

        // Blade @php assignments inside <x-layouts.app> slots are dropped
        // on production (Livewire ExtendedCompilerEngine). Compute every
        // declaration-card value here so the view only echoes.
        $policyElg = collect($cycle->activePolicy?->spec_json['eligibility']['rules'] ?? [])
            ->firstWhere('check', 'declaration_form_received');
        $formRuleId = $policyElg['id'] ?? ($cycle->series === 'PR22' ? 'ELG-05' : 'ELG-06');

        $formData = $athlete->declaration?->form_data ?? [];
        $attestations = null;
        if (! empty($formData['attestations']) && is_array($formData['attestations'])) {
            $attestations = [];
            foreach ($formData['attestations'] as $key => $agreed) {
                $attestations[] = ['label' => DeclarationAttestations::label($formData, (string) $key), 'agreed' => (bool) $agreed];
            }
        }
        $receivedChannel = $formData['received_channel'] ?? null;

        return view('selection.athletes.show', compact(
            'cycle',
            'athlete',
            'divisions',
            'criteria',
            'formRuleId',
            'formData',
            'attestations',
            'receivedChannel',
        ));
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
