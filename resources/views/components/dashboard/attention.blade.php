@props(['items' => []])

@if (count($items) > 0)
    <section aria-label="Needs attention" class="mt-6">
        <div class="flex flex-wrap gap-2">
            @foreach ($items as $item)
                @php
                    $tone = $item['tone'] ?? 'amber';
                    $classes = match ($tone) {
                        'red' => 'border-red-300 bg-red-50 text-red-900 hover:bg-red-100',
                        'neutral' => 'border-stone-300 bg-white text-stone-800 hover:bg-stone-50',
                        'emerald' => 'border-emerald-300 bg-emerald-50 text-emerald-900 hover:bg-emerald-100',
                        default => 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100',
                    };
                    $badge = match ($tone) {
                        'red' => 'bg-red-600 text-white',
                        'neutral' => 'bg-stone-700 text-white',
                        'emerald' => 'bg-emerald-700 text-white',
                        default => 'bg-amber-500 text-white',
                    };
                @endphp
                <a
                    href="{{ $item['href'] }}"
                    class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition {{ $classes }}"
                >
                    <span class="grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-xs font-bold {{ $badge }}">{{ $item['count'] }}</span>
                    {{ $item['label'] }}
                    <span aria-hidden="true">→</span>
                </a>
            @endforeach
        </div>
    </section>
@endif
