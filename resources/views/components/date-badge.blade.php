@props(['date', 'compact' => false])

@php
    $carbon = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
@endphp

@if($compact)
    {{-- Stacked compact badge: month on top, day below --}}
    <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl bg-emerald-50 border border-emerald-100 w-14 h-14 shrink-0']) }}>
        <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 leading-none">{{ $carbon->format('M') }}</span>
        <span class="text-lg font-bold text-emerald-900 leading-tight">{{ $carbon->format('j') }}</span>
    </div>
@else
    <span {{ $attributes->merge(['class' => 'text-sm font-medium text-stone-600']) }}>
        {{ $carbon->format('D, j M Y') }}
    </span>
@endif
