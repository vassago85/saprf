@php
    use Illuminate\Support\Str;

    $hero = $heroes[$activeHeroDiscipline] ?? null;
    $chart = $hero['chart'] ?? null;

    // --- Chart layout helpers (pure PHP, no new Blade deps) ---
    // The hero chart is rendered as inline SVG so it degrades to plain text
    // for screen readers and copies cleanly into print/PDF exports.
    if ($chart && count($chart['points']) >= 1) {
        $chartPad = ['top' => 20, 'right' => 20, 'bottom' => 30, 'left' => 44];
        $chartW = 640;
        $chartH = 200;
        $innerW = $chartW - $chartPad['left'] - $chartPad['right'];
        $innerH = $chartH - $chartPad['top'] - $chartPad['bottom'];
        $yMin = (float) $chart['y_min'];
        $yMax = (float) $chart['y_max'];
        $pointsRaw = $chart['points'];
        $count = count($pointsRaw);

        $xFor = fn (int $i) => $chartPad['left'] + ($count <= 1 ? $innerW / 2 : $i * ($innerW / max(1, $count - 1)));
        $yFor = fn (float $pct) => $chartPad['top'] + (($yMax - $pct) / max(0.01, $yMax - $yMin)) * $innerH;

        $svgPoints = [];
        foreach ($pointsRaw as $i => $p) {
            $svgPoints[] = [
                'x' => round($xFor($i), 1),
                'y' => round($yFor((float) $p['pct']), 1),
                'pct' => (float) $p['pct'],
                'label' => $p['date_full'] ?? $p['date'],
                'name' => $p['name'],
            ];
        }

        $polyline = implode(' ', array_map(fn ($p) => "{$p['x']},{$p['y']}", $svgPoints));
        $areaPath = 'M'.$svgPoints[0]['x'].','.$svgPoints[0]['y'];
        for ($i = 1; $i < $count; $i++) {
            $areaPath .= ' L'.$svgPoints[$i]['x'].','.$svgPoints[$i]['y'];
        }
        $areaPath .= ' L'.$svgPoints[$count - 1]['x'].','.($chartPad['top'] + $innerH);
        $areaPath .= ' L'.$svgPoints[0]['x'].','.($chartPad['top'] + $innerH).' Z';

        // Y gridlines at 4 nice levels across the visible range.
        $gridlines = [];
        for ($g = 0; $g <= 3; $g++) {
            $v = $yMax - ($yMax - $yMin) * ($g / 3);
            $gridlines[] = ['y' => $chartPad['top'] + ($g / 3) * $innerH, 'label' => (int) round($v).'%'];
        }
    }

    $discBadgeClass = fn (string $s) => $s === 'PR22'
        ? 'border border-sky-200 bg-sky-50 text-sky-800'
        : 'border border-emerald-200 bg-emerald-50 text-emerald-800';

    $discBarFillClass = fn (string $s) => $s === 'PR22' ? 'bg-sky-700' : 'bg-emerald-700';
    $discSolidClass = fn (string $s) => $s === 'PR22' ? 'bg-sky-700' : 'bg-emerald-700';
    $discTextClass = fn (string $s) => $s === 'PR22' ? 'text-sky-800' : 'text-emerald-800';

    $pillClass = fn (string $tone) => match ($tone) {
        'amber' => 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100',
        'red' => 'border-red-300 bg-red-50 text-red-900 hover:bg-red-100',
        'sky' => 'border-sky-300 bg-sky-50 text-sky-900 hover:bg-sky-100',
        default => 'border-stone-200 bg-stone-50 text-stone-800 hover:bg-stone-100',
    };
    $pillCountClass = fn (string $tone) => match ($tone) {
        'amber' => 'bg-amber-500 text-white',
        'red' => 'bg-red-600 text-white',
        'sky' => 'bg-sky-600 text-white',
        default => 'bg-stone-500 text-white',
    };
@endphp

<div class="mx-auto max-w-[1360px] space-y-5">
    {{-- 1 ── Slim header: greeting + name + status chips + CTAs ───────────── --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0 space-y-1">
            <p class="text-sm text-stone-500">Welcome back, {{ Str::of($user->name)->before(' ') }}</p>
            <h1 class="font-heading text-3xl font-bold tracking-tight text-stone-900 sm:text-[2.1rem] leading-[1.05]">
                Dashboard
            </h1>
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                @if ($membership)
                    @php
                        $statusLabel = $membership->effective_status_label;
                        $isActive = str_contains($statusLabel, 'Active');
                    @endphp
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-semibold',
                        'bg-emerald-100 text-emerald-800' => $isActive,
                        'bg-amber-50 text-amber-900' => str_contains($statusLabel, 'Lapsed'),
                        'bg-red-50 text-red-800' => str_contains($statusLabel, 'Expired'),
                        'bg-stone-100 text-stone-700' => ! $isActive && ! str_contains($statusLabel, 'Lapsed') && ! str_contains($statusLabel, 'Expired'),
                    ])>
                        @if ($isActive)<span class="size-1.5 rounded-full bg-emerald-600"></span>@endif
                        {{ $statusLabel }} · {{ $season }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 font-semibold text-stone-600">Free account</span>
                @endif
                @if ($user->club?->name)
                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 font-semibold text-stone-700">{{ $user->club->name }}</span>
                @endif
                @if ($user->province?->name)
                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 font-semibold text-stone-700">{{ $user->province->name }}</span>
                @endif
                @if (count($disciplines) > 0)
                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 font-semibold text-stone-700">
                        {{ collect($disciplines)->map(fn ($d) => $d === 'PR22' ? 'PR22 (Rimfire)' : 'PRS (Centrefire)')->join(' · ') }}
                    </span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('standings.shooter', ['season' => $season, 'user' => $user->id]) }}"
               class="inline-flex min-h-10 items-center rounded-lg border border-stone-300 bg-white px-3.5 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                Public profile
            </a>
            <a href="{{ route('events.index') }}"
               class="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-emerald-700 px-3.5 text-sm font-semibold text-white hover:bg-emerald-800">
                Find a match <span aria-hidden="true">→</span>
            </a>
        </div>
    </header>

    {{-- 2 ── Attention pills (hidden entirely when nothing needs attention) ── --}}
    @if (count($attention) > 0)
        <section aria-labelledby="attn-h" class="space-y-2">
            <h2 id="attn-h" class="sr-only">Needs your attention</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($attention as $item)
                    @php
                        $tone = $item['pill_tone'] ?? 'sky';
                        $href = $item['href'] ?? null;
                        $Tag = $href ? 'a' : 'div';
                    @endphp
                    <{{ $Tag }}
                        @if ($href) href="{{ $href }}" @endif
                        class="flex items-center gap-2.5 rounded-xl border px-3 py-2 pl-2 text-left transition {{ $pillClass($tone) }}">
                        <span class="inline-flex min-h-[1.4rem] min-w-[1.4rem] items-center justify-center rounded-full px-1.5 text-[11px] font-bold {{ $pillCountClass($tone) }}">
                            {{ $item['pill_count'] }}
                        </span>
                        <span class="min-w-0 leading-tight">
                            <span class="block text-[13px] font-semibold">{{ $item['title'] }}</span>
                            <span class="block text-[11.5px] opacity-80">
                                {{ $item['body'] }}
                                @if (! empty($item['href_label']))
                                    <span class="underline">{{ $item['href_label'] }}</span>
                                @endif
                            </span>
                        </span>
                        @if ($href)
                            <span aria-hidden="true" class="ml-1 opacity-60">→</span>
                        @endif
                    </{{ $Tag }}>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 3 ── Dark hero: season standing + match-% trend ───────────────────── --}}
    @if ($hero || count($heroDisciplines) > 0)
        <section aria-labelledby="hero-h"
                 class="relative isolate overflow-hidden rounded-2xl bg-[#111113] text-white shadow-xl">
            <h2 id="hero-h" class="sr-only">Season standing</h2>

            {{-- Subtle emerald wash + gridlines, decorative only. --}}
            <div class="pointer-events-none absolute inset-0" aria-hidden="true"
                 style="background:
                    radial-gradient(600px 300px at 0% 0%, rgba(16,185,129,.22), transparent 60%),
                    radial-gradient(500px 260px at 100% 100%, rgba(16,185,129,.10), transparent 60%);"></div>
            <div class="pointer-events-none absolute inset-0" aria-hidden="true"
                 style="background-image:
                    linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
                    background-size:28px 28px;
                    mask-image: linear-gradient(90deg, transparent, #000 40%);
                    -webkit-mask-image: linear-gradient(90deg, transparent, #000 40%);"></div>
            {{-- Decorative reticle --}}
            <svg class="pointer-events-none absolute -top-14 -right-14 size-[260px] opacity-[0.06]" viewBox="0 0 200 200" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="100" cy="100" r="96"/>
                <circle cx="100" cy="100" r="60"/>
                <path d="M100 0v80M100 120v80M0 100h80M120 100h80"/>
                <path d="M100 86v-4M100 118v-4M86 100h-4M118 100h-4" stroke-width="3"/>
            </svg>

            <div class="relative grid gap-0 xl:grid-cols-[minmax(300px,380px)_1fr]">
                {{-- Left: standing stats --}}
                <div class="border-b border-white/10 p-6 xl:border-b-0 xl:border-r">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-stone-300 sm:text-sm">
                            {{ $season }} · {{ $hero['label'] ?? ($activeHeroDiscipline.' season standing') }}
                        </p>
                        @if (count($heroDisciplines) > 1)
                            <div role="group" aria-label="Hero discipline" class="inline-flex gap-0.5 rounded-lg bg-white/10 p-0.5 text-xs font-semibold">
                                @foreach ($heroDisciplines as $d)
                                    <button type="button" wire:click="setHeroDiscipline('{{ $d }}')"
                                        @class([
                                            'min-h-7 rounded-md px-3 transition',
                                            'bg-white text-stone-900' => $d === $activeHeroDiscipline,
                                            'text-stone-300 hover:text-white' => $d !== $activeHeroDiscipline,
                                        ])
                                        aria-pressed="{{ $d === $activeHeroDiscipline ? 'true' : 'false' }}">{{ $d }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if ($hero && $hero['rank'])
                        <div class="mt-3 flex items-end gap-3">
                            <div class="font-heading font-bold leading-[0.82] tracking-tight tabular-nums text-[6rem] sm:text-[7rem]">
                                <sup class="relative -top-5 mr-0.5 text-emerald-400 text-[2.5rem]">#</sup>{{ $hero['rank'] }}
                            </div>
                            <div class="pb-1 text-sm text-stone-300">
                                @if ($hero['total_ranked'])
                                    <b class="text-white">of {{ number_format((int) $hero['total_ranked']) }}</b> ranked
                                @endif
                                @if ($hero['movement_label'])
                                    <div class="mt-1 text-xs text-emerald-300">{{ $hero['movement_label'] }}</div>
                                @endif
                            </div>
                        </div>

                        <dl class="mt-5 grid grid-cols-3 gap-2">
                            <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2.5">
                                <dt class="text-xs text-stone-300">Points</dt>
                                <dd class="font-heading text-2xl font-bold leading-tight tabular-nums">
                                    {{ $hero['points'] !== null ? number_format((float) $hero['points'], 2) : '—' }}
                                </dd>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2.5">
                                <dt class="text-xs text-stone-300">Top</dt>
                                <dd class="font-heading text-2xl font-bold leading-tight tabular-nums">
                                    {{ $hero['top_pct'] !== null ? $hero['top_pct'].'%' : '—' }}
                                </dd>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2.5">
                                <dt class="text-xs text-stone-300">Avg match</dt>
                                <dd class="font-heading text-2xl font-bold leading-tight tabular-nums">
                                    {{ $hero['avg_match_pct'] !== null ? number_format((float) $hero['avg_match_pct'], 1).'%' : '—' }}
                                </dd>
                            </div>
                        </dl>

                        @if ($hero['cut']['has_data'])
                            <div class="mt-4">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-stone-300">Points to top {{ $hero['cut']['target_rank'] }}</span>
                                    @if ($hero['cut']['in_cut'])
                                        <span class="font-semibold text-emerald-300">Inside the top {{ $hero['cut']['target_rank'] }}</span>
                                    @else
                                        <span class="font-semibold text-white">{{ number_format((float) $hero['cut']['points_to_cut'], 1) }} pts to go</span>
                                    @endif
                                </div>
                                <div class="relative mt-2 h-2 overflow-hidden rounded-full bg-white/10">
                                    <div class="absolute inset-y-0 left-0 rounded-full"
                                         style="width: {{ $hero['cut']['pct_filled'] }}%;
                                                background: linear-gradient(90deg, #059669, #34d399);"></div>
                                </div>
                                <div class="mt-1.5 flex justify-between text-xs text-stone-300">
                                    <span>You {{ $hero['points'] !== null ? number_format((float) $hero['points'], 1) : '—' }}</span>
                                    <span class="font-semibold text-amber-300">
                                        Top {{ $hero['cut']['target_rank'] }} at {{ number_format((float) $hero['cut']['target_points'], 1) }}
                                    </span>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="mt-6">
                            <p class="font-heading text-3xl font-bold leading-tight">Not ranked yet</p>
                            <p class="mt-2 text-sm text-stone-300">
                                Shoot a {{ $activeHeroDiscipline }} national or provincial match this season to appear on the standings.
                            </p>
                            <a href="{{ route('events.index') }}" class="mt-4 inline-flex min-h-10 items-center rounded-lg bg-white px-3.5 text-sm font-semibold text-stone-900 hover:bg-stone-100">
                                Browse upcoming events →
                            </a>
                        </div>
                    @endif
                </div>

                {{-- Right: match-% trend chart --}}
                <div class="p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-heading text-xl font-bold">Match score % this season</h3>
                        <div class="flex gap-3 text-xs text-stone-300">
                            <span><i class="mr-1.5 inline-block h-0.5 w-3.5 align-middle" style="background:#34d399"></i>Your match %</span>
                        </div>
                    </div>

                    @if ($chart)
                        <svg class="mt-2 block h-auto w-full" viewBox="0 0 640 200" role="img"
                             aria-label="Match score percentage over {{ $chart['match_count'] }} match(es), latest {{ $chart['latest_pct'] }}%">
                            <defs>
                                <linearGradient id="hero-area" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0" stop-color="#10b981" stop-opacity=".35"/>
                                    <stop offset="1" stop-color="#10b981" stop-opacity="0"/>
                                </linearGradient>
                            </defs>
                            <g stroke="rgba(255,255,255,.08)" font-size="11" fill="#a1a1aa" font-family="Inter">
                                @foreach ($gridlines as $g)
                                    <line x1="{{ $chartPad['left'] }}" x2="{{ $chartW - $chartPad['right'] }}" y1="{{ $g['y'] }}" y2="{{ $g['y'] }}"/>
                                    <text x="8" y="{{ $g['y'] + 4 }}" stroke="none">{{ $g['label'] }}</text>
                                @endforeach
                            </g>

                            @if (count($svgPoints) >= 2)
                                <path d="{{ $areaPath }}" fill="url(#hero-area)"/>
                                <polyline points="{{ $polyline }}" fill="none" stroke="#34d399" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                            @endif

                            @foreach ($svgPoints as $i => $p)
                                @if ($i < count($svgPoints) - 1)
                                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4" fill="#111113" stroke="#34d399" stroke-width="2">
                                        <title>{{ $p['label'] }}: {{ $p['pct'] }}% · {{ $p['name'] }}</title>
                                    </circle>
                                @else
                                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="11" fill="none" stroke="#34d399" stroke-opacity=".35" stroke-width="2"/>
                                    <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="6" fill="#34d399">
                                        <title>{{ $p['label'] }}: {{ $p['pct'] }}% · {{ $p['name'] }}</title>
                                    </circle>
                                    <g transform="translate({{ $p['x'] - 29 }},{{ max(2, $p['y'] - 28) }})">
                                        <rect width="58" height="22" rx="6" fill="#fff"/>
                                        <text x="29" y="15" text-anchor="middle" font-size="12" font-weight="700" fill="#18181b" font-family="Inter">{{ $p['pct'] }}%</text>
                                    </g>
                                @endif
                            @endforeach

                            @if (count($svgPoints) >= 2)
                                <g font-size="11" fill="#a1a1aa" font-family="Inter" text-anchor="middle">
                                    @foreach ($svgPoints as $p)
                                        <text x="{{ $p['x'] }}" y="{{ $chartH - 8 }}">{{ $p['label'] }}</text>
                                    @endforeach
                                </g>
                            @endif
                        </svg>
                    @else
                        <p class="mt-10 text-center text-sm text-stone-300">
                            Your match-% trend appears here as soon as scores are published for this season.
                        </p>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- 4 ── KPI cards ────────────────────────────────────────────────────── --}}
    @php
        $kpiVisible = collect($kpis)->filter(fn ($k) => ($k['has_data'] ?? false));
    @endphp
    @if ($kpiVisible->isNotEmpty())
        <section aria-labelledby="kpi-h">
            <h2 id="kpi-h" class="sr-only">Season figures</h2>
            <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
                {{-- Matches shot --}}
                @if ($kpis['matches']['has_data'])
                    <a href="{{ route('standings.shooter', ['season' => $season, 'user' => $user->id]) }}"
                       class="group block rounded-2xl border border-stone-200 bg-white p-4 shadow-sm transition hover:-translate-y-px hover:border-emerald-500 hover:shadow-md">
                        <div class="flex items-start justify-between text-stone-500">
                            <span class="text-sm">Matches shot</span>
                            <span class="opacity-0 transition group-hover:opacity-100 text-emerald-700" aria-hidden="true">→</span>
                        </div>
                        <div class="mt-1.5 flex items-baseline gap-2">
                            <b class="font-heading text-4xl font-bold leading-none tabular-nums text-stone-900">
                                {{ $kpis['matches']['shot'] }}
                            </b>
                            @if ($kpis['matches']['calendar'] > 0)
                                <span class="text-sm text-stone-500">of {{ $kpis['matches']['calendar'] }} on calendar</span>
                            @endif
                        </div>
                        @if (count($kpis['matches']['dots']) > 0)
                            <div class="mt-3 flex gap-1" aria-hidden="true">
                                @foreach ($kpis['matches']['dots'] as $d)
                                    <span @class([
                                        'h-1.5 flex-1 rounded-sm',
                                        'bg-emerald-600' => $d['shot'],
                                        'bg-stone-200' => ! $d['shot'] && ! $d['future'],
                                        'bg-emerald-200' => ! $d['shot'] && $d['future'],
                                    ])></span>
                                @endforeach
                            </div>
                        @endif
                        @if ($kpis['matches']['breakdown'])
                            <p class="mt-2 text-xs text-stone-500">{{ $kpis['matches']['breakdown'] }}</p>
                        @endif
                    </a>
                @endif

                {{-- Average match % --}}
                @if ($kpis['average']['has_data'])
                    <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between text-stone-500">
                            <span class="text-sm">Average match %</span>
                        </div>
                        <div class="mt-1.5 flex items-baseline gap-2">
                            <b class="font-heading text-4xl font-bold leading-none tabular-nums text-stone-900">
                                {{ number_format((float) $kpis['average']['avg'], 1) }}%
                            </b>
                        </div>
                        <p class="mt-2 text-xs text-stone-500">
                            @if ($kpis['average']['delta'] !== null)
                                <span @class([
                                    'font-semibold',
                                    'text-emerald-700' => $kpis['average']['delta'] >= 0,
                                    'text-red-700' => $kpis['average']['delta'] < 0,
                                ])>
                                    {{ $kpis['average']['delta'] >= 0 ? '▲' : '▼' }}
                                    {{ number_format(abs($kpis['average']['delta']), 1) }} pts
                                </span>
                                vs {{ (int) $season - 1 }} ·
                            @endif
                            best {{ number_format((float) $kpis['average']['best'], 1) }}%
                        </p>
                    </div>
                @endif

                {{-- Best finish --}}
                @if ($kpis['best']['has_data'])
                    <a href="{{ $kpis['best']['href'] ?? '#' }}"
                       class="group block rounded-2xl border border-stone-200 bg-white p-4 shadow-sm transition hover:-translate-y-px hover:border-emerald-500 hover:shadow-md">
                        <div class="flex items-start justify-between text-stone-500">
                            <span class="text-sm">Best finish</span>
                            <span class="opacity-0 transition group-hover:opacity-100 text-emerald-700" aria-hidden="true">→</span>
                        </div>
                        <div class="mt-1.5 flex items-baseline gap-2">
                            <b class="font-heading text-4xl font-bold leading-none tabular-nums text-stone-900">
                                #{{ $kpis['best']['rank'] }}
                            </b>
                            @if ($kpis['best']['total'])
                                <span class="text-sm text-stone-500">of {{ $kpis['best']['total'] }}</span>
                            @endif
                        </div>
                        @if ($kpis['best']['match_name'])
                            <p class="mt-2 line-clamp-1 text-xs text-stone-500">
                                {{ $kpis['best']['match_name'] }}@if ($kpis['best']['match_date']) · {{ $kpis['best']['match_date'] }}@endif
                            </p>
                        @endif
                    </a>
                @endif

                {{-- Peak match % (ring) --}}
                @if ($kpis['peak']['has_data'])
                    <a href="{{ $kpis['peak']['href'] ?? '#' }}"
                       class="group block rounded-2xl border border-stone-200 bg-white p-4 shadow-sm transition hover:-translate-y-px hover:border-emerald-500 hover:shadow-md">
                        <div class="flex items-start justify-between text-stone-500">
                            <span class="text-sm">Peak match %</span>
                            <span class="opacity-0 transition group-hover:opacity-100 text-emerald-700" aria-hidden="true">→</span>
                        </div>
                        <div class="mt-2 flex items-center gap-3.5">
                            <svg viewBox="0 0 36 36" class="size-14 shrink-0" aria-hidden="true">
                                <circle cx="18" cy="18" r="15.5" fill="none" stroke="#f4f4f5" stroke-width="4"/>
                                <circle cx="18" cy="18" r="15.5" fill="none" stroke="#059669" stroke-width="4"
                                        stroke-linecap="round"
                                        stroke-dasharray="{{ $kpis['peak']['arc'] }} {{ $kpis['peak']['circumference'] }}"
                                        transform="rotate(-90 18 18)"/>
                            </svg>
                            <div class="min-w-0">
                                <b class="font-heading text-3xl font-bold leading-none tabular-nums text-stone-900">
                                    {{ number_format((float) $kpis['peak']['pct'], 1) }}%
                                </b>
                                @if ($kpis['peak']['match_name'])
                                    <p class="mt-1 line-clamp-1 text-xs text-stone-500">{{ $kpis['peak']['match_name'] }}</p>
                                @endif
                            </div>
                        </div>
                    </a>
                @endif
            </div>
        </section>
    @endif

    {{-- 5 ── Next matches + Qualification tracker ─────────────────────────── --}}
    <div class="grid gap-5 xl:grid-cols-[3fr_2fr]">
        {{-- Next matches --}}
        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-3.5">
                <div>
                    <h2 class="font-heading text-xl font-bold leading-tight">Next matches</h2>
                    <p class="text-xs text-stone-500">{{ $scopeNote }}</p>
                </div>
                <div class="flex items-center gap-3">
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
                    <a href="{{ route('events.index') }}" class="text-sm font-semibold text-emerald-700 hover:underline">All events</a>
                </div>
            </div>

            <ul class="divide-y divide-stone-100">
                @forelse ($nextMatches as $m)
                    <li>
                        <a href="{{ $m['href'] }}" class="group flex items-center gap-3.5 px-5 py-3.5 transition hover:bg-stone-50">
                            @php $onDate = ($m['status_tone'] ?? '') === 'registered'; @endphp
                            <div @class([
                                'flex w-[52px] shrink-0 flex-col items-center justify-center rounded-lg py-1.5 leading-tight',
                                'bg-emerald-700 text-white' => $onDate,
                                'bg-stone-100 text-stone-800' => ! $onDate,
                            ])>
                                <span @class([
                                    'text-[11px] font-semibold uppercase tracking-wider',
                                    'text-emerald-100' => $onDate,
                                    'text-stone-500' => ! $onDate,
                                ])>{{ $m['month'] }}</span>
                                <span class="font-heading text-2xl font-bold">{{ $m['day'] }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 items-center gap-2">
                                    @if ($m['series'])
                                        <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold tracking-wide {{ $discBadgeClass($m['series']) }}">{{ $m['series'] }}</span>
                                    @endif
                                    <span class="truncate font-semibold text-stone-900 group-hover:text-emerald-700">{{ $m['name'] }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-stone-500">
                                    <span class="truncate">{{ $m['meta'] }}</span>
                                    @if ($m['counts_for_finals'])
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">Counts for finals</span>
                                    @endif
                                </div>
                            </div>

                            @if (! empty($m['capacity']))
                                <div class="hidden min-w-[92px] text-right sm:block">
                                    <div class="text-xs font-semibold tabular-nums text-stone-800">
                                        {{ $m['capacity']['taken'] }} / {{ $m['capacity']['max'] }}
                                    </div>
                                    <div class="mt-1 h-1 overflow-hidden rounded bg-stone-200">
                                        <div @class([
                                            'h-full rounded',
                                            'bg-red-500' => $m['capacity']['pct'] >= 95,
                                            'bg-amber-500' => $m['capacity']['pct'] >= 80 && $m['capacity']['pct'] < 95,
                                            'bg-emerald-600' => $m['capacity']['pct'] < 80,
                                        ])
                                        style="width: {{ max(4, $m['capacity']['pct']) }}%"></div>
                                    </div>
                                </div>
                            @endif

                            @php
                                $tone = $m['status_tone'];
                                $outstanding = ! empty($m['fee']['outstanding']);
                            @endphp
                            @if ($tone === 'registered' && $outstanding)
                                <span class="shrink-0 whitespace-nowrap rounded-full border border-amber-300 bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-900">
                                    Pay R{{ number_format((float) $m['fee']['amount'], 2) }}
                                </span>
                            @elseif ($tone === 'registered')
                                <span class="shrink-0 whitespace-nowrap rounded-full bg-stone-900 px-2.5 py-0.5 text-xs font-semibold text-white">
                                    Entered ✓
                                </span>
                            @elseif ($tone === 'open')
                                <span class="shrink-0 whitespace-nowrap rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                    Entries open
                                </span>
                            @elseif ($tone === 'opens')
                                <span class="shrink-0 whitespace-nowrap rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-700">
                                    {{ $m['status'] }}
                                </span>
                            @else
                                <span class="shrink-0 whitespace-nowrap rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-500">
                                    Entries closed
                                </span>
                            @endif
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-stone-500">No upcoming matches in this discipline yet.</li>
                @endforelse
            </ul>
        </section>

        {{-- Qualification tracker --}}
        @if (count($seasonCards) > 0)
            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-stone-100 px-5 py-3.5">
                    <div>
                        <h2 class="font-heading text-xl font-bold leading-tight">Qualification tracker</h2>
                        <p class="text-xs text-stone-500">{{ $season }} season · rules as published</p>
                    </div>
                    <a href="{{ route('standings.public', ['season' => $season]) }}" class="text-sm font-semibold text-emerald-700 hover:underline">Standings</a>
                </div>
                <div class="divide-y divide-stone-100">
                    @foreach ($seasonCards as $card)
                        @php $q = $card['qualification']; @endphp
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold tracking-wide {{ $discBadgeClass($card['series']) }}">{{ $card['series'] }}</span>
                                    <b class="truncate text-stone-900">{{ $card['label'] }} · {{ $card['series'] === 'PR22' ? 'finals' : 'national series' }}</b>
                                </div>
                                @if ($q['met'])
                                    <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">On track</span>
                                @else
                                    <span class="shrink-0 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">
                                        @if ($q['mode'] === 'weighted_pools')
                                            {{ (int) ($q['oop']['required'] ?? 0) > 0 && ! ($q['oop']['qualified'] ?? false) ? 'OOP missing' : 'Short' }}
                                        @else
                                            {{ max(0, (int) $q['need'] - (int) $q['shot']) }} missing
                                        @endif
                                    </span>
                                @endif
                            </div>

                            @if ($q['mode'] === 'weighted_pools')
                                <div class="mt-3 space-y-2.5">
                                    @foreach ($q['pools'] as $pool)
                                        <div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-stone-700">
                                                    {{ $pool['label'] }}
                                                    @if ($pool['detail'])<span class="text-stone-400">· {{ $pool['detail'] }}</span>@endif
                                                </span>
                                                <span class="font-mono text-stone-800">{{ $pool['completed'] }} / {{ $pool['required'] }}</span>
                                            </div>
                                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-stone-200">
                                                <div class="h-full {{ $discBarFillClass($card['series']) }}" style="width: {{ $pool['pct'] }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-3">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-stone-700">Counting matches shot</span>
                                        <span class="font-mono text-stone-800">{{ $q['shot'] }} / {{ $q['need'] }}</span>
                                    </div>
                                    @if (count($q['segments']) > 0)
                                        <div class="mt-1 grid gap-1" style="grid-template-columns: repeat({{ max(1, count($q['segments'])) }}, minmax(0, 1fr))" aria-hidden="true">
                                            @foreach ($q['segments'] as $seg)
                                                <span @class([
                                                    'h-1.5 rounded-sm',
                                                    'bg-emerald-700' => $seg === 'counting' && $card['series'] === 'PRS',
                                                    'bg-emerald-300' => $seg === 'dropped' && $card['series'] === 'PRS',
                                                    'bg-sky-700' => $seg === 'counting' && $card['series'] === 'PR22',
                                                    'bg-sky-300' => $seg === 'dropped' && $card['series'] === 'PR22',
                                                ])></span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($q['hint'])
                                        <p class="mt-2 text-xs text-stone-500">{{ $q['hint'] }}</p>
                                    @endif
                                </div>
                            @endif

                            @if (($q['oop']['required'] ?? 0) > 0)
                                <div class="mt-3 flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs">
                                    <span class="font-semibold text-amber-900">Out-of-province national (finals)</span>
                                    <span class="font-mono font-semibold text-amber-900">{{ $q['oop']['completed'] }} / {{ $q['oop']['required'] }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    {{-- 6 ── Recent results + My rifles ───────────────────────────────────── --}}
    <div class="grid gap-5 xl:grid-cols-[3fr_2fr]">
        {{-- Recent results --}}
        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-3.5">
                <h2 class="font-heading text-xl font-bold leading-tight">Recent results</h2>
                <div class="flex items-center gap-3">
                    @if ($dual)
                        <div role="group" aria-label="Filter by discipline" class="inline-flex gap-0.5 rounded-lg bg-stone-100 p-0.5">
                            @foreach (['all' => 'All', 'PRS' => 'PRS', 'PR22' => 'PR22'] as $key => $label)
                                <button type="button" wire:click="setResultsFilter('{{ $key }}')"
                                    @class([
                                        'min-h-8 rounded-md px-3 text-xs font-semibold transition',
                                        'bg-white text-stone-900 shadow-sm' => $resultsFilter === $key,
                                        'text-stone-500 hover:text-stone-800' => $resultsFilter !== $key,
                                    ])
                                    aria-pressed="{{ $resultsFilter === $key ? 'true' : 'false' }}"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    @endif
                    <a href="{{ route('standings.shooter', ['season' => $season, 'user' => $user->id]) }}" class="text-sm font-semibold text-emerald-700 hover:underline">All match history</a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="text-left text-xs text-stone-500">
                            <th class="px-5 py-2.5 font-medium">Date</th>
                            <th class="px-2 py-2.5 font-medium">Match</th>
                            <th class="px-2 py-2.5 font-medium">Prov.</th>
                            <th class="px-2 py-2.5 text-right font-medium">Place</th>
                            <th class="px-2 py-2.5 font-medium">Match %</th>
                            <th class="px-5 py-2.5 text-right font-medium">Impacts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentResults as $r)
                            <tr class="border-t border-stone-100 hover:bg-stone-50">
                                <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-stone-600">{{ $r['date'] }}</td>
                                <td class="px-2 py-3">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        @if ($r['series'])
                                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold tracking-wide {{ $discBadgeClass($r['series']) }}">{{ $r['series'] }}</span>
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
                                <td class="px-2 py-3 text-right">
                                    @if ($r['place'])
                                        @php
                                            $placeClass = match (true) {
                                                $r['place'] === 1 => 'bg-amber-100 text-amber-800',
                                                $r['place'] === 2 => 'bg-stone-200 text-stone-800',
                                                $r['place'] === 3 => 'bg-orange-200 text-orange-900',
                                                default => 'bg-stone-100 text-stone-700',
                                            };
                                        @endphp
                                        <span class="inline-flex min-w-[2rem] items-center justify-center rounded font-heading text-base font-bold {{ $placeClass }}">{{ $r['place'] }}</span>
                                    @else
                                        <span class="text-stone-400">—</span>
                                    @endif
                                </td>
                                <td class="px-2 py-3">
                                    @if ($r['match_pct'] !== null)
                                        <span class="inline-flex items-center gap-2">
                                            <span class="h-1 w-10 rounded bg-stone-200">
                                                <span class="block h-full rounded bg-emerald-600" style="width: {{ min(100, (int) round($r['match_pct'])) }}%"></span>
                                            </span>
                                            <b class="font-mono tabular-nums text-stone-900">{{ number_format((float) $r['match_pct'], 1) }}%</b>
                                        </span>
                                    @else
                                        <span class="text-stone-400">—</span>
                                    @endif
                                </td>
                                <td @class([
                                    'px-5 py-3 text-right font-mono tabular-nums',
                                    'font-semibold text-red-700' => $r['impacts'] === 0,
                                    'text-stone-900' => $r['impacts'] !== 0,
                                ])>
                                    {{ $r['impacts'] === null ? '—' : $r['impacts'] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-sm text-stone-400">No results yet this season.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- My rifles --}}
        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-stone-100 px-5 py-3.5">
                <h2 class="font-heading text-xl font-bold leading-tight">My rifles</h2>
                <a href="{{ route('rifle-configurations.index') }}" class="text-sm font-semibold text-emerald-700 hover:underline">Manage kit</a>
            </div>

            @if ($allRifles->isEmpty())
                <div class="space-y-3 p-5">
                    <p class="text-sm text-stone-600">No rifles yet. Add one per discipline to link scores, ammo and barrel life.</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($disciplines as $disc)
                            <a href="{{ route('rifle-configurations.create', ['primary_series' => $disc]) }}"
                               class="inline-flex min-h-10 items-center rounded-lg border px-3.5 text-sm font-semibold {{ $discBadgeClass($disc) }}">
                                Add {{ $disc === 'PR22' ? 'rimfire' : 'centrefire' }} rifle
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="divide-y divide-stone-100">
                    @foreach ($allRifles as $rifle)
                        @php $d = $rifleDope[$rifle->id] ?? null; @endphp
                        <article class="px-5 py-4">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <a href="{{ route('rifle-configurations.show', $rifle) }}" class="block truncate font-semibold text-stone-900 hover:text-emerald-700">{{ $rifle->displayName() }}</a>
                                    <p class="text-xs text-stone-500">
                                        {{ $rifle->calibre?->name }}@if ($d && $d['ammo_label']) · {{ $d['ammo_label'] }}@endif
                                    </p>
                                </div>
                                @if ($rifle->primary_series)
                                    <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold tracking-wide {{ $discBadgeClass($rifle->primary_series) }}">{{ $rifle->primary_series }}</span>
                                @endif
                            </div>

                            @if ($d && ($d['avg_mv'] !== null || $d['sd'] !== null || $d['rounds_season'] > 0))
                                <dl class="mt-3 grid grid-cols-3 gap-1.5">
                                    <div class="rounded-lg border border-stone-100 bg-stone-50 px-2.5 py-1.5">
                                        <dt class="text-[11px] text-stone-500">Avg MV</dt>
                                        <dd class="font-mono text-sm font-bold text-stone-900">{{ $d['avg_mv'] !== null ? number_format((int) $d['avg_mv']).' fps' : '—' }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-stone-100 bg-stone-50 px-2.5 py-1.5">
                                        <dt class="text-[11px] text-stone-500">SD{{ $d['sd_n'] ? ' (n='.$d['sd_n'].')' : '' }}</dt>
                                        <dd class="font-mono text-sm font-bold text-stone-900">{{ $d['sd'] !== null ? number_format($d['sd'], 1) : '—' }}</dd>
                                    </div>
                                    <div class="rounded-lg border border-stone-100 bg-stone-50 px-2.5 py-1.5">
                                        <dt class="text-[11px] text-stone-500">Rounds {{ $season }}</dt>
                                        <dd class="font-mono text-sm font-bold text-stone-900">{{ $d['rounds_season'] ? number_format($d['rounds_season']) : '0' }}</dd>
                                    </div>
                                </dl>
                            @endif

                            @if ($d && $d['barrel'])
                                <div class="mt-3">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-stone-500">
                                            Barrel · {{ $d['barrel']['label'] }}@if ($d['barrel']['maker']) · {{ $d['barrel']['maker'] }}@endif
                                        </span>
                                        <span class="font-mono text-stone-800">
                                            <b class="tabular-nums">{{ number_format((int) $d['barrel']['round_count']) }}</b>
                                            rounds · no limit set
                                        </span>
                                    </div>
                                </div>
                            @elseif ($d && $d['barrel_lifetime'] > 0)
                                <div class="mt-3 text-xs text-stone-500">
                                    Lifetime <b class="font-mono tabular-nums text-stone-800">{{ number_format((int) $d['barrel_lifetime']) }}</b> rounds · no barrel tracked
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- 7 ── Membership strip ─────────────────────────────────────────────── --}}
    @if ($strip['has_membership'] || $strip['family_count'] > 0 || $strip['unpaid_count'] > 0)
        <section aria-labelledby="mem-h" class="rounded-2xl border border-stone-200 bg-white px-5 py-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="mem-h" class="font-heading text-xl font-bold leading-tight text-stone-900">Membership</h2>
                <div class="flex items-center gap-3 text-sm">
                    @if ($strip['in_renewal_window'])
                        <a href="{{ route('my-membership') }}" class="font-semibold text-emerald-700 hover:underline">Renew for {{ (int) $season + 1 }}</a>
                    @endif
                    <a href="{{ route('my-membership') }}" class="font-semibold text-emerald-700 hover:underline">My membership →</a>
                </div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
                <div>
                    <dt class="text-xs text-stone-500">Status</dt>
                    <dd @class([
                        'mt-0.5 font-semibold',
                        'text-emerald-700' => $strip['status_tone'] === 'ok',
                        'text-amber-800' => $strip['status_tone'] === 'warn',
                        'text-red-700' => $strip['status_tone'] === 'bad',
                        'text-stone-700' => $strip['status_tone'] === 'neutral',
                    ])>
                        {{ $strip['status_label'] }}@if ($strip['type_label']) · {{ $strip['type_label'] }}@endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">Valid until</dt>
                    <dd class="mt-0.5 font-semibold text-stone-800">{{ $strip['expiry_date'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">SAPRF number</dt>
                    <dd class="mt-0.5 font-mono font-semibold text-stone-800">{{ $strip['saprf_number'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">Family</dt>
                    <dd class="mt-0.5 font-semibold">
                        @if ($strip['family_count'] > 0)
                            <a href="{{ route('family.index') }}" class="text-emerald-700 hover:underline">
                                {{ $strip['family_count'] }} linked member{{ $strip['family_count'] === 1 ? '' : 's' }} →
                            </a>
                        @else
                            <a href="{{ route('family.index') }}" class="text-stone-600 hover:text-emerald-700 hover:underline">Add family →</a>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">Payments</dt>
                    <dd class="mt-0.5 font-semibold">
                        @if ($strip['unpaid_count'] > 0)
                            <a href="{{ route('invoices.index') }}" class="text-red-700 hover:underline">
                                {{ $strip['unpaid_count'] }} outstanding · R{{ number_format((float) $strip['unpaid_total'], 2) }} — Pay now →
                            </a>
                        @else
                            <a href="{{ route('invoices.index') }}" class="text-stone-600 hover:text-emerald-700 hover:underline">All settled →</a>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>
    @endif
</div>
