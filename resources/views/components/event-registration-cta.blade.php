@php
    $sizeClasses = match($size) {
        'lg' => 'px-6 py-3 text-base',
        'sm' => 'px-4 py-1.5 text-xs',
        default => 'px-5 py-2.5 text-sm',
    };

    $variantClasses = match($variant) {
        'primary' => 'bg-emerald-700 text-white font-semibold hover:bg-emerald-800 shadow-sm',
        'registered' => 'bg-emerald-50 text-emerald-700 font-semibold ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100',
        'waitlist' => 'bg-amber-50 text-amber-700 font-semibold ring-1 ring-inset ring-amber-200 hover:bg-amber-100',
        default => 'bg-stone-50 text-stone-400 font-medium ring-1 ring-inset ring-stone-200 cursor-default',
    };
@endphp

@if($disabled)
    <span class="inline-flex items-center justify-center rounded-xl transition {{ $sizeClasses }} {{ $variantClasses }}">
        {{ $label }}
    </span>
@else
    <a href="{{ $url }}" class="inline-flex items-center justify-center rounded-xl transition {{ $sizeClasses }} {{ $variantClasses }}">
        {{ $label }}
    </a>
@endif
