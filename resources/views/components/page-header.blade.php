@props([
    'title',
    'subtitle' => null,
])

{{-- Shared admin page chrome: title matching the menu, optional subtitle, actions on the right. --}}
<div {{ $attributes->class(['flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="min-w-0">
        @isset($breadcrumb)
            <div class="text-sm text-stone-500">{{ $breadcrumb }}</div>
        @endisset
        <h1 @class([
            'font-heading text-3xl font-bold tracking-tight text-stone-900',
            'mt-1' => isset($breadcrumb),
        ])>{{ $title }}</h1>
        @if (filled($subtitle))
            <p class="mt-1 text-sm text-stone-500">{{ $subtitle }}</p>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
