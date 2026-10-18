@php
    $statusClasses = [
        'registered' => 'bg-emerald-100 text-emerald-800',
        'open' => 'bg-stone-900 text-white',
        'opens' => 'bg-stone-100 text-stone-700',
        'closed' => 'bg-stone-100 text-stone-500',
    ];
    $discBadge = fn (string $s) => $s === 'PR22'
        ? 'bg-sky-50 text-sky-800 border-sky-200'
        : 'bg-emerald-50 text-emerald-800 border-emerald-200';
    $discBar = fn (string $s) => $s === 'PR22' ? 'border-sky-700' : 'border-emerald-700';
    $discSolid = fn (string $s) => $s === 'PR22' ? 'bg-sky-700' : 'bg-emerald-700';
    $discText = fn (string $s) => $s === 'PR22' ? 'text-sky-800' : 'text-emerald-800';
    $discBarFill = fn (string $s) => $s === 'PR22' ? 'bg-sky-700' : 'bg-emerald-700';
@endphp

<div class="space-y-5">
    {{-- 1. Identity bar --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1.5 min-w-0">
            <p class="text-sm text-stone-500">Welcome back</p>
            <h1 class="font-heading text-3xl font-bold tracking-tight text-stone-900">{{ $user->name }}</h1>
            <div class="flex flex-wrap items-center gap-2 text-sm text-stone-600">
                @if ($membership)
                    @php
                        $label = $membership->effective_status_label;
                        $pill = match (true) {
                            str_contains($label, 'Active') => 'bg-emerald-50 text-emerald-800',
                            str_contains($label, 'Lapsed') => 'bg-amber-50 text-amber-900',
                            str_contains($label, 'Expired') => 'bg-red-50 text-red-800',
                            default => 'bg-stone-100 text-stone-700',
                        };
                        $dot = match (true) {
                            str_contains($label, 'Active') => 'bg-emerald-600',
                            str_contains($label, 'Lapsed') => 'bg-amber-500',
                            str_contains($label, 'Expired') => 'bg-red-600',
                            default => 'bg-stone-400',
                        };
                    @endphp
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $pill }}">
                        <span class="size-1.5 rounded-full {{ $dot }}"></span>
                        {{ $label }}
                    </span>
                    @if ($membership->saprf_number)
                        <span>SAPRF <span class="font-mono font-semibold text-stone-800">#{{ $membership->saprf_number }}</span></span>
                    @endif
                @else
                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600">Free account</span>
                @endif
                @if ($user->province?->name)
                    <span class="text-stone-300">·</span>
                    <span>{{ $user->province->name }}</span>
                @endif
                @if ($membership?->expiry_date && $membership->isActiveMember())
                    <span class="text-stone-300">·</span>
                    <span>Renews {{ $membership->expiry_date->format('d M Y') }}</span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('standings.shooter', ['season' => $season, 'user' => $user->id]) }}"
               class="inline-flex min-h-10 items-center rounded-lg border border-stone-300 bg-white px-3.5 text-sm font-medium text-stone-800 hover:bg-stone-50">
                Public profile
            </a>
            <a href="{{ route('events.index') }}"
               class="inline-flex min-h-10 items-center rounded-lg bg-emerald-700 px-3.5 text-sm font-semibold text-white hover:bg-emerald-800">
                Find a match
            </a>
        </div>
    </header>

    {{-- 2. Next matches + attention --}}
    <section class="grid gap-4 md:grid-cols-2">
        <div class="flex flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-3.5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Next matches</p>
                    <p class="text-xs text-stone-500">{{ $scopeNote }}</p>
                </div>
                @if ($dual)
                    <div role="group" aria-label="Filter upcoming matches by discipline" class="inline-flex gap-0.5 rounded-lg bg-stone-100 p-0.5">
                        @foreach (['all' => 'All', 'PRS' => 'PRS', 'PR22' => 'PR22'] as $key => $label)
                            <button type="button" wire:click="setNextFilter('{{ $key }}')"
                                @class([
                                    'min-h-8 rounded-md px-3 text-xs font-semibold transition',
                                    'bg-white text-stone-900 shadow-sm' => $nextFilter === $key,
                                    'text-stone-500 hover:text-stone-800' => $nextFilter !== $key,
                                ])
                                aria-pressed="{{ $nextFilter === $key ? 'true' : 'false' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                @endif
            </div>

            @forelse ($nextMatches as $m)
                <a href="{{ $m['href'] }}" class="grid min-h-11 grid-cols-[4rem_minmax(0,1fr)_auto] items-center gap-3.5 border-b border-stone-100 px-5 py-3.5 text-stone-900 hover:bg-stone-50 transition">
                    <div class="flex flex-col items-center justify-center rounded-lg border border-stone-200 bg-stone-50 py-1.5 leading-tight">
                        <span class="text-[11px] font-semibold tracking-wide text-stone-500">{{ $m['month'] }}</span>
                        <span class="font-mono text-xl font-semibold">{{ $m['day'] }}</span>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <span @class(['shrink-0 rounded border px-1.5 py-0.5 text-[11px] font-bold tracking-wide', $discBadge($m['series'])])>{{ $m['series'] }}</span>
                            <span class="truncate font-semibold">{{ $m['name'] }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 text-xs text-stone-500">
                            <span>{{ $m['meta'] }}</span>
                            @if ($m['counts_for_finals'])
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">Counts for finals</span>
                            @endif
                        </div>
                    </div>
                    <span @class(['shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap', $statusClasses[$m['status_tone']] ?? $statusClasses['closed']])>
                        {{ $m['status'] }}
                    </span>
                </a>
            @empty
                <p class="px-5 py-5 text-sm text-stone-500">No upcoming matches in this discipline yet.</p>
            @endforelse

            <div class="mt-auto flex flex-wrap justify-between gap-2 bg-stone-50 px-5 py-3 text-sm">
                <a href="{{ route('events.index') }}" class="font-medium text-emerald-700 hover:underline">Show all disciplines</a>
                <a href="{{ route('events.index') }}" class="font-medium text-emerald-700 hover:underline">All events</a>
            </div>
        </div>

        @if (count($attention) > 0)
            <div class="flex flex-col gap-3 rounded-2xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Needs your attention</p>
                @foreach ($attention as $item)
                    <div @class([
                        'flex items-start gap-3 rounded-xl border p-3',
                        'border-amber-200 bg-amber-50' => ($item['tone'] ?? '') === 'amber',
                        'border-stone-200 bg-stone-50' => ($item['tone'] ?? '') !== 'amber',
                    ])>
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p @class(['font-semibold', 'text-amber-950' => ($item['tone'] ?? '') === 'amber', 'text-stone-900' => ($item['tone'] ?? '') !== 'amber'])>
                                {{ $item['title'] }}
                            </p>
                            <p @class(['text-sm', 'text-amber-900/90' => ($item['tone'] ?? '') === 'amber', 'text-stone-500' => ($item['tone'] ?? '') !== 'amber'])>
                                {{ $item['body'] }}
                                @if (! empty($item['href']) && ! empty($item['href_label']))
                                    <a href="{{ $item['href'] }}" class="font-semibold text-emerald-700 hover:underline">{{ $item['href_label'] }}</a>
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- 3. Season cards --}}
    @if (count($seasonCards) > 0)
        <div class="flex flex-wrap items-baseline justify-between gap-3 pt-2">
            <h2 class="text-lg font-semibold text-stone-900">{{ $season }} season</h2>
            <a href="{{ route('standings.public', ['season' => $season]) }}" class="text-sm font-medium text-emerald-700 hover:underline">Full standings</a>
        </div>

        <section class="grid gap-4 lg:grid-cols-2">
            @foreach ($seasonCards as $card)
                <article class="flex flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white">
                    <div @class(['flex items-center justify-between gap-2 border-b border-stone-100 border-t-4 px-5 py-4', $discBar($card['series'])])>
                        <div class="flex items-center gap-2.5">
                            <span @class(['rounded-md px-2 py-0.5 text-xs font-bold tracking-wide text-white', $discSolid($card['series'])])>{{ $card['series'] }}</span>
                            <span class="font-semibold text-stone-900">{{ $card['label'] }}</span>
                        </div>
                        <span class="text-sm text-stone-500">{{ $card['matches'] }} {{ Str::plural('match', $card['matches']) }}</span>
                    </div>

                    <div class="grid grid-cols-2">
                        <div class="space-y-0.5 border-r border-stone-100 px-5 py-4">
                            <p class="text-xs font-medium text-stone-500">National</p>
                            <p @class(['font-mono text-3xl font-semibold leading-tight', $discText($card['series'])])>
                                {{ $card['national']['rank'] ? '#'.$card['national']['rank'] : '—' }}
                            </p>
                            <p class="text-sm text-stone-600">
                                @if ($card['national']['points'] !== null)
                                    <span class="font-mono">{{ number_format((float) $card['national']['points'], 2) }}</span> pts
                                @else
                                    —
                                @endif
                                @if ($card['national']['open_rank'])
                                    · Open #{{ $card['national']['open_rank'] }}
                                @endif
                            </p>
                            @if ($card['national']['includes_provincial_points'])
                                <p class="text-[11px] text-stone-400">Includes provincial points</p>
                            @endif
                        </div>
                        <div class="space-y-0.5 px-5 py-4">
                            <p class="text-xs font-medium text-stone-500">{{ $card['provincial']['name'] }}</p>
                            <p @class(['font-mono text-3xl font-semibold leading-tight', $discText($card['series'])])>
                                {{ ($card['provincial']['has'] && $card['provincial']['rank']) ? '#'.$card['provincial']['rank'] : '—' }}
                            </p>
                            <p class="text-sm text-stone-600">
                                @if ($card['provincial']['has'] && $card['provincial']['points'] !== null)
                                    <span class="font-mono">{{ number_format((float) $card['provincial']['points'], 2) }}</span> pts
                                @else
                                    —
                                @endif
                                @if ($card['provincial']['open_rank'])
                                    · Open #{{ $card['provincial']['open_rank'] }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="px-5 pb-1">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-stone-500">
                                    <th class="py-2 font-medium">Level</th>
                                    <th class="py-2 text-right font-medium">Shot</th>
                                    <th class="py-2 text-right font-medium">Best</th>
                                    <th class="py-2 text-right font-medium">Avg place</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono">
                                @foreach ($card['levels'] as $level)
                                    <tr class="border-t border-stone-100">
                                        <td class="py-2 font-sans text-stone-800">{{ $level['label'] }}</td>
                                        <td class="py-2 text-right">{{ $level['shot'] }}</td>
                                        <td @class(['py-2 text-right', 'text-stone-400' => $level['best'] === null])>
                                            {{ $level['best'] !== null ? '#'.$level['best'] : '—' }}
                                        </td>
                                        <td @class(['py-2 text-right', 'text-stone-400' => $level['avg'] === null])>
                                            {{ $level['avg'] !== null ? number_format($level['avg'], 1) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @php $q = $card['qualification']; @endphp
                    <div class="mt-auto space-y-3 border-t border-stone-100 bg-stone-50 px-5 py-4">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-stone-900">Qualification</span>
                            @if ($q['met'])
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Requirement met</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-900">Not yet</span>
                            @endif
                        </div>

                        @if ($q['mode'] === 'weighted_pools')
                            @foreach ($q['pools'] as $pool)
                                <div class="space-y-1">
                                    <div class="flex justify-between text-sm">
                                        <span>{{ $pool['label'] }} @if($pool['detail'])<span class="text-stone-500">· {{ $pool['detail'] }}</span>@endif</span>
                                        <span class="font-mono">{{ $pool['completed'] }} / {{ $pool['required'] }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded bg-stone-200">
                                        <div class="h-full {{ $discBarFill($card['series']) }}" style="width: {{ $pool['pct'] }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                            @if (($q['oop']['required'] ?? 0) > 0)
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm">
                                    <span class="font-semibold text-amber-950">Out-of-province national (finals)</span>
                                    <span class="font-mono font-semibold text-amber-950">{{ $q['oop']['completed'] }} / {{ $q['oop']['required'] }}</span>
                                </div>
                            @endif
                        @else
                            <div class="space-y-1.5">
                                <div class="flex justify-between text-sm">
                                    <span>Nationals shot</span>
                                    <span class="font-mono">{{ $q['shot'] }} · need {{ $q['need'] }}</span>
                                </div>
                                @if (count($q['segments']) > 0)
                                    <div class="grid gap-1" style="grid-template-columns: repeat({{ count($q['segments']) }}, minmax(0, 1fr))" aria-hidden="true">
                                        @foreach ($q['segments'] as $seg)
                                            <span @class([
                                                'h-2 rounded-sm',
                                                'bg-emerald-700' => $seg === 'counting' && $card['series'] === 'PRS',
                                                'bg-emerald-300' => $seg === 'dropped' && $card['series'] === 'PRS',
                                                'bg-sky-700' => $seg === 'counting' && $card['series'] === 'PR22',
                                                'bg-sky-300' => $seg === 'dropped' && $card['series'] === 'PR22',
                                            ])></span>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($q['hint'])
                                    <p class="text-xs text-stone-500">{{ $q['hint'] }}</p>
                                @endif
                            </div>
                            @if (($q['oop']['required'] ?? 0) > 0)
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm">
                                    <span class="font-semibold text-amber-950">Out-of-province national (finals)</span>
                                    <span class="font-mono font-semibold text-amber-950">{{ $q['oop']['completed'] }} / {{ $q['oop']['required'] }}</span>
                                </div>
                            @endif
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    {{-- 4. Recent results --}}
    <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
            <h2 class="text-lg font-semibold text-stone-900">Recent results</h2>
            @if ($dual)
                <div role="group" aria-label="Filter by discipline" class="inline-flex gap-0.5 rounded-lg bg-stone-100 p-0.5">
                    @foreach (['all' => 'All', 'PRS' => 'PRS', 'PR22' => 'PR22'] as $key => $label)
                        <button type="button" wire:click="setResultsFilter('{{ $key }}')"
                            @class([
                                'min-h-8 rounded-md px-3.5 text-sm font-semibold transition',
                                'bg-white text-stone-900 shadow-sm' => $resultsFilter === $key,
                                'text-stone-500 hover:text-stone-800' => $resultsFilter !== $key,
                            ])
                            aria-pressed="{{ $resultsFilter === $key ? 'true' : 'false' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="text-left text-xs text-stone-500">
                        <th class="px-5 py-2.5 font-medium">Date</th>
                        <th class="px-2 py-2.5 font-medium">Match</th>
                        <th class="px-2 py-2.5 font-medium">Prov.</th>
                        <th class="px-2 py-2.5 text-right font-medium">Place</th>
                        <th class="px-5 py-2.5 text-right font-medium">Impacts</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentResults as $r)
                        <tr class="border-t border-stone-100">
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-sm text-stone-600">{{ $r['date'] }}</td>
                            <td class="px-2 py-3">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    @if ($r['series'])
                                        <span @class(['shrink-0 rounded border px-1.5 py-0.5 text-[11px] font-bold', $discBadge($r['series'])])>{{ $r['series'] }}</span>
                                    @endif
                                    @if ($r['href'])
                                        <a href="{{ $r['href'] }}" class="max-w-[22rem] truncate font-medium text-stone-900 hover:text-emerald-700">{{ $r['name'] }}</a>
                                    @else
                                        <span class="truncate font-medium">{{ $r['name'] }}</span>
                                    @endif
                                    @if ($r['level'])
                                        <span class="shrink-0 text-xs text-stone-500">{{ $r['level'] }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-2 py-3 text-stone-600">{{ $r['province'] }}</td>
                            <td @class([
                                'px-2 py-3 text-right font-mono font-semibold',
                                'text-emerald-700' => $r['place'] && $r['place'] <= 3,
                                'text-stone-900' => ! $r['place'] || $r['place'] > 3,
                            ])>
                                {{ $r['place'] ? '#'.$r['place'] : '—' }}
                            </td>
                            <td @class([
                                'px-5 py-3 text-right font-mono',
                                'font-semibold text-red-700' => $r['impacts'] === 0,
                                'text-stone-900' => $r['impacts'] !== 0,
                            ])>
                                {{ $r['impacts'] === null ? '—' : $r['impacts'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-stone-400">No results yet this season.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-stone-100 px-5 py-3 text-sm">
            <a href="{{ route('standings.shooter', ['season' => $season, 'user' => $user->id]) }}" class="font-medium text-emerald-700 hover:underline">All match history</a>
        </div>
    </section>

    {{-- 5. Rifles strip --}}
    @if ($rifles->flatten()->isEmpty())
        <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-dashed border-stone-300 bg-white px-5 py-4">
            <div>
                <p class="font-semibold text-stone-900">My rifles</p>
                <p class="text-sm text-stone-500">No rifles yet. Add one per discipline to link scores, ammo and barrel life.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($disciplines as $disc)
                    <a href="{{ route('rifle-configurations.create', ['primary_series' => $disc]) }}"
                       @class([
                           'inline-flex min-h-10 items-center rounded-lg border px-3.5 text-sm font-semibold',
                           'border-emerald-200 bg-emerald-50 text-emerald-800' => $disc === 'PRS',
                           'border-sky-200 bg-sky-50 text-sky-800' => $disc === 'PR22',
                       ])>
                        Add {{ $disc === 'PR22' ? 'rimfire' : 'centrefire' }} rifle
                    </a>
                @endforeach
            </div>
        </section>
    @else
        <section class="rounded-2xl border border-stone-200 bg-white px-5 py-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <p class="font-semibold text-stone-900">My rifles</p>
                <a href="{{ route('rifle-configurations.create') }}" class="text-sm font-medium text-emerald-700 hover:underline">Add rifle</a>
            </div>
            @foreach (['PRS' => 'Centrefire', 'PR22' => 'Rimfire', 'other' => 'Other'] as $key => $label)
                @php $group = $rifles->get($key, collect()); @endphp
                @if ($group->isNotEmpty())
                    <div>
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-stone-500">{{ $label }}</p>
                        <ul class="flex flex-wrap gap-2">
                            @foreach ($group as $rifle)
                                <li>
                                    <a href="{{ route('rifle-configurations.show', $rifle) }}"
                                       class="inline-flex items-center gap-2 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm font-medium text-stone-800 hover:border-emerald-300">
                                        {{ $rifle->nickname }}
                                        @if ($rifle->calibre)
                                            <span class="text-xs text-stone-400">{{ $rifle->calibre->name }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </section>
    @endif
</div>
