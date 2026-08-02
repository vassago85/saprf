@props([
    'heading' => '',
    'description' => '',
    'ctaLabel' => null,
    'ctaHref' => null,
])

<div {{ $attributes->class(['rounded-2xl border border-dashed border-stone-200 bg-white px-6 py-12 text-center']) }}>
    @isset($icon)
        <div class="mx-auto w-12 h-12 rounded-full bg-emerald-50 ring-1 ring-emerald-100 flex items-center justify-center mb-4">
            {{ $icon }}
        </div>
    @endisset

    @if($heading)
        <h3 class="font-semibold text-stone-900">{{ $heading }}</h3>
    @endif

    @if($description)
        <p class="mt-1 text-sm text-stone-500 max-w-md mx-auto">{{ $description }}</p>
    @endif

    {{ $slot }}

    @if($ctaLabel && $ctaHref)
        <a href="{{ $ctaHref }}" class="inline-flex items-center gap-2 mt-4 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
            {{ $ctaLabel }}
        </a>
    @endif
</div>
