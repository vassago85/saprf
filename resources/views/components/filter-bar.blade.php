@props([
    'action',
    'method' => 'GET',
    'label' => 'Filters',
])

<form
    method="{{ $method }}"
    action="{{ $action }}"
    aria-label="{{ $label }}"
    {{ $attributes->class(['mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-sm']) }}
>
    {{ $slot }}
</form>
