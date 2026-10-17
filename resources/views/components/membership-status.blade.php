@props(['membership' => null])

@php
    $status = $membership?->membershipStatus();
@endphp

@if ($status)
    @php $badge = $status->badge(); @endphp
    <span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {$badge['bg']} {$badge['text']} {$badge['ring']}"]) }} title="{{ $status->description() }}">
        {{ $status->label() }}
    </span>
@else
    <span class="text-xs text-stone-400">No membership</span>
@endif
