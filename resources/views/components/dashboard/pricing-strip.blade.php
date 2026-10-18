@props([
    'title' => 'Pricing & rules',
    'href' => null,
    'hrefLabel' => 'Edit pricing',
    'items' => [],
])

<section aria-label="{{ $title }}" class="mt-6 rounded-xl border border-stone-200 bg-white px-5 py-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="font-heading text-xl font-bold text-stone-900">{{ $title }}</h2>
        @if ($href)
            <a href="{{ $href }}" class="text-sm font-semibold text-emerald-700 hover:underline">{{ $hrefLabel }}</a>
        @endif
    </div>
    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($items as $item)
            <div>
                <dt class="text-stone-500">
                    {{ $item['label'] }}
                    @if (! empty($item['badge']))
                        <span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">{{ $item['badge'] }}</span>
                    @endif
                </dt>
                <dd class="mt-0.5 font-semibold text-stone-900">
                    @if (! empty($item['href']))
                        <a href="{{ $item['href'] }}" class="hover:underline">{{ $item['value'] }}</a>
                    @else
                        {{ $item['value'] }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</section>
