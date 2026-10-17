@props([
    'href' => null,
    'title' => '',
    'variant' => 'default', // default | danger | primary
    'confirm' => null,
])

@php
    $classes = match ($variant) {
        'danger' => 'rounded-lg p-1.5 text-stone-400 hover:bg-red-50 hover:text-red-600',
        'primary' => 'rounded-lg p-1.5 text-emerald-700 hover:bg-emerald-50 hover:text-emerald-900',
        default => 'rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700',
    };
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        title="{{ $title }}"
        @if ($confirm) onclick="return confirm(@js($confirm))" @endif
        {{ $attributes->class([$classes]) }}
    >{{ $slot }}</a>
@else
    <button
        type="{{ $attributes->get('type', 'submit') }}"
        title="{{ $title }}"
        @if ($confirm) onclick="return confirm(@js($confirm))" @endif
        {{ $attributes->class([$classes])->except('type') }}
    >{{ $slot }}</button>
@endif
