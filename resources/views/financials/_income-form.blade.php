@php
    $isEdit = isset($income);
    $sponsors = $sponsors ?? collect();
    $currentCategory = old('category', $isEdit ? $income->category : '');
    $currentSponsor = old('sponsor_id', $isEdit ? $income->sponsor_id : '');
@endphp

<div class="space-y-5 max-w-2xl" x-data="{ category: '{{ $currentCategory }}' }">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="category" class="block text-sm font-medium text-stone-700 mb-1">Category <span class="text-red-500">*</span></label>
            <select name="category" id="category" required x-model="category"
                    class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                <option value="">— Select —</option>
                @foreach($categories as $key => $label)
                    <option value="{{ $key }}" @selected($currentCategory === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="income_date" class="block text-sm font-medium text-stone-700 mb-1">Date <span class="text-red-500">*</span></label>
            <input type="date" name="income_date" id="income_date" required
                   value="{{ old('income_date', $isEdit ? $income->income_date->format('Y-m-d') : now()->format('Y-m-d')) }}"
                   class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            @error('income_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div x-show="category === 'sponsorship'" x-cloak class="rounded-lg border border-amber-200 bg-amber-50/50 p-4">
        <label for="sponsor_id" class="block text-sm font-medium text-stone-700 mb-1">Sponsor <span class="text-stone-400 font-normal">(optional)</span></label>
        <select name="sponsor_id" id="sponsor_id"
                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            <option value="">— Not linked to a sponsor profile —</option>
            @foreach($sponsors as $sponsor)
                <option value="{{ $sponsor->id }}" @selected((string) $currentSponsor === (string) $sponsor->id)>{{ $sponsor->name }}</option>
            @endforeach
        </select>
        <p class="mt-1.5 text-xs text-stone-500">Linking to a sponsor profile lets the sponsorship report group payments by sponsor.</p>
        @error('sponsor_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description <span class="text-red-500">*</span></label>
        <input type="text" name="description" id="description" required maxlength="255"
               value="{{ old('description', $isEdit ? $income->description : '') }}"
               placeholder="e.g. Vortex sponsorship payment Q1"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="amount" class="block text-sm font-medium text-stone-700 mb-1">Amount (ZAR) <span class="text-red-500">*</span></label>
            <input type="number" name="amount" id="amount" required step="0.01" min="0.01"
                   value="{{ old('amount', $isEdit ? $income->amount : '') }}"
                   class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="source" class="block text-sm font-medium text-stone-700 mb-1">Source / Donor</label>
            <input type="text" name="source" id="source" maxlength="150"
                   value="{{ old('source', $isEdit ? $income->source : '') }}"
                   placeholder="e.g. Vortex Optics, John Smith"
                   class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        </div>
    </div>

    <div>
        <label for="reference" class="block text-sm font-medium text-stone-700 mb-1">Reference</label>
        <input type="text" name="reference" id="reference" maxlength="100"
               value="{{ old('reference', $isEdit ? $income->reference : '') }}"
               placeholder="e.g. EFT ref, receipt number"
               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div>
        <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
        <textarea name="notes" id="notes" rows="3" maxlength="500"
                  class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500"
                  placeholder="Optional details...">{{ old('notes', $isEdit ? $income->notes : '') }}</textarea>
    </div>

    <label class="flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="is_recurring" value="0">
        <input type="checkbox" name="is_recurring" value="1"
               class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500"
               @checked(old('is_recurring', $isEdit ? $income->is_recurring : false))>
        <span class="text-sm font-medium text-stone-700">This is recurring income</span>
    </label>

    <div class="pt-2">
        <button type="submit"
                class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
            {{ $isEdit ? 'Update Income' : 'Record Income' }}
        </button>
    </div>
</div>
