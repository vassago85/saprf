@props([
    'label' => 'More actions',
])

{{-- Secondary row actions behind a ⋯ menu. Destructive items go last. --}}
<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative inline-flex"
>
    <button
        type="button"
        @click="open = !open"
        class="rounded-lg px-2 py-1 text-lg leading-none text-stone-500 hover:bg-stone-100 hover:text-stone-800"
        :aria-expanded="open.toString()"
        aria-haspopup="true"
        aria-label="{{ $label }}"
    >⋯</button>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="absolute right-0 z-20 mt-1 w-44 rounded-lg border border-stone-200 bg-white py-1 text-sm shadow-lg"
        role="menu"
    >
        {{ $slot }}
    </div>
</div>
