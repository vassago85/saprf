@props([
    'name',
    'label',
    'value' => null,
    'number' => null,
])

@php
    $value = $value === null ? null : (string) $value;
    $ids = [];
    for ($i = 1; $i <= 10; $i++) {
        $ids[$i] = $name . '_' . $i;
    }
@endphp

<fieldset>
    <legend class="block text-sm font-medium text-stone-900">
        @if($number !== null)
            <span class="mr-1 text-stone-500 tabular-nums">{{ $number }}.</span>
        @endif
        {{ $label }}
    </legend>
    <p class="mt-1 text-xs text-stone-400">1 = Terrible &middot; 5 = Average &middot; 10 = Excellent</p>

    <div class="mt-3 grid grid-cols-5 gap-1.5 sm:grid-cols-10">
        @for($i = 1; $i <= 10; $i++)
            <label
                for="{{ $ids[$i] }}"
                class="relative inline-flex cursor-pointer items-center justify-center rounded-lg border border-stone-300 bg-white px-0 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-emerald-400 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50 has-[:checked]:text-emerald-800 has-[:checked]:ring-1 has-[:checked]:ring-emerald-600"
            >
                <input
                    type="radio"
                    name="{{ $name }}"
                    id="{{ $ids[$i] }}"
                    value="{{ $i }}"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    @checked($value === (string) $i)
                />
                <span class="tabular-nums">{{ $i }}</span>
            </label>
        @endfor
    </div>
</fieldset>
