@props([
    'action',
    'method' => 'GET',
    'label' => 'Filters',
    'submitLabel' => 'Filter',
])

{{--
    Shared admin filter chrome.

    Every page gets the same soft-grey "Filter" submit button on the right-hand
    side. If a page needs something custom (an inline reset link, a different
    label, a split button) it can override with:

        <x-slot:submit>
            <button type="submit" class="...">Search</button>
            <a href="…">Clear</a>
        </x-slot:submit>

    Pages must NOT put their own `type="submit"` buttons inside the default
    slot — the component appends the standard one, so inline submits would
    double up.
--}}
<form
    method="{{ $method }}"
    action="{{ $action }}"
    aria-label="{{ $label }}"
    {{ $attributes->class(['mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-sm']) }}
>
    {{ $slot }}

    @isset($submit)
        {{ $submit }}
    @else
        <button
            type="submit"
            class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition"
        >
            {{ $submitLabel }}
        </button>
    @endisset
</form>
