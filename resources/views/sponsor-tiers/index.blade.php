<x-layouts.app :title="'Sponsor Tiers - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Sponsor Tiers</h1>
                <p class="mt-1 text-sm text-stone-500">Define sponsorship levels, pricing, and placement.</p>
            </div>
            <a href="{{ route('sponsor-tiers.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Tier
            </a>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse ($tiers as $tier)
                <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4 {{ !$tier->is_active ? 'opacity-50' : '' }}">
                    <div class="flex items-center justify-between">
                        <h3 class="font-heading text-xl font-bold text-stone-900">{{ $tier->name }}</h3>
                        @if ($tier->is_active)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-stone-100 text-stone-600">Inactive</span>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Price per year</span>
                            <span class="font-semibold text-stone-900">R{{ number_format($tier->price_per_year, 0) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Logo max height</span>
                            <span class="font-medium text-stone-700">{{ $tier->logo_max_height }}px</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Display order</span>
                            <span class="font-medium text-stone-700">#{{ $tier->display_order }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Active sponsors</span>
                            <span class="font-medium text-stone-700">{{ $tier->sponsors_count }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($tier->placement ?? [] as $p)
                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-[10px] font-medium text-stone-600">
                                {{ str_replace('_', ' ', $p) }}
                            </span>
                        @endforeach
                    </div>

                    <a href="{{ route('sponsor-tiers.edit', $tier) }}" class="block text-center text-sm text-emerald-700 font-medium hover:text-emerald-900 pt-2 border-t border-stone-100">
                        Edit Tier
                    </a>
                </div>
            @empty
                <div class="sm:col-span-3 text-center py-12 text-sm text-stone-400">
                    No sponsor tiers defined yet. Create one to get started.
                </div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
