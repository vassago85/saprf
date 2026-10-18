@props(['items' => []])

@if (count($items) > 0)
    <section aria-label="Key figures" class="mt-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($items as $kpi)
                @php
                    $accent = $kpi['accent'] ?? null;
                    $valueClass = match ($accent) {
                        'amber' => 'text-amber-700',
                        'red' => 'text-red-700',
                        'emerald' => 'text-emerald-700',
                        default => 'text-stone-900',
                    };
                    $tag = ! empty($kpi['href']) ? 'a' : 'div';
                @endphp
                <{{ $tag }}
                    @if (! empty($kpi['href'])) href="{{ $kpi['href'] }}" @endif
                    @class([
                        'group rounded-xl border border-stone-200 bg-white p-5 shadow-sm',
                        'hover:border-emerald-600 hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-700' => ! empty($kpi['href']),
                    ])
                >
                    <div class="flex items-center justify-between text-sm text-stone-500">
                        <span>{{ $kpi['label'] }}</span>
                        @if (! empty($kpi['href']))
                            <span class="opacity-0 transition group-hover:opacity-100">→</span>
                        @endif
                    </div>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-3xl font-bold tabular-nums {{ $valueClass }}">{{ $kpi['value'] }}</span>
                    </div>
                    @if (isset($kpi['progress']) && $kpi['progress'] !== null)
                        <div class="mt-3 h-1.5 w-full rounded-full bg-stone-100">
                            <div class="h-1.5 rounded-full bg-emerald-600" style="width: {{ min(100, max(0, (float) $kpi['progress'] * 100)) }}%"></div>
                        </div>
                    @endif
                    @if (! empty($kpi['hint']))
                        <div class="mt-2 text-xs text-stone-500">{{ $kpi['hint'] }}</div>
                    @endif
                </{{ $tag }}>
            @endforeach
        </div>
    </section>
@endif
