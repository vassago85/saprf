<x-layouts.app :title="'My Ammo Loads - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">My Ammo</h1>
                <p class="mt-1 text-sm text-stone-500">All ammunition loads across your rifles.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        @php $hasLoads = $rifles->pluck('ammoLoads')->flatten()->isNotEmpty(); @endphp

        @if ($rifles->isNotEmpty())
            <div class="space-y-6">
                @foreach ($rifles as $rifle)
                    <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-stone-100 px-5 py-3 bg-stone-50 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('rifle-configurations.show', $rifle) }}" class="font-heading text-base font-bold text-stone-900 hover:text-emerald-700 transition">{{ $rifle->nickname }}</a>
                                <span class="text-xs text-stone-400">{{ $rifle->make?->name }} {{ $rifle->model?->name }} &middot; {{ $rifle->calibre?->name }}</span>
                                @if ($rifle->is_primary)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Primary</span>
                                @endif
                            </div>
                            <a href="{{ route('ammo-loads.create', $rifle) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800 transition">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                Add Load
                            </a>
                        </div>

                        @if ($rifle->ammoLoads->isNotEmpty())
                            <div class="divide-y divide-stone-100">
                                @foreach ($rifle->ammoLoads as $load)
                                    <div class="px-5 py-3.5 flex items-center gap-4 hover:bg-stone-50/50 transition-colors">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-stone-900">{{ $load->nickname }}</p>
                                            <p class="mt-0.5 text-xs text-stone-500">
                                                {{ collect([$load->bullet_make, $load->bullet_weight, $load->bullet_model ?: $load->bullet_type])->filter()->implode(' ') }}
                                                @if ($load->powder) &middot; {{ $load->powder }}@if ($load->charge_weight) / {{ $load->charge_weight }}@endif @endif
                                                @if ($load->velocity) &middot; {{ $load->velocity }} @endif
                                            </p>
                                        </div>
                                        <a href="{{ route('ammo-loads.edit', $load) }}" class="text-xs font-medium text-emerald-700 hover:text-emerald-800 shrink-0">Edit</a>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="px-5 py-6 text-center text-sm text-stone-400">
                                No loads yet &mdash;
                                <a href="{{ route('ammo-loads.create', $rifle) }}" class="font-semibold text-emerald-700 hover:text-emerald-800">add one</a>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-12 text-center">
                <h3 class="text-sm font-semibold text-stone-900">No rifles configured yet.</h3>
                <p class="mt-1 text-sm text-stone-500">Add a rifle first, then you can create ammo loads for it.</p>
                <a href="{{ route('rifle-configurations.create') }}"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Add Rifle
                </a>
            </div>
        @endif
    </div>
</x-layouts.app>
