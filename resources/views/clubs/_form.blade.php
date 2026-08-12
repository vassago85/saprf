@php($c = $club ?? null)
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="md:col-span-2">
        <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Club Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="name" required value="{{ old('name', $c?->name) }}"
            class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-500">e.g. <span class="font-mono">Pretoria Precision Rifle Club (PPRC)</span> — abbreviation in brackets will be auto-extracted.</p>
    </div>
    <div>
        <label for="abbreviation" class="block text-sm font-medium text-stone-700 mb-1">Abbreviation</label>
        <input type="text" name="abbreviation" id="abbreviation" maxlength="20" value="{{ old('abbreviation', $c?->abbreviation) }}"
            class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>
    <div>
        <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Province</label>
        <select name="province_id" id="province_id" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            <option value="">— Unassigned —</option>
            @foreach ($provinces as $p)
                <option value="{{ $p->id }}" @selected(old('province_id', $c?->province_id) == $p->id)>{{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-center gap-3 mt-1">
        <label class="inline-flex items-center gap-2 text-sm text-stone-700">
            <input type="checkbox" name="saprf_recognised" value="1" @checked(old('saprf_recognised', $c?->saprf_recognised ?? true)) class="rounded text-emerald-700 focus:ring-emerald-500">
            <span>SAPRF-recognised</span>
        </label>
    </div>
    <div class="flex items-center gap-3 mt-1">
        <label class="inline-flex items-center gap-2 text-sm text-stone-700">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $c?->is_active ?? true)) class="rounded text-emerald-700 focus:ring-emerald-500">
            <span>Active</span>
        </label>
    </div>
</div>
@if ($errors->any())
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <ul class="list-disc list-inside">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
