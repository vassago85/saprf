@php
    $isEdit = isset($junior);
    $junior = $junior ?? null;
@endphp

<div class="space-y-5 max-w-2xl">
    <div>
        <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Junior's Full Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="name" required maxlength="120"
               value="{{ old('name', $isEdit ? $junior->name : '') }}"
               placeholder="e.g. Conner Britnell"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="date_of_birth" class="block text-sm font-medium text-stone-700 mb-1">Date of Birth <span class="text-red-500">*</span></label>
            <input type="date" name="date_of_birth" id="date_of_birth" required
                   value="{{ old('date_of_birth', $isEdit ? $junior->date_of_birth?->format('Y-m-d') : '') }}"
                   max="{{ now()->subYears(5)->format('Y-m-d') }}"
                   class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            <p class="mt-1 text-xs text-stone-400">Used to determine junior eligibility (under 21 on 1 Jan).</p>
            @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Home Province <span class="text-red-500">*</span></label>
            <select name="province_id" id="province_id" required
                    class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                <option value="">— Select —</option>
                @foreach($provinces as $province)
                    <option value="{{ $province->id }}" @selected((string) old('province_id', $isEdit ? $junior->province_id : auth()->user()->province_id) === (string) $province->id)>{{ $province->name }}</option>
                @endforeach
            </select>
            @error('province_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="division_id" class="block text-sm font-medium text-stone-700 mb-1">Division <span class="text-red-500">*</span></label>
        <select name="division_id" id="division_id" required
                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            <option value="">— Select —</option>
            @foreach($divisions as $division)
                <option value="{{ $division->id }}" @selected((string) old('division_id', $isEdit ? $junior->division_id : '') === (string) $division->id)>{{ $division->name }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-stone-400">Pick one — Open, Factory, Limited, Production, Ladies, Junior, or Senior.</p>
        @error('division_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit"
                class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
            {{ $isEdit ? 'Save Changes' : 'Add Junior' }}
        </button>
        <a href="{{ $isEdit ? route('family.show', $junior) : route('family.index') }}"
           class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
    </div>
</div>
