@php
    /** @var \App\Models\Barrel|null $barrel */
    /** @var \Illuminate\Support\Collection $rifles */
    $barrel = $barrel ?? null;
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4">
        <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid sm:grid-cols-2 gap-6">
    <div>
        <label for="label" class="block text-sm font-medium text-stone-700 mb-1">Label</label>
        <input type="text" name="label" id="label" required maxlength="120"
               value="{{ old('label', $barrel->label ?? '') }}"
               placeholder="e.g. Bartlein #2, 26in"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="chambering" class="block text-sm font-medium text-stone-700 mb-1">Chambering</label>
        <input type="text" name="chambering" id="chambering" maxlength="60"
               value="{{ old('chambering', $barrel->chambering ?? '') }}"
               placeholder="e.g. 6mm Dasher, 6.5 Creedmoor"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="maker" class="block text-sm font-medium text-stone-700 mb-1">Maker</label>
        <input type="text" name="maker" id="maker" maxlength="80"
               value="{{ old('maker', $barrel->maker ?? '') }}"
               placeholder="e.g. Bartlein, Krieger, Proof Research"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="rifle_configuration_id" class="block text-sm font-medium text-stone-700 mb-1">Currently on rifle</label>
        <select name="rifle_configuration_id" id="rifle_configuration_id"
                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            <option value="">— none —</option>
            @foreach ($rifles as $rifle)
                <option value="{{ $rifle->id }}"
                    @selected(old('rifle_configuration_id', $barrel->rifle_configuration_id ?? null) == $rifle->id)>
                    {{ $rifle->nickname }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="length_mm" class="block text-sm font-medium text-stone-700 mb-1">Length (mm)</label>
        <input type="number" min="100" max="1500" name="length_mm" id="length_mm"
               value="{{ old('length_mm', $barrel->length_mm ?? '') }}"
               placeholder="e.g. 660"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="twist_rate" class="block text-sm font-medium text-stone-700 mb-1">Twist rate</label>
        <input type="text" name="twist_rate" id="twist_rate" maxlength="20"
               value="{{ old('twist_rate', $barrel->twist_rate ?? '') }}"
               placeholder="e.g. 1:7.5"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="round_count" class="block text-sm font-medium text-stone-700 mb-1">Round count</label>
        <input type="number" min="0" max="200000" name="round_count" id="round_count"
               value="{{ old('round_count', $barrel->round_count ?? 0) }}"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="installed_on" class="block text-sm font-medium text-stone-700 mb-1">Installed on</label>
        <input type="date" name="installed_on" id="installed_on"
               value="{{ old('installed_on', optional($barrel->installed_on ?? null)->toDateString()) }}"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="retired_on" class="block text-sm font-medium text-stone-700 mb-1">Retired on</label>
        <input type="date" name="retired_on" id="retired_on"
               value="{{ old('retired_on', optional($barrel->retired_on ?? null)->toDateString()) }}"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
</div>
