@props([
    'rifle' => null,
])

@php
    $fields = [
        ['name' => 'trigger_description', 'label' => 'Trigger', 'placeholder' => "e.g. Bix'n Andy"],
        ['name' => 'muzzle_brake_description', 'label' => 'Muzzle Brake', 'placeholder' => 'e.g. Botnia Solutions'],
        ['name' => 'scope_mount_description', 'label' => 'Scope Mount', 'placeholder' => 'e.g. Spuhr'],
        ['name' => 'bipod_description', 'label' => 'Bipod', 'placeholder' => 'e.g. MDT Ckye Pod'],
        ['name' => 'magazine_description', 'label' => 'Magazine', 'placeholder' => 'e.g. MDT'],
        ['name' => 'bag_description', 'label' => 'Bag', 'placeholder' => 'e.g. Wiebad'],
        ['name' => 'powder_description', 'label' => 'Powder', 'placeholder' => 'e.g. Hodgdon H4350'],
        ['name' => 'brass_description', 'label' => 'Brass', 'placeholder' => 'e.g. Lapua'],
        ['name' => 'rangefinder_description', 'label' => 'Rangefinder', 'placeholder' => 'e.g. Vortex Fury HD'],
        ['name' => 'chronograph_description', 'label' => 'Chronograph', 'placeholder' => 'e.g. Garmin Xero C1'],
        ['name' => 'tripod_description', 'label' => 'Tripod', 'placeholder' => 'e.g. Leofoto'],
        ['name' => 'gunsmith_description', 'label' => 'Gunsmith', 'placeholder' => 'e.g. Preece Precision'],
    ];

    $hasAny = collect($fields)->contains(fn ($f) => filled(old($f['name'], $rifle?->{$f['name']})));
@endphp

<div class="sm:col-span-2" x-data="{ open: {{ $hasAny ? 'true' : 'false' }} }">
    <button type="button" @click="open = ! open"
        class="w-full flex items-center justify-between rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-left hover:bg-stone-100 transition">
        <span>
            <span class="block text-sm font-medium text-stone-700">Gear details (optional)</span>
            <span class="block text-xs text-stone-500 mt-0.5">Trigger, brake, bipod, mount, bag, ammo components, tripod, gunsmith — shown on your public shooter profile if you opt in.</span>
        </span>
        <svg class="size-4 text-stone-400 transition-transform" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" :class="{ 'rotate-180': open }">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
        </svg>
    </button>

    <div x-show="open" x-cloak class="mt-4 grid sm:grid-cols-2 gap-6">
        @foreach($fields as $field)
            <div>
                <label for="{{ $field['name'] }}" class="block text-sm font-medium text-stone-700 mb-1">{{ $field['label'] }}</label>
                <input type="text" name="{{ $field['name'] }}" id="{{ $field['name'] }}"
                    value="{{ old($field['name'], $rifle?->{$field['name']}) }}"
                    placeholder="{{ $field['placeholder'] }}"
                    class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
        @endforeach
    </div>
</div>
