@props([])

<div {{ $attributes->class(['mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm']) }}>
    <div class="overflow-x-auto">
        {{ $slot }}
    </div>
</div>
