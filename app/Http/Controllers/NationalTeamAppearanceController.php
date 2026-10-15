<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Membership;
use App\Models\NationalTeam;
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
 * Admin CRUD for SA national-team appearances (IPRF worlds + comparable
 * events). Each row = one year the shooter represented SA.
 *
 * Invariant: at most ONE appearance per user has awarded_colours=true —
 * Protea Colours is a career-once honour.
 *
 *   - store/update: reject when ticking the flag would create a second
 *     colours-awarding row for the same shooter.
 *   - destroy: if the deleted row carried the colours flag and other
 *     appearances remain, auto-promote the earliest surviving one so
 *     "ever represented SA" implies "has Protea Colours" stays true.
 */
class NationalTeamAppearanceController extends Controller
{
    /**
     * Fields whose before/after values are captured in the audit log on
     * every update, in a single place so add/remove is one edit.
     */
    private const AUDIT_FIELDS = [
        'year', 'division_id', 'division_label', 'role', 'championship_name',
        'host_country', 'placing', 'awarded_colours', 'notes', 'appeared_at',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $appearances = NationalTeamAppearance::query()
            ->with(['user.membership', 'division', 'selectionCycle', 'recordedBy'])
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->integer('year')))
            ->when($request->boolean('colours_only'), fn ($q) => $q->where('awarded_colours', true))
            ->when($request->filled('series'), fn ($q) => $q->whereHas('selectionCycle',
                fn ($c) => $c->where('series', $request->input('series'))))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('user',
                fn ($u) => $u->where('name', 'like', '%'.$request->input('search').'%')))
            ->orderByDesc('year')
            ->orderByDesc('appeared_at')
            ->paginate(50)
            ->withQueryString();

        return view('national-team.index', [
            'appearances' => $appearances,
            'availableYears' => NationalTeamAppearance::query()
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year'),
        ]);
    }

    public function create(): View
    {
        return view('national-team.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shooter_lookup' => ['required', 'string', 'max:255'],
            ...$this->payloadRules(),
        ]);

        $user = $this->resolveShooter($data['shooter_lookup']);

        if ($user === null) {
            return back()->withInput()->withErrors([
                'shooter_lookup' => 'No shooter matched that SAPRF number or name. Try the exact SAPRF number.',
            ]);
        }

        [$awardsColours, $role] = $this->normaliseColoursAndRole($data);

        // Reject double-award (store path: any existing colours row is a conflict).
        if ($awardsColours && ($existing = $user->proteaColoursAppearance()->first()) !== null) {
            return back()->withInput()->withErrors([
                'awarded_colours' => "This shooter was already awarded Protea Colours in {$existing->year} ({$existing->championship_name}). Uncheck the box — this is a later national-team appearance, not a colours award.",
            ]);
        }

        $team = $this->resolveParentTeam($data);

        $appearance = NationalTeamAppearance::create($this->persistPayload($data, $team, $awardsColours, $role) + [
            'user_id' => $user->id,
            'recorded_by' => $request->user()?->id,
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

        return redirect()
            ->route('appearances.index')
            ->with('success', $awardsColours
                ? "Protea Colours awarded to {$user->name} ({$appearance->year})."
                : "Appearance recorded for {$user->name} ({$appearance->year}).");
    }

    public function edit(NationalTeamAppearance $appearance): View
    {
        $appearance->load(['user.membership', 'division', 'selectionCycle', 'nationalTeam']);

        return view('national-team.edit', $this->formData() + ['appearance' => $appearance]);
    }

    public function update(Request $request, NationalTeamAppearance $appearance): RedirectResponse
    {
        $data = $request->validate($this->payloadRules());

        [$awardsColours, $role] = $this->normaliseColoursAndRole($data);

        // Reject double-award (edit path: a DIFFERENT existing row is the conflict).
        if ($awardsColours && ! $appearance->awarded_colours) {
            $existing = $appearance->user?->proteaColoursAppearance()->first();
            if ($existing !== null && $existing->id !== $appearance->id) {
                return back()->withInput()->withErrors([
                    'awarded_colours' => "This shooter was already awarded Protea Colours in {$existing->year} ({$existing->championship_name}). Uncheck the box on that other appearance first.",
                ]);
            }
        }

        $team = $this->resolveParentTeam($data);

        $previous = $appearance->only(self::AUDIT_FIELDS);
        $appearance->update($this->persistPayload($data, $team, $awardsColours, $role));

        $this->auditLogService->log(
            $request->user(),
            'national_team_appearance_updated',
            'NationalTeamAppearance',
            $appearance->id,
            $previous,
            $appearance->only(self::AUDIT_FIELDS),
            "National-team appearance updated ({$appearance->user?->name} · {$appearance->year})",
        );

        // Returning admin from the team-year roster → send them back there.
        if ($request->input('back_to') === 'team_year') {
            return redirect()
                ->route('team-years.edit', ['teamYear' => $team->id])
                ->with('status', "Appearance updated for {$appearance->user?->name}.");
        }

        return redirect()
            ->route('appearances.index', ['year' => $appearance->year])
            ->with('success', 'Appearance updated.');
    }

    public function destroy(Request $request, NationalTeamAppearance $appearance): RedirectResponse
    {
        $snapshot = [
            'user_id' => $appearance->user_id,
            'user_name' => $appearance->user?->name,
            'year' => $appearance->year,
            'championship_name' => $appearance->championship_name,
            'awarded_colours' => $appearance->awarded_colours,
            'national_team_id' => $appearance->national_team_id,
        ];

        // Wrap so the auto-promote step can't leave history in a partial state.
        DB::transaction(function () use ($appearance) {
            $wasColours = $appearance->awarded_colours;
            $userId = $appearance->user_id;

            $appearance->delete();

            // If we removed the colours-awarding row, promote the earliest
            // surviving appearance so "represented SA" ⇒ "has colours" holds.
            if ($wasColours) {
                NationalTeamAppearance::query()
                    ->where('user_id', $userId)
                    ->orderBy('year')
                    ->orderBy('appeared_at')
                    ->first()
                    ?->update(['awarded_colours' => true]);
            }
        });

        $this->auditLogService->log(
            $request->user(),
            'national_team_appearance_deleted',
            'NationalTeamAppearance',
            $appearance->id,
            $snapshot,
            null,
            "National-team appearance removed ({$snapshot['user_name']} · {$snapshot['year']})",
        );

        if ($request->input('back_to') === 'team_year' && $snapshot['national_team_id']) {
            return redirect()
                ->route('team-years.edit', ['teamYear' => $snapshot['national_team_id']])
                ->with('status', 'Appearance removed.');
        }

        return redirect()
            ->route('appearances.index')
            ->with('success', 'Appearance removed.');
    }

    /** Shared validation rules for the store + update payload (minus shooter_lookup). */
    private function payloadRules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:1990,'.(now()->year + 1)],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'division_label' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(array_keys(NationalTeamAppearance::ROLES))],
            'championship_name' => ['required', 'string', 'max:255'],
            'host_country' => ['nullable', 'string', 'size:2', Rule::in(array_keys(NationalTeamAppearance::HOST_COUNTRIES))],
            'placing' => ['nullable', 'integer', 'between:1,999'],
            'selection_cycle_id' => ['nullable', 'integer', 'exists:selection_cycles,id'],
            'awarded_colours' => ['nullable', 'boolean'],
            'appeared_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Dropdown / option data shared by the create + edit views. */
    private function formData(): array
    {
        return [
            'divisions' => Division::orderBy('display_order')->orderBy('name')->get(),
            'countries' => NationalTeamAppearance::HOST_COUNTRIES,
            'cycles' => SelectionCycle::query()->orderByDesc('season')->get(),
        ];
    }

    /**
     * Normalise the colours flag + role together: managers never get
     * colours regardless of what the admin ticked.
     *
     * @return array{0: bool, 1: string}
     */
    private function normaliseColoursAndRole(array $data): array
    {
        $role = $data['role'] ?? NationalTeamAppearance::ROLE_SHOOTER;
        $awards = (bool) ($data['awarded_colours'] ?? false)
            && $role !== NationalTeamAppearance::ROLE_MANAGER;

        return [$awards, $role];
    }

    /**
     * Find-or-create the parent NationalTeam header so every appearance
     * for the same (year, championship) hangs off one shared record.
     */
    private function resolveParentTeam(array $data): NationalTeam
    {
        $discipline = str_contains(strtolower((string) $data['championship_name']), 'rimfire')
            ? 'rimfire' : 'centrefire';

        return NationalTeam::firstOrCreate(
            ['year' => $data['year'], 'championship_name' => $data['championship_name']],
            ['discipline' => $discipline, 'host_country' => $data['host_country'] ?? null],
        );
    }

    /** Attribute payload shared by create() + update(). */
    private function persistPayload(array $data, NationalTeam $team, bool $awardsColours, string $role): array
    {
        return [
            'year' => $data['year'],
            'division_id' => $data['division_id'] ?? null,
            'division_label' => $data['division_label'] ?? null,
            'role' => $role,
            'national_team_id' => $team->id,
            'championship_name' => $data['championship_name'],
            'host_country' => $data['host_country'] ?? null,
            'placing' => $data['placing'] ?? null,
            'selection_cycle_id' => $data['selection_cycle_id'] ?? null,
            'awarded_colours' => $awardsColours,
            'appeared_at' => $data['appeared_at'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Best-effort shooter lookup. Tries SAPRF number first (Exco's
     * canonical reference), falls back to an EXACT name match. Ambiguous
     * name matches return null — re-enter with the SAPRF number.
     */
    private function resolveShooter(string $lookup): ?User
    {
        $lookup = trim($lookup);

        $membership = Membership::query()->where('saprf_number', $lookup)->first();
        if ($membership?->user !== null) {
            return $membership->user;
        }

        $matches = User::query()->where('name', $lookup)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
