@props(['date', 'endDate' => null, 'compact' => false])

@php
    $carbon = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
    $endCarbon = $endDate ? ($endDate instanceof \Carbon\Carbon ? $endDate : \Carbon\Carbon::parse($endDate)) : null;
    $isRange = $endCarbon && !$endCarbon->isSameDay($carbon);
@endphp

@if($compact)
    @if($isRange)
        <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl bg-emerald-50 border border-emerald-100 px-2 h-14 shrink-0']) }}>
            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 leading-none">{{ $carbon->format('M') }}</span>
            <span class="text-base font-bold text-emerald-900 leading-tight">{{ $carbon->format('j') }}–{{ $endCarbon->format('j') }}</span>
        </div>
    @else
        <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl bg-emerald-50 border border-emerald-100 w-14 h-14 shrink-0']) }}>
            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 leading-none">{{ $carbon->format('M') }}</span>
            <span class="text-lg font-bold text-emerald-900 leading-tight">{{ $carbon->format('j') }}</span>
        </div>
    @endif
@else
    <span {{ $attributes->merge(['class' => 'text-sm font-medium text-stone-600']) }}>
        @if($isRange)
            {{ $carbon->format('D, j') }}–{{ $endCarbon->format('j M Y') }}
        @else
            {{ $carbon->format('D, j M Y') }}
        @endif
    </span>
@endif
