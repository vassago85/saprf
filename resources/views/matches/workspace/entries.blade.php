<x-layouts.app :title="$match->name . ' — Entries'">
    @include('matches.workspace._chrome')

    <div class="mt-6 space-y-4">
        {{-- Toolbar: filters + actions. The email action is its own form so it
             is not nested inside the filter GET. --}}
        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4">
        <form method="GET" action="{{ route('matches.entries', $match) }}"
              class="flex flex-1 flex-wrap items-end gap-3">
            <div class="min-w-[180px] flex-1">
                <label class="block text-xs font-semibold text-stone-500">Search shooter</label>
                <input type="text" name="search" value="{{ $filters['search'] }}"
                       placeholder="Name or email"
                       class="mt-1 w-full rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-500">Status</label>
                <select name="status"
                        class="mt-1 rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">All</option>
                    <option value="paid" @selected($filters['status']==='paid')>Paid</option>
                    <option value="pending" @selected($filters['status']==='pending')>Pending</option>
                    <option value="waitlist" @selected($filters['status']==='waitlist')>Waitlist</option>
                    <option value="cancelled" @selected($filters['status']==='cancelled')>Cancelled</option>
                </select>
            </div>
            @if($showLiveBoard)
                <div>
                    <label class="block text-xs font-semibold text-stone-500">Live board</label>
                    <select name="board"
                            class="mt-1 rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">Everyone</option>
                        <option value="showing" @selected($filters['board']==='showing')>Showing a score</option>
                        <option value="missing" @selected($filters['board']==='missing')>Not on the board</option>
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-xs font-semibold text-stone-500">Category</label>
                <select name="category"
                        class="mt-1 rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">All</option>
                    <option value="active_member" @selected($filters['category']==='active_member')>Member</option>
                    <option value="lapsed_member" @selected($filters['category']==='lapsed_member')>Lapsed</option>
                    <option value="non_member" @selected($filters['category']==='non_member')>Non-member</option>
                    <option value="club_entry" @selected($filters['category']==='club_entry')>Club entry</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-stone-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-stone-800">
                    Filter
                </button>
                <a href="{{ route('matches.entries', $match) }}" wire:navigate
                   class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                    Reset
                </a>
            </div>
        </form>
            <div class="ml-auto flex flex-wrap gap-2">
                @can('update', $match)
                    @if(($counts['pending_payment'] ?? 0) > 0)
                        <form method="POST" action="{{ route('matches.notify-unpaid', $match) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-semibold text-amber-950 hover:bg-amber-400"
                                    onclick="return confirm('Email all {{ $counts['pending_payment'] }} unpaid shooter(s) on this match?');">
                                Email unpaid ({{ $counts['pending_payment'] }})
                            </button>
                        </form>
                    @endif
                @endcan
                {{-- Export entries for Impact used to live under the sidebar
                     "Match tools" card on Settings. It's one click away from
                     the Entries workflow where it belongs. --}}
                <a href="{{ route('matches.export-impact-scoring', $match) }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50"
                   title="Download the entries CSV for Impact scoring">
                    <flux:icon.arrow-down-tray class="size-4" />
                    Export for Impact
                </a>
                @can('update', $match)
                    <a href="#add-shooter"
                       class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        <flux:icon.plus class="size-4" />
                        Add entry
                    </a>
                @endcan
            </div>
        </div>

        {{-- Add-a-shooter card (MD/admin only). Moved from the Settings
             sidebar so adding a walk-in or comp'd entry happens right
             next to the list of existing entries. The partial handles
             its own forms and policy checks; we gate at this call site
             because the inner partial relies on `$selfEntry` /
             `$selfDefaultDivisionId` provided by the controller. --}}
        @can('update', $match)
            @include('matches.workspace._add-shooter-panel')
        @endcan

        @include('matches.workspace._apply-club-entry')

        @if($showLiveBoard && $liveBoard)
            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                <p class="font-semibold">
                    {{ $liveBoard['showing'] }} {{ \Illuminate\Support\Str::plural('entry', $liveBoard['showing']) }}
                    {{ $liveBoard['showing'] === 1 ? 'is' : 'are' }} showing a live score.
                    {{ $liveBoard['missing'] }} {{ $liveBoard['missing'] === 1 ? 'is' : 'are' }} not on the board.
                </p>
                @if($liveBoard['missing'] > 0)
                    <p class="mt-1 text-xs text-sky-900">
                        Not showing:
                        {{ collect($liveBoard['missing_names'])->take(12)->join(', ') }}@if(count($liveBoard['missing_names']) > 12)…@endif
                        <a href="{{ route('matches.entries', [$match, 'board' => 'missing']) }}" class="font-semibold underline">Show only these</a>
                    </p>
                @endif
                @if($liveBoard['unmatched'] !== [] && auth()->user()?->can('update', $match))
                    <div class="mt-3 border-t border-sky-200 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-900">On the Impact score sheet, not matched to an entry</p>
                        <p class="mt-0.5 text-xs text-sky-900">Link each Impact name to an entry on this match. Next pull posts their score.</p>
                        <div class="mt-2 space-y-2">
                            @foreach($liveBoard['unmatched'] as $u)
                                <form method="POST" action="{{ route('matches.impact-name-map', $match) }}"
                                      class="flex flex-wrap items-center gap-2 rounded-lg bg-white/60 px-2 py-1.5">
                                    @csrf
                                    <input type="hidden" name="impact_name" value="{{ $u['name'] }}">
                                    <span class="text-sm font-semibold text-stone-800 min-w-[200px]">{{ $u['name'] }}</span>
                                    <span class="text-xs text-stone-500">→</span>
                                    <select name="registration_id"
                                            class="flex-1 min-w-[220px] rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                        <option value="">— Choose an entry —</option>
                                        @foreach($liveBoard['assignable_entries'] as $entry)
                                            <option value="{{ $entry['id'] }}" @selected($u['mapped_registration_id'] === $entry['id'])>{{ $entry['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="inline-flex items-center rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">
                                        @if($u['mapped_registration_id']) Update @else Assign @endif
                                    </button>
                                    @if($u['mapped_registration_id'])
                                        <button type="submit" name="registration_id" value=""
                                                class="inline-flex items-center rounded-md border border-stone-300 bg-white px-2.5 py-1 text-xs font-semibold text-stone-600 hover:bg-stone-50"
                                                title="Remove this mapping">
                                            Unlink
                                        </button>
                                    @endif
                                </form>
                            @endforeach
                        </div>
                    </div>
                @elseif($liveBoard['unmatched'] !== [])
                    <p class="mt-1 text-xs text-sky-900">
                        On the Impact score sheet, not matched to an entry:
                        {{ collect($liveBoard['unmatched'])->pluck('name')->take(12)->join(', ') }}@if(count($liveBoard['unmatched']) > 12)…@endif
                    </p>
                @endif
            </div>
        @endif

        @if(! empty($impactExportOmitted))
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
                {{ count($impactExportOmitted) === 1 ? '1 entry was' : count($impactExportOmitted).' entries were' }}
                left out of the Impact export because {{ count($impactExportOmitted) === 1 ? 'it has' : 'they have' }} no email address:
                {{ collect($impactExportOmitted)->pluck('name')->join(', ') }}.
                The download still includes the other entries.
            </div>
        @endif

        {{-- Entries table --}}
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            @if($entries->isEmpty())
                <div class="px-6 py-12 text-center text-sm text-stone-500">
                    No entries match these filters.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold">Shooter</th>
                                @if($showLiveBoard)
                                    <th class="px-4 py-2.5 text-left font-semibold">Live score</th>
                                @endif
                                <th class="px-4 py-2.5 text-left font-semibold">Category</th>
                                <th class="px-4 py-2.5 text-left font-semibold">Division</th>
                                <th class="px-4 py-2.5 text-left font-semibold">Payment</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Fee</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Entered</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($entries as $reg)
                                @php
                                    $statusColour = match ($reg->registration_status) {
                                        'confirmed' => 'bg-emerald-50 text-emerald-800',
                                        'pending' => 'bg-amber-50 text-amber-800',
                                        'waitlisted' => 'bg-sky-50 text-sky-800',
                                        'cancelled' => 'bg-stone-100 text-stone-500 line-through',
                                        default => 'bg-stone-100 text-stone-600',
                                    };
                                    $paymentColour = match ($reg->payment_status) {
                                        'paid' => 'bg-emerald-50 text-emerald-800',
                                        'waived' => 'bg-sky-50 text-sky-800',
                                        'refunded' => 'bg-stone-100 text-stone-500',
                                        default => 'bg-amber-50 text-amber-800',
                                    };
                                @endphp
                                <tr class="hover:bg-stone-50/60">
                                    <td class="px-4 py-2.5">
                                        {{-- Shooter name is the primary click target.
                                             MD/admin lands on registrations.show which
                                             has the full edit surface (division, rifle,
                                             shots, mark-settled, withdraw) — all of
                                             which the policies already allow after the
                                             match is completed. --}}
                                        <a href="{{ route('registrations.show', $reg) }}" wire:navigate
                                           class="block font-semibold text-stone-900 hover:text-emerald-700">
                                            {{ $reg->shooter_name ?: $reg->user?->name ?: '—' }}
                                        </a>
                                        @if($reg->email)
                                            <div class="text-xs text-stone-500">{{ $reg->email }}</div>
                                        @endif
                                    </td>
                                    @if($showLiveBoard)
                                        @php
                                            $onBoard = $reg->user_id ? ($scoresByUser[$reg->user_id] ?? null) : null;
                                        @endphp
                                        <td class="px-4 py-2.5">
                                            @if($reg->registration_status === 'cancelled')
                                                <span class="text-xs text-stone-400">—</span>
                                            @elseif($onBoard)
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                                    {{ $onBoard['score'] }}@if($onBoard['stages']) · {{ $onBoard['stages'] }}@endif
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Not showing</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="px-4 py-2.5 text-stone-700">{{ $reg->feeCategoryLabel() }}</td>
                                    <td class="px-4 py-2.5 text-stone-700">{{ $reg->division?->name ?: '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $paymentColour }}">
                                            {{ \Illuminate\Support\Str::title($reg->payment_status) }} · {{ $reg->paymentMethodLabel() }}
                                        </span>
                                        @if($reg->registration_status !== 'confirmed')
                                            <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusColour }}">
                                                {{ \Illuminate\Support\Str::title($reg->registration_status) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-stone-900">
                                        R {{ number_format((float) $reg->fee_amount, 2) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs text-stone-500">
                                        {{ $reg->registered_at?->format('j M') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                        @can('update', $reg)
                                            @if($reg->hasOutstandingPayment() && $reg->canSendPaymentInquiry() && $reg->user?->email && ! str_contains((string) $reg->user->email, '@saprf.walkin'))
                                                <form method="POST" action="{{ route('registrations.payment-inquiry', $reg) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                                                        Email
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                        @if($reg->hasOutstandingPayment() && $reg->registration_status !== 'cancelled')
                                            {{-- Fast path to the mark-settled form on the
                                                 detail page. The #mark-settled anchor
                                                 lives in registrations/show.blade.php so
                                                 the MD scrolls straight to it. --}}
                                            <a href="{{ route('registrations.show', $reg) }}#mark-settled"
                                               wire:navigate
                                               class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">
                                                Mark paid
                                            </a>
                                        @else
                                            <a href="{{ route('registrations.show', $reg) }}" wire:navigate
                                               class="inline-flex items-center gap-1 rounded-md border border-stone-300 bg-white px-2.5 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50"
                                               title="Open entry to edit division, rifle, shots, or withdraw">
                                                Edit
                                            </a>
                                        @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-stone-100 bg-stone-50 px-4 py-3">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>

        {{-- Squadding card. Moved out of the Match-day details block on
             Settings (where it was buried five sections deep) so an MD
             building squads sees it beside the list of entries. Posts
             to the normal matches.update route with the match name
             hidden to pass the "required name" validation rule — the
             rest of the match's fields are untouched by the save. --}}
        @can('update', $match)
            @php
                $squaddingRaw = old('squadding_json');
                if ($squaddingRaw === null) {
                    $squaddingRaw = is_array($match->squadding_json)
                        ? json_encode($match->squadding_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $match->squadding_json;
                }
            @endphp
            <section class="rounded-xl border border-stone-200 bg-white p-5">
                <header>
                    <h3 class="text-sm font-semibold text-stone-900">Squadding</h3>
                    <p class="mt-0.5 text-xs text-stone-500">
                        Group shooters into squads. Shown on the public event page as its own card when populated.
                    </p>
                </header>

                <form method="POST" action="{{ route('matches.update', $match) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PUT')
                    {{-- UpdateMatchRequest requires name + match_type; we re-send the
                         current values so this single-field save validates without
                         touching anything else. --}}
                    <input type="hidden" name="name" value="{{ $match->name }}" />
                    <input type="hidden" name="match_type" value="{{ $match->match_type }}" />

                    <div>
                        <label for="squadding_json" class="sr-only">Squadding (JSON)</label>
                        <textarea name="squadding_json" id="squadding_json" rows="6"
                                  placeholder='[{"squad_name":"Squad A","members":["Japie Louw","Sarah de Beer"]}]'
                                  class="block w-full rounded-lg border border-stone-300 font-mono text-xs focus:border-emerald-500 focus:ring-emerald-500">{{ $squaddingRaw }}</textarea>
                        <p class="mt-1 text-xs text-stone-400">
                            List of <code>{"squad_name":"…","members":["…"]}</code> rows. Leave blank to hide the squadding card on the public page.
                        </p>
                        @error('squadding_json') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm">Save squads</flux:button>
                    </div>
                </form>
            </section>
        @endcan
    </div>
</x-layouts.app>
