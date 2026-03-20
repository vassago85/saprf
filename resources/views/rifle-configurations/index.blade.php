<x-layouts.app :title="'My Rifles - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">My Rifles</h1>
                <p class="mt-1 text-sm text-stone-500">Manage your rifle configurations and track performance.</p>
            </div>
            <a href="{{ route('rifle-configurations.create') }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Rifle
            </a>
        </div>

        @if ($rifles->isNotEmpty())
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($rifles as $rifle)
                    <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden flex flex-col">
                        <div class="p-5 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h2 class="font-heading text-lg font-bold text-stone-900">{{ $rifle->nickname }}</h2>
                                @if ($rifle->is_primary)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 ring-1 ring-inset ring-emerald-600/20 shrink-0">Primary</span>
                                @endif
                            </div>

                            <p class="mt-1 text-sm text-stone-500">
                                {{ $rifle->make->name ?? '—' }} {{ $rifle->model->name ?? '' }}
                            </p>

                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @if ($rifle->calibre)
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">{{ $rifle->calibre->name }}</span>
                                @endif
                                @if ($rifle->optic_description)
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">{{ Str::limit($rifle->optic_description, 20) }}</span>
                                @endif
                            </div>

                            @if ($rifle->barrel_length || $rifle->twist_rate)
                                <p class="mt-2 text-xs text-stone-400">
                                    @if ($rifle->barrel_length)Barrel: {{ $rifle->barrel_length }}@endif
                                    @if ($rifle->barrel_length && $rifle->twist_rate) &middot; @endif
                                    @if ($rifle->twist_rate)Twist: {{ $rifle->twist_rate }}@endif
                                </p>
                            @endif

                            <p class="mt-3 text-xs font-medium text-stone-400">
                                {{ $rifle->registrations_count ?? 0 }} {{ Str::plural('match', $rifle->registrations_count ?? 0) }}
                            </p>
                        </div>

                        <div class="border-t border-stone-100 px-5 py-3 flex items-center justify-between">
                            <a href="{{ route('rifle-configurations.show', $rifle) }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">View Details</a>
                            <a href="{{ route('rifle-configurations.edit', $rifle) }}" class="text-sm text-stone-400 hover:text-stone-700">Edit</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-12 text-center">
                <svg class="mx-auto h-10 w-10 text-stone-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                <h3 class="mt-3 text-sm font-semibold text-stone-900">No rifle configurations yet.</h3>
                <p class="mt-1 text-sm text-stone-500">Add your first rifle to start tracking your builds and match performance.</p>
                <a href="{{ route('rifle-configurations.create') }}"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Add Rifle
                </a>
            </div>
        @endif
    </div>
</x-layouts.app>
