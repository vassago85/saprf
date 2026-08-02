<x-layouts.app :title="'Sponsors - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Sponsors</h1>
                <p class="mt-1 text-sm text-stone-500">Manage federation sponsors and partnerships.</p>
            </div>
            <a href="{{ route('sponsors.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Sponsor
            </a>
        </div>

        <h2 class="sr-only">Filters</h2>
        <div class="flex flex-wrap items-end gap-4">
            <form method="GET" action="{{ route('sponsors.index') }}" class="flex items-end gap-3" aria-label="Sponsor filters">
                <div>
                    <label for="sponsors_search" class="block text-xs font-medium text-stone-500 mb-1">Search</label>
                    <input type="text" id="sponsors_search" name="search" value="{{ $search ?? '' }}" placeholder="Sponsor name..."
                        class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="sponsors_status" class="block text-xs font-medium text-stone-500 mb-1">Status</label>
                    <select id="sponsors_status" name="status" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="expired" @selected(request('status') === 'expired')>Expired / Inactive</option>
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">Filter</button>
            </form>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b-2 border-stone-200 bg-stone-50">
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Logo</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Sponsor</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Tier</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Period</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                        <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sponsors as $sponsor)
                        <tr class="border-b border-stone-100 hover:bg-stone-50 transition">
                            <td class="px-5 py-4">
                                @if ($sponsor->logoUrl())
                                    <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" class="h-8 w-auto rounded">
                                @else
                                    <div class="h-8 w-16 bg-stone-100 rounded flex items-center justify-center text-[10px] text-stone-400">No logo</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="text-sm font-semibold text-stone-900">{{ $sponsor->name }}</div>
                                @if ($sponsor->website_url)
                                    <a href="{{ $sponsor->website_url }}" target="_blank" class="text-xs text-emerald-600 hover:underline">{{ parse_url($sponsor->website_url, PHP_URL_HOST) }}</a>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold
                                    {{ $sponsor->tier->display_order === 1 ? 'bg-amber-100 text-amber-800' : ($sponsor->tier->display_order === 2 ? 'bg-yellow-100 text-yellow-700' : 'bg-stone-100 text-stone-600') }}">
                                    {{ $sponsor->tier->name }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-sm text-stone-600">
                                {{ $sponsor->starts_at->format('d M Y') }} — {{ $sponsor->expires_at->format('d M Y') }}
                            </td>
                            <td class="px-5 py-4">
                                @if ($sponsor->is_active && !$sponsor->isExpired())
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Active</span>
                                @elseif ($sponsor->isExpired())
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-red-100 text-red-700">Expired</span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-stone-100 text-stone-600">Inactive</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('sponsors.edit', $sponsor) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-900">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-sm text-stone-400">No sponsors found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sponsors->hasPages())
            <div class="mt-4">{{ $sponsors->links() }}</div>
        @endif
    </div>
</x-layouts.app>
