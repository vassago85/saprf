<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Membership;
use App\Models\NationalTeamAppearance;
use App\Models\SelectionCycle;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD for SA national-team appearances (IPRF world championships
 * and other national representations).
 *
 * Domain model:
 *   - Each row is one year the shooter shot for South Africa.
 *   - The `awarded_colours` flag is TRUE on the ONE appearance that
 *     granted the shooter their Protea Colours — a career-once honour.
 *     Every other appearance by the same shooter is a national-team
 *     appearance without a fresh colours award.
 *
 * Invariant: at most one appearance per user has awarded_colours=true.
 * Enforced here on store + destroy (MySQL has no partial unique index).
 *
 *   - store: rejects when the checkbox is ticked and the shooter already
 *            has a colours-awarding appearance.
 *   - destroy: if the deleted row was the colours-awarding one and other
 *              appearances remain, auto-promotes the earliest remaining
 *              one so "ever represented SA" implies "has Protea Colours"
 *              stays true.
 */
class NationalTeamAppearanceController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $appearances = NationalTeamAppearance::query()
            ->with(['user.membership', 'division', 'selectionCycle', 'recordedBy'])
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->integer('year')))
            ->when($request->boolean('colours_only'), fn ($q) => $q->where('awarded_colours', true))
            ->when($request->filled('series'), function ($q) use ($request) {
                $q->whereHas('selectionCycle', fn ($c) => $c->where('series', $request->input('series')));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"));
            })
            ->orderByDesc('year')
            ->orderByDesc('appeared_at')
            ->paginate(50)
            ->withQueryString();

        $availableYears = NationalTeamAppearance::query()
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year');

        return view('national-team.index', [
            'appearances' => $appearances,
            'availableYears' => $availableYears,
        ]);
    }

    public function create(): View
    {
        return view('national-team.create', [
            'divisions' => Division::orderBy('display_order')->orderBy('name')->get(),
            'countries' => User::COUNTRY_OPTIONS,
            'cycles' => SelectionCycle::query()->orderByDesc('season')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shooter_lookup' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:1990,'.(now()->year + 1)],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'division_label' => ['nullable', 'string', 'max:255'],
            'championship_name' => ['required', 'string', 'max:255'],
            'host_country' => ['nullable', 'string', 'size:2', Rule::in(array_keys(User::COUNTRY_OPTIONS))],
            'placing' => ['nullable', 'integer', 'between:1,999'],
            'selection_cycle_id' => ['nullable', 'integer', 'exists:selection_cycles,id'],
            'awarded_colours' => ['nullable', 'boolean'],
            'appeared_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $this->resolveShooter($validated['shooter_lookup']);

        if ($user === null) {
            return redirect()->back()->withInput()->withErrors([
                'shooter_lookup' => 'No shooter matched that SAPRF number or name. Try the exact SAPRF number.',
            ]);
        }

        $awardsColours = (bool) ($validated['awarded_colours'] ?? false);

        // Invariant guard. If admin ticked "grants colours" but this
        // shooter already has a colours-awarding appearance elsewhere,
        // we reject rather than silently reassign — reassignment would
        // let a typo strip colours from the record that actually
        // granted them.
        if ($awardsColours) {
            $existing = $user->proteaColoursAppearance()->first();
            if ($existing !== null) {
                return redirect()->back()->withInput()->withErrors([
                    'awarded_colours' => "This shooter was already awarded Protea Colours in {$existing->year} ({$existing->championship_name}). Uncheck the box — this is a later national-team appearance, not a colours award.",
                ]);
            }
        }

        $appearance = NationalTeamAppearance::create([
            'user_id' => $user->id,
            'year' => $validated['year'],
            'division_id' => $validated['division_id'] ?? null,
            'division_label' => $validated['division_label'] ?? null,
            'championship_name' => $validated['championship_name'],
            'host_country' => $validated['host_country'] ?? null,
            'placing' => $validated['placing'] ?? null,
            'selection_cycle_id' => $validated['selection_cycle_id'] ?? null,
            'awarded_colours' => $awardsColours,
            'appeared_at' => $validated['appeared_at'],
            'recorded_by' => $request->user()?->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            $awardsColours ? 'protea_colours_awarded' : 'national_team_appearance_recorded',
            'NationalTeamAppearance',
            $appearance->id,
            null,
            [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'year' => $appearance->year,
                'championship_name' => $appearance->championship_name,
                'awarded_colours' => $awardsColours,
            ],
            $awardsColours
                ? "Protea Colours awarded to {$user->name} ({$appearance->year} {$appearance->championship_name})"
                : "National-team appearance recorded for {$user->name} ({$appearance->year} {$appearance->championship_name})",
        );

        $flash = $awardsColours
            ? "Protea Colours awarded to {$user->name} ({$appearance->year})."
            : "Appearance recorded for {$user->name} ({$appearance->year}).";

        return redirect()
            ->route('national-team.index')
            ->with('success', $flash);
    }

    public function destroy(Request $request, NationalTeamAppearance $nationalTeam): RedirectResponse
    {
        $snapshot = [
            'user_id' => $nationalTeam->user_id,
            'user_name' => $nationalTeam->user?->name,
            'year' => $nationalTeam->year,
            'championship_name' => $nationalTeam->championship_name,
            'awarded_colours' => $nationalTeam->awarded_colours,
        ];

        // Wrap in a transaction so the auto-promote step below can't leave
        // the shooter's history in a partially-modified state if anything
        // fails mid-way (e.g. race with another admin submitting).
        DB::transaction(function () use ($nationalTeam) {
            $wasColours = $nationalTeam->awarded_colours;
            $userId = $nationalTeam->user_id;

            $nationalTeam->delete();

            // If we just removed the shooter's Protea Colours entry and
            // they have other SA appearances remaining, promote the
            // earliest surviving one so "ever represented SA" continues
            // to imply "has Protea Colours". Exco can then re-adjust if
            // they want the colours attached to a specific year.
            if ($wasColours) {
                $earliest = NationalTeamAppearance::query()
                    ->where('user_id', $userId)
                    ->orderBy('year')
                    ->orderBy('appeared_at')
                    ->first();

                if ($earliest !== null) {
                    $earliest->update(['awarded_colours' => true]);
                }
            }
        });

        $this->auditLogService->log(
            $request->user(),
            'national_team_appearance_deleted',
            'NationalTeamAppearance',
            $nationalTeam->id,
            $snapshot,
            null,
            "National-team appearance removed ({$snapshot['user_name']} · {$snapshot['year']})",
        );

        return redirect()
            ->route('national-team.index')
            ->with('success', 'Appearance removed.');
    }

    /**
     * Best-effort shooter lookup. Tries SAPRF number first (that's what
     * Exco actually reference by), falls back to an exact name match.
     * Ambiguous name matches return null — Exco should re-enter with
     * the SAPRF number.
     */
    private function resolveShooter(string $lookup): ?User
    {
        $lookup = trim($lookup);

        $membership = Membership::query()
            ->where('saprf_number', $lookup)
            ->first();

        if ($membership !== null && $membership->user !== null) {
            return $membership->user;
        }

        $userMatches = User::query()
            ->where('name', $lookup)
            ->limit(2)
            ->get();

        if ($userMatches->count() === 1) {
            return $userMatches->first();
        }

        return null;
    }
}
