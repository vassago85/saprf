@props([
    'current' => null,
    'showOnProfile' => false,
])

@php
    $selected = old('primary_series', $current) ?? '';
    $profileChecked = (bool) old('show_on_profile', $showOnProfile);
@endphp

<div class="sm:col-span-2 space-y-3" x-data="{ series: @js($selected) }">
    <div>
        <p class="text-sm font-medium text-stone-700 mb-1">Main rifle</p>
        <p class="mb-3 text-xs text-stone-500">You can have one main PRS rifle and one main PR22 rifle. Match sign-up pre-selects the matching main.</p>
        <div class="flex flex-col gap-2">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="radio" name="primary_series" value="" x-model="series"
                    class="border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm text-stone-700">Not a main rifle</span>
            </label>
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="radio" name="primary_series" value="PRS" x-model="series"
                    class="border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm text-stone-700">Main PRS rifle</span>
            </label>
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="radio" name="primary_series" value="PR22" x-model="series"
                    class="border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm text-stone-700">Main PR22 rifle</span>
            </label>
        </div>
    </div>

    <label x-show="series === 'PRS' || series === 'PR22'" x-cloak
        class="flex items-start gap-3 cursor-pointer rounded-lg border border-stone-200 bg-stone-50 px-4 py-3">
        <input type="checkbox" name="show_on_profile" value="1"
            @checked($profileChecked)
            class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
        <span>
            <span class="block text-sm font-medium text-stone-700">Show this rifle on my shooter profile</span>
            <span class="block text-xs text-stone-500 mt-0.5">Anyone who opens your public standings page will see it as your main <span x-text="series"></span> rifle.</span>
        </span>
    </label>
</div>
