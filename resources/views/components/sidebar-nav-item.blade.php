@props(['item'])

@php
    $current = (bool) ($item['current'] ?? false);
    $base = 'relative flex h-10 lg:h-8 items-center gap-2 rounded-md pl-3 pr-2 text-sm leading-none transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500';
    $state = $current
        ? 'bg-emerald-50 text-emerald-800 font-medium'
        : 'text-stone-700 hover:bg-stone-100 hover:text-stone-900';
@endphp

<a href="{{ $item['href'] }}"
   @class([$base, $state])
   title="{{ $item['label'] }}"
   data-nav-current="{{ $current ? 'true' : 'false' }}"
   @if($current) aria-current="page" @endif>
    @if($current)
        <span class="pointer-events-none absolute inset-y-1 left-0 w-0.5 rounded-full bg-emerald-700" aria-hidden="true"></span>
    @endif

    @if(!empty($item['icon']))
        <flux:icon :name="$item['icon']" class="size-4 shrink-0 {{ $current ? 'text-emerald-700' : 'text-stone-500' }}" />
    @endif

    <span class="flex-1 truncate">{{ $item['label'] }}</span>

    @if($item['badge'] !== null)
        <span class="sidebar-nav-badge inline-flex min-w-[1.1rem] items-center justify-center rounded-full px-1.5 text-[10px] font-semibold leading-none
                     {{ ($item['badge_color'] ?? 'emerald') === 'amber' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
            {{ $item['badge'] }}
        </span>
    @endif
</a>
