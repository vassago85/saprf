@props([
    'title',
    'href' => null,
    'hrefLabel' => null,
    'rows' => [],
    'class' => '',
])

<section {{ $attributes->class(['rounded-xl border border-stone-200 bg-white shadow-sm', $class]) }}>
    <div class="flex items-center justify-between border-b border-stone-200 px-5 py-3.5">
        <h2 class="font-heading text-xl font-bold text-stone-900">{{ $title }}</h2>
        @if ($href)
            <a href="{{ $href }}" class="text-sm font-medium text-emerald-700 hover:underline">{{ $hrefLabel ?? 'View all' }}</a>
        @endif
    </div>

    @if (count($rows) === 0)
        <p class="px-5 py-8 text-center text-sm text-stone-400">Nothing to show yet.</p>
    @else
        <ul class="divide-y divide-stone-100 text-sm">
            @foreach ($rows as $row)
                @php $type = $row['type'] ?? 'activity'; @endphp

                @if ($type === 'match')
                    <li>
                        <a href="{{ $row['href'] ?? '#' }}" class="flex items-center gap-4 px-5 py-3 hover:bg-stone-50 transition">
                            <div class="w-12 shrink-0 rounded-lg bg-stone-100 py-1 text-center">
                                <div class="text-[11px] font-semibold uppercase text-stone-500">{{ $row['month'] ?? '' }}</div>
                                <div class="text-lg font-bold leading-tight text-stone-900">{{ $row['day'] ?? '' }}</div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium text-stone-900">{{ $row['title'] }}</div>
                                <div class="truncate text-stone-500">{{ $row['subtitle'] ?? '' }}</div>
                            </div>
                            @if (! empty($row['meta']))
                                <div class="hidden text-right sm:block">
                                    <div class="font-semibold text-stone-900">{{ $row['meta'] }}</div>
                                    <div class="text-xs text-stone-500">{{ $row['meta_label'] ?? '' }}</div>
                                </div>
                            @endif
                            @if (! empty($row['status']))
                                @php
                                    $tone = $row['status_tone'] ?? 'stone';
                                    $badge = match ($tone) {
                                        'emerald' => 'bg-emerald-100 text-emerald-800',
                                        'amber' => 'bg-amber-100 text-amber-800',
                                        'sky' => 'bg-sky-100 text-sky-800',
                                        'red' => 'bg-red-100 text-red-800',
                                        default => 'bg-stone-100 text-stone-700',
                                    };
                                @endphp
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">{{ $row['status'] }}</span>
                            @endif
                        </a>
                    </li>
                @elseif ($type === 'link')
                    <li>
                        <a href="{{ $row['href'] }}" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-stone-50 transition">
                            <div>
                                <div class="font-medium text-stone-900">{{ $row['label'] }}</div>
                                @if (! empty($row['hint']))
                                    <div class="text-xs text-stone-500">{{ $row['hint'] }}</div>
                                @endif
                            </div>
                            <span class="text-stone-400">→</span>
                        </a>
                    </li>
                @elseif ($type === 'empty')
                    <li class="px-5 py-8 text-center text-sm text-stone-400">{{ $row['body'] ?? 'Nothing to show yet.' }}</li>
                @else
                    <li class="flex gap-3 px-5 py-3">
                        @php
                            $dot = $row['dot'] ?? 'emerald';
                            $dotClass = match ($dot) {
                                'amber' => 'bg-amber-500',
                                'sky' => 'bg-sky-600',
                                'red' => 'bg-red-600',
                                'stone' => 'bg-stone-400',
                                default => 'bg-emerald-600',
                            };
                        @endphp
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dotClass }}"></span>
                        <div class="min-w-0 flex-1">
                            @if (! empty($row['href']))
                                <a href="{{ $row['href'] }}" class="hover:underline">
                                    <span class="font-medium text-stone-900">{{ $row['title'] ?? '' }}</span>
                                    @if (! empty($row['body']))
                                        <span class="text-stone-600"> {{ $row['body'] }}</span>
                                    @endif
                                </a>
                            @else
                                <div>
                                    <span class="font-medium text-stone-900">{{ $row['title'] ?? '' }}</span>
                                    @if (! empty($row['body']))
                                        <span class="text-stone-600"> {{ $row['body'] }}</span>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($row['meta']))
                                <div class="text-xs text-stone-500">{{ $row['meta'] }}</div>
                            @endif
                        </div>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif
</section>
