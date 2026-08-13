@props([
    'id' => null,
    'label' => 'Password',
    'required' => false,
    'autofocus' => false,
    'autocomplete' => 'new-password',
    // Requirement flags — drive the live checklist shown under the field.
    // Set these to mirror the server-side validation rules for the form so
    // the checklist never promises something the backend doesn't enforce.
    'min' => 8,
    'letters' => false,
    'numbers' => false,
    'mixedCase' => false,
    // When false, the live checklist is hidden (used for "confirm password"
    // fields, which only need the show/hide eye — not a second checklist).
    'checklist' => true,
])

@php
    $fieldId = $id ?? 'pwf_'.\Illuminate\Support\Str::random(6);

    // Build the checklist as [human label, Alpine boolean expression] pairs.
    // `val` is the reactive copy of the input's value held in the Alpine scope.
    $checks = [['At least '.$min.' characters', 'val.length >= '.(int) $min]];
    if ($letters) {
        $checks[] = ['A letter', '/[a-zA-Z]/.test(val)'];
    }
    if ($mixedCase) {
        $checks[] = ['Upper &amp; lower case letters', '/[a-z]/.test(val) && /[A-Z]/.test(val)'];
    }
    if ($numbers) {
        $checks[] = ['A number', '/[0-9]/.test(val)'];
    }
@endphp

<div x-data="{ show: false, val: '' }">
    <label for="{{ $fieldId }}" class="block text-sm font-medium text-stone-700 mb-1">
        {{ $label }}@if($required) <span class="text-red-600">*</span>@endif
    </label>

    <div class="relative">
        <input {{ $attributes->merge(['class' => 'w-full rounded-lg border border-stone-300 text-sm py-2.5 pl-3 pr-11 focus:ring-emerald-500 focus:border-emerald-500']) }}
            id="{{ $fieldId }}"
            :type="show ? 'text' : 'password'"
            autocomplete="{{ $autocomplete }}"
            @if($required) required @endif
            @if($autofocus) autofocus @endif
            x-on:input="val = $event.target.value" />

        <button type="button"
            x-on:click="show = !show"
            :aria-label="show ? 'Hide password' : 'Show password'"
            :aria-pressed="show ? 'true' : 'false'"
            tabindex="-1"
            class="absolute inset-y-0 right-0 flex items-center px-3 text-stone-400 hover:text-stone-600 focus:outline-none focus-visible:text-emerald-600 transition-colors">
            {{-- Eye (password hidden → click to show) --}}
            <svg x-show="!show" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S5.5 5.5 12 5.5 21.5 12 21.5 12 18.5 18.5 12 18.5 2.5 12 2.5 12Z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
            {{-- Eye with slash (password visible → click to hide) --}}
            <svg x-show="show" x-cloak class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.7A9.6 9.6 0 0 1 12 5.5c6.5 0 9.5 6.5 9.5 6.5a16 16 0 0 1-2.9 3.8M6.2 6.7A16 16 0 0 0 2.5 12S5.5 18.5 12 18.5c1 0 1.9-.15 2.7-.4" />
            </svg>
        </button>
    </div>

    @if($checklist)
        <ul class="mt-2 space-y-1 text-xs" aria-live="polite">
            @foreach($checks as [$label, $test])
                <li class="flex items-center gap-1.5 transition-colors" :class="({{ $test }}) ? 'text-emerald-600' : 'text-stone-400'">
                    <svg x-show="{{ $test }}" class="size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.1 3.1 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                    </svg>
                    <svg x-show="!({{ $test }})" x-cloak class="size-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="10" cy="10" r="6.5" />
                    </svg>
                    <span>{!! $label !!}</span>
                </li>
            @endforeach
        </ul>
    @endif

    {{ $slot }}
</div>
