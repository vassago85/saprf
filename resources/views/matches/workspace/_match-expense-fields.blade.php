{{--
    Shared form fields for creating and editing a match expense.
    Expects: $description, $category, $costType, $amount, $notes,
             $submitLabel, $cancelHandler (Alpine expression).
--}}
<div class="grid grid-cols-1 gap-3 sm:grid-cols-5">
    <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-stone-600 mb-1">Description</label>
        <input type="text" name="description" value="{{ $description }}" required
               placeholder="e.g. Range hire, catering, prizes"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
    </div>
    <div>
        <label class="block text-xs font-medium text-stone-600 mb-1">Category</label>
        <select name="category" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">—</option>
            @foreach(\App\Models\MatchExpense::CATEGORIES as $key => $label)
                <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-stone-600 mb-1">Type</label>
        <select name="cost_type" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            @foreach(\App\Models\MatchExpense::COST_TYPES as $key => $label)
                <option value="{{ $key }}" @selected($costType === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-stone-600 mb-1">Amount (R)</label>
        <input type="number" name="amount" value="{{ $amount }}" step="0.01" min="0.01" required
               placeholder="0.00"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
    </div>
</div>
<div>
    <label class="block text-xs font-medium text-stone-600 mb-1">Notes <span class="text-stone-400 font-normal">(optional)</span></label>
    <input type="text" name="notes" value="{{ $notes }}"
           placeholder="Context for later audits"
           class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
</div>
<div class="flex items-center gap-2">
    <button type="submit"
            class="inline-flex items-center rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
        {{ $submitLabel }}
    </button>
    <button type="button"
            @click="{{ $cancelHandler }}"
            class="inline-flex items-center rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
        Cancel
    </button>
</div>
