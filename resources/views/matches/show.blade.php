<x-layouts.app :title="$match->name">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">{{ $match->name }}</h1>

        <div class="flex items-center gap-2">
            @can('update', $match)
                <flux:button href="{{ route('matches.edit', $match) }}" variant="primary" icon="pencil-square">Edit</flux:button>
            @endcan
            <flux:button href="{{ route('matches.index') }}" variant="ghost" icon="arrow-left">Back</flux:button>
        </div>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Match Details</h2>

                <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Type</dt>
                        <dd class="mt-1.5">
                            @if ($match->match_type === 'PRS')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">PRS</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">PR22</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Series Level</dt>
                        <dd class="mt-1.5 text-sm text-stone-900 capitalize">{{ $match->series_level }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Province</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->province?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->match_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Venue</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->venue_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Location</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->venue_location ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</dt>
                        <dd class="mt-1.5">
                            @switch($match->status)
                                @case('draft')
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">Draft</span>
                                    @break
                                @case('open')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Open</span>
                                    @break
                                @case('closed')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Closed</span>
                                    @break
                                @case('completed')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Completed</span>
                                    @break
                            @endswitch
                        </dd>
                    </div>
                    @if ($match->description)
                        <div class="sm:col-span-2">
                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Description</dt>
                            <dd class="mt-1.5 text-sm text-stone-700">{{ $match->description }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Registration &amp; Fees</h2>

                <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Registration Opens</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->registration_opens_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Registration Closes</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->registration_closes_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Fee (Active)</dt>
                        <dd class="mt-1.5 text-sm font-medium text-stone-900">{{ $match->fee_active ? 'R ' . number_format($match->fee_active, 2) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Fee (Non-Member)</dt>
                        <dd class="mt-1.5 text-sm font-medium text-stone-900">{{ $match->fee_non_member ? 'R ' . number_format($match->fee_non_member, 2) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Fee (Lapsed)</dt>
                        <dd class="mt-1.5 text-sm font-medium text-stone-900">{{ $match->fee_lapsed ? 'R ' . number_format($match->fee_lapsed, 2) : '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Related</h2>

                <div class="space-y-2">
                    <a href="{{ route('registrations.index', ['match_id' => $match->id]) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.clipboard-document-list class="size-5 text-stone-400" />
                        Registrations
                        <span class="ml-auto inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">{{ $match->registrations_count ?? $match->registrations->count() }}</span>
                    </a>
                    <a href="{{ route('scores.index', ['match_id' => $match->id]) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.chart-bar class="size-5 text-stone-400" />
                        Scores
                        <span class="ml-auto inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">{{ $match->scores_count ?? $match->scores->count() }}</span>
                    </a>
                    <a href="{{ route('score-imports.index', ['match_id' => $match->id]) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.arrow-up-tray class="size-5 text-stone-400" />
                        Score Imports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <x-sponsors-strip placement="match_pages" class="mt-8 border-t border-stone-200" />
</x-layouts.app>
