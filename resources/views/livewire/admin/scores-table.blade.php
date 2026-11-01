<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use App\Support\NameNormalizer;
use App\Support\ScoreFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /**
     * Phase 5 admin /scores Livewire island. Keeps the controller as the
     * authz gatekeeper (ScoreController@index still runs first) but owns
     * all the filtering, bulk-action, and inline-badge UX.
     */
    #[Url(as: 'season', except: '')]
    public string $season = '';

    #[Url(as: 'discipline', except: '')]
    public string $discipline = '';

    #[Url(as: 'division_id', except: '')]
    public string $divisionId = '';

    #[Url(as: 'match_id', except: '')]
    public string $matchId = '';

    #[Url(as: 'shooter', except: '')]
    public string $shooterSearch = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'nonzero', except: false)]
    public bool $nonZeroOnly = false;

    #[Url(as: 'match_search', except: '')]
    public string $matchSearch = '';

    public bool $matchDirectorOnly = false;

    /** @var array<int, int> */
    public array $selected = [];

    public string $overrideStatus = '';
    public string $overrideReason = '';
    public bool $showingOverrideSheet = false;

    public function mount(bool $matchDirectorOnly = false): void
    {
        $this->matchDirectorOnly = $matchDirectorOnly;
    }

    /**
     * Reset pagination whenever a filter changes — Livewire's standard
     * pattern. Also wipe the current selection because the rows the user
     * can see are now a different set.
     */
    public function updating($property): void
    {
        if (in_array($property, ['season', 'discipline', 'divisionId', 'matchId', 'shooterSearch', 'status', 'nonZeroOnly'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function toggleAllOnPage(bool $target): void
    {
        $ids = $this->scores->pluck('id')->all();
        if ($target) {
            $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
        } else {
            $this->selected = array_values(array_diff($this->selected, $ids));
        }
    }

    /**
     * Bulk-mark the selected scores as valid. Policy: anyone who can hit
     * this view can also bulk-validate; override (which flips a status
     * away from a validation decision) still requires the stronger
     * `override` ability enforced per-row by ScoreValidationService.
     */
    public function bulkValidate(ScoreValidationService $svc, AuditLogService $audit): void
    {
        $this->ensureBulkAuthorised();
        if (empty($this->selected)) {
            session()->flash('scores_table_error', 'Select at least one score first.');

            return;
        }

        $rows = Score::query()->whereIn('id', $this->selected)->get();
        $touched = 0;

        DB::transaction(function () use ($rows, $svc, $audit, &$touched) {
            foreach ($rows as $score) {
                $before = $score->status;
                $svc->evaluateScoreStatus($score);
                $fresh = $score->fresh();
                if ($fresh && $fresh->status !== $before) {
                    $touched++;
                    $audit->log(
                        auth()->user(),
                        'score.bulk_validate',
                        'score',
                        $score->id,
                        ['status' => $before],
                        ['status' => $fresh->status],
                    );
                }
            }
        });

        $this->selected = [];
        session()->flash('scores_table_message', "Re-evaluated {$touched} score".($touched === 1 ? '' : 's').'.');
    }

    public function startOverride(): void
    {
        $this->ensureBulkAuthorised();
        if (empty($this->selected)) {
            session()->flash('scores_table_error', 'Select at least one score first.');

            return;
        }
        $this->overrideStatus = '';
        $this->overrideReason = '';
        $this->showingOverrideSheet = true;
    }

    public function cancelOverride(): void
    {
        $this->showingOverrideSheet = false;
    }

    public function applyOverride(ScoreValidationService $svc): void
    {
        $this->ensureBulkAuthorised();
        $data = $this->validate([
            'overrideStatus' => ['required', 'in:valid,invalid'],
            'overrideReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $rows = Score::query()->whereIn('id', $this->selected)->get();
        foreach ($rows as $score) {
            $svc->overrideScoreStatus($score, $data['overrideStatus'], $data['overrideReason'], auth()->user());
        }

        $this->showingOverrideSheet = false;
        $this->selected = [];
        session()->flash('scores_table_message', 'Overrode '.$rows->count().' score'.($rows->count() === 1 ? '' : 's').' as '.$data['overrideStatus'].'.');
    }

    private function ensureBulkAuthorised(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasAnyRole(['admin', 'exco', 'developer', 'owner']), 403);
    }

    /**
     * The filtered, paginated score query. Shared by both the view and
     * the duplicate-flag computation so the "duplicate name" badge only
     * considers rows actually in the current filter.
     */
    #[Computed]
    public function scores()
    {
        return Score::query()
            ->select('scores.*')
            ->leftJoin('matches', 'matches.id', '=', 'scores.match_id')
            ->with(['match:id,name,match_type,series,season,match_date,created_by', 'user:id,name', 'division:id,name'])
            ->when($this->matchDirectorOnly && auth()->check(), function ($q) {
                $q->whereHas('match', fn ($m) => $m->where('created_by', auth()->id()));
            })
            ->when($this->matchId !== '', fn ($q) => $q->where('scores.match_id', (int) $this->matchId))
            ->when($this->status !== '', fn ($q) => $q->where('scores.status', $this->status))
            ->when($this->season !== '', fn ($q) => $q->where('matches.season', $this->season))
            ->when($this->discipline !== '', fn ($q) => $q->where('matches.match_type', $this->discipline))
            ->when($this->divisionId !== '', fn ($q) => $q->where('scores.division_id', (int) $this->divisionId))
            ->when($this->shooterSearch !== '', function ($q) {
                $needle = '%'.$this->shooterSearch.'%';
                $q->where(function ($qq) use ($needle) {
                    $qq->where('scores.shooter_name', 'like', $needle)
                       ->orWhereHas('user', fn ($uu) => $uu->where('name', 'like', $needle));
                });
            })
            ->when($this->nonZeroOnly, fn ($q) => $q->where('scores.raw_score', '>', 0))
            ->orderByDesc('matches.match_date')
            ->orderBy('scores.match_id')
            ->orderByRaw('scores.placement IS NULL, scores.placement ASC')
            ->orderBy('scores.id')
            ->paginate(30);
    }

    /**
     * Phase 5 duplicate flag: within the CURRENT PAGE, flag any two rows
     * that share the same (match_id, canonical shooter name) — the DB
     * unique(match_id, user_id) stops the hard-dup case, so this picks
     * out rows that look like the same shooter under slightly different
     * names (orphan non-member imports vs the linked member row).
     *
     * @return array<int, true>
     */
    #[Computed]
    public function duplicateMarkers(): array
    {
        $groups = [];
        foreach ($this->scores as $row) {
            $key = $row->match_id.'|'.NameNormalizer::canonical($row->shooter_name);
            $groups[$key][] = $row->id;
        }

        $markers = [];
        foreach ($groups as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $markers[$id] = true;
                }
            }
        }

        return $markers;
    }

    #[Computed]
    public function matches(): Collection
    {
        return MatchEvent::query()
            ->when($this->matchDirectorOnly && auth()->check(), fn ($q) => $q->where('created_by', auth()->id()))
            ->when($this->matchSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->matchSearch.'%'))
            ->orderByDesc('match_date')
            ->limit(60)
            ->get(['id', 'name', 'match_date', 'match_type']);
    }

    #[Computed]
    public function divisions(): Collection
    {
        return Division::query()->where('is_active', true)->orderBy('display_order')->get(['id', 'name']);
    }

    #[Computed]
    public function seasons(): array
    {
        return MatchEvent::query()
            ->whereNotNull('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->all();
    }

};
?>

<div>
    @if (session('scores_table_message'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ session('scores_table_message') }}</div>
    @endif
    @if (session('scores_table_error'))
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-800 ring-1 ring-rose-200">{{ session('scores_table_error') }}</div>
    @endif

    <div class="mb-4 rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
        <h2 class="sr-only">Score filters</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
            <label class="block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">Season</span>
                <select wire:model.live="season" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    @foreach ($this->seasons as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">Discipline</span>
                <select wire:model.live="discipline" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    <option value="PRS">PRS</option>
                    <option value="PR22">PR22</option>
                </select>
            </label>
            <label class="block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">Division</span>
                <select wire:model.live="divisionId" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    @foreach ($this->divisions as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">Status</span>
                <select wire:model.live="status" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    <option value="valid">Valid</option>
                    <option value="pending">Pending</option>
                    <option value="lapsed">Lapsed</option>
                    <option value="non_member">Non-member</option>
                    <option value="invalid">Invalid</option>
                </select>
            </label>
            <label class="block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">Shooter</span>
                <input type="text" wire:model.live.debounce.350ms="shooterSearch" placeholder="Name contains…" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            </label>
            <label class="block">
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 mb-1">Match</span>
                <div class="flex gap-2">
                    <select wire:model.live="matchId" class="min-w-0 flex-1 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All matches</option>
                        @foreach ($this->matches as $m)
                            <option value="{{ $m->id }}">{{ $m->match_date?->format('j M y') }} · {{ $m->name }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="text" wire:model.live.debounce.350ms="matchSearch" placeholder="Filter match list…" class="mt-1 w-full rounded-lg border border-stone-200 text-xs py-1 px-2 focus:ring-emerald-500 focus:border-emerald-500">
            </label>
        </div>
        <div class="mt-3 flex items-center justify-between gap-3">
            <label class="inline-flex items-center gap-2 text-xs text-stone-600">
                <input type="checkbox" wire:model.live="nonZeroOnly" class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                Hide zero-score rows
            </label>
            @if (count($selected) > 0 && auth()->user()?->hasAnyRole(['admin', 'exco', 'developer', 'owner']))
                <div class="flex items-center gap-2">
                    <span class="text-xs text-stone-500">{{ count($selected) }} selected</span>
                    <button type="button" wire:click="bulkValidate" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-700 transition">Re-validate</button>
                    <button type="button" wire:click="startOverride" class="rounded-md bg-sky-600 px-3 py-1 text-xs font-semibold text-white hover:bg-sky-700 transition">Override…</button>
                </div>
            @endif
        </div>
    </div>

    @if ($showingOverrideSheet)
        <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50/60 p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-sky-900 mb-2">Override {{ count($selected) }} score{{ count($selected) === 1 ? '' : 's' }}</h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-sky-800 mb-1">New status</span>
                    <select wire:model="overrideStatus" class="w-full rounded-lg border border-sky-300 text-sm py-2 px-3">
                        <option value="">Select…</option>
                        <option value="valid">Valid</option>
                        <option value="invalid">Invalid</option>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-sky-800 mb-1">Audit reason</span>
                    <input type="text" wire:model="overrideReason" maxlength="500" placeholder="Why is this override being applied?" class="w-full rounded-lg border border-sky-300 text-sm py-2 px-3">
                    @error('overrideReason') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    @error('overrideStatus') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </label>
            </div>
            <div class="mt-3 flex items-center gap-2">
                <button type="button" wire:click="applyOverride" class="rounded-md bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-sky-800 transition">Apply override</button>
                <button type="button" wire:click="cancelOverride" class="rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 ring-1 ring-stone-200 hover:ring-stone-300 transition">Cancel</button>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="border-b-2 border-stone-200 bg-stone-50">
                    <tr>
                        @if (auth()->user()?->hasAnyRole(['admin', 'exco', 'developer', 'owner']))
                            <th class="w-10 px-3 py-3 text-left">
                                <input type="checkbox"
                                       @checked(count($selected) > 0 && collect($this->scores->pluck('id'))->every(fn ($id) => in_array($id, $selected, true)))
                                       wire:click="toggleAllOnPage($event.target.checked)"
                                       class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            </th>
                        @endif
                        <th class="px-3 md:px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-500">Match</th>
                        <th class="px-3 md:px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-500">Shooter</th>
                        <th class="hidden md:table-cell px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-500">Division</th>
                        <th class="px-3 md:px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-500 whitespace-nowrap">Score</th>
                        <th class="hidden lg:table-cell px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-500 whitespace-nowrap">Placement</th>
                        <th class="px-3 md:px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-500">Status</th>
                        <th class="px-3 md:px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->scores as $score)
                        <tr class="border-b border-stone-100 hover:bg-stone-50/70 transition">
                            @if (auth()->user()?->hasAnyRole(['admin', 'exco', 'developer', 'owner']))
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $score->id }}" wire:model.live="selected" class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                                </td>
                            @endif
                            <td class="px-3 md:px-5 py-3 text-sm text-stone-900 align-top">
                                <a href="{{ route('matches.show', $score->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $score->match->name }}</a>
                                <div class="text-xs text-stone-400">{{ $score->match->match_date?->format('j M Y') }} · {{ $score->match->match_type }}</div>
                            </td>
                            <td class="px-3 md:px-5 py-3 text-sm text-stone-900 align-top">
                                <div class="font-medium">{{ $score->display_name }}</div>
                                <div class="md:hidden text-xs text-stone-500">{{ $score->division?->name ?? '' }}</div>
                                @if ($this->duplicateMarkers[$score->id] ?? false)
                                    <span class="mt-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800 ring-1 ring-amber-200" title="Another row on this match shares the same canonical shooter name — likely the same person under slightly different spellings.">
                                        Possible dup
                                    </span>
                                @endif
                            </td>
                            <td class="hidden md:table-cell whitespace-nowrap px-5 py-3 text-sm text-stone-700 align-top">{{ $score->division?->name ?? '—' }}</td>
                            {{-- Phase 5: wider column, tabular-nums, no clipping on the number itself. --}}
                            <td class="whitespace-nowrap px-3 md:px-5 py-3 text-sm text-right font-mono font-medium text-stone-900 tabular-nums align-top">{{ ScoreFormat::format($score->raw_score, $score->match?->match_type, $score->match?->series) }}</td>
                            <td class="hidden lg:table-cell whitespace-nowrap px-5 py-3 text-sm text-right text-stone-700 align-top">{{ $score->placement ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 md:px-5 py-3 text-sm align-top">
                                @switch($score->status)
                                    @case('valid')      <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Valid</span> @break
                                    @case('pending')    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span> @break
                                    @case('lapsed')     <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-600/20">Lapsed</span> @break
                                    @case('non_member') <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-700 ring-1 ring-inset ring-stone-500/20">Non-member</span> @break
                                    @case('invalid')    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Invalid</span> @break
                                    @default            <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ $score->status ?? '—' }}</span>
                                @endswitch
                            </td>
                            <td class="whitespace-nowrap px-3 md:px-5 py-3 text-right text-sm align-top">
                                <flux:button href="{{ route('scores.show', $score) }}" variant="ghost" size="sm" icon="eye" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->hasAnyRole(['admin', 'exco', 'developer', 'owner']) ? 8 : 7 }}" class="px-5 py-12 text-center text-sm text-stone-500">
                                No scores match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->scores->links() }}
    </div>
</div>
