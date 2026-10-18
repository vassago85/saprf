@props([
    'greeting',
    'title' => 'Dashboard',
    'season' => null,
    'showSeasonSelect' => false,
    'actions' => [],
])

<header class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm text-stone-500">Welcome back, {{ $greeting }}</p>
        <h1 class="font-heading text-3xl font-bold tracking-tight text-stone-900">{{ $title }}</h1>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if ($showSeasonSelect && $season)
            <form method="GET" action="{{ route('dashboard') }}" class="inline">
                <label class="sr-only" for="dashboard_season">Season</label>
                <select
                    id="dashboard_season"
                    name="season"
                    onchange="this.form.submit()"
                    class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-800 focus:border-emerald-500 focus:ring-emerald-500"
                >
                    @foreach (range((int) now()->year, (int) now()->year - 3) as $y)
                        <option value="{{ $y }}" @selected((string) $season === (string) $y)>{{ $y }} season</option>
                    @endforeach
                </select>
            </form>
        @endif
        @foreach ($actions as $action)
            @php $variant = $action['variant'] ?? 'secondary'; @endphp
            <a
                href="{{ $action['href'] }}"
                @class([
                    'rounded-lg px-3 py-2 text-sm font-semibold transition',
                    'bg-emerald-700 text-white hover:bg-emerald-800' => $variant === 'primary',
                    'border border-stone-300 bg-white text-stone-800 hover:bg-stone-50' => $variant !== 'primary',
                ])
            >{{ $action['label'] }}</a>
        @endforeach
    </div>
</header>
