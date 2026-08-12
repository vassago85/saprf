<x-layouts.app :title="'Membership Fees'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Membership Fees</h1>
                <p class="mt-1 text-sm text-stone-500">Manage the annual membership fee tiers members can join or renew on.</p>
            </div>
            <a href="{{ route('fees.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Fee
            </a>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Fee</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Term</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($tiers as $tier)
                        <tr class="{{ !$tier->is_active ? 'opacity-50 bg-stone-50' : '' }}">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-stone-900">{{ $tier->name }}</span>
                                    @if ($tier->is_default)
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-emerald-100 text-emerald-800">Default</span>
                                    @endif
                                </div>
                                @if ($tier->description)
                                    <p class="text-xs text-stone-400 mt-0.5">{{ $tier->description }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-stone-600">
                                {{ $tier->duration_months == 12 ? '1 Year' : $tier->duration_months . ' months' }}
                            </td>
                            <td class="px-6 py-4 text-right text-sm font-semibold text-stone-900">R {{ number_format((float) $tier->price, 2) }}</td>
                            <td class="px-6 py-4 text-center">
                                @if ($tier->is_active)
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-stone-100 text-stone-600">Archived</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center gap-4">
                                    <a href="{{ route('fees.edit', $tier) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-900">Edit</a>
                                    <form method="POST" action="{{ route('fees.destroy', $tier) }}" onsubmit="return confirm('Delete the {{ $tier->name }} fee? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm text-red-600 font-medium hover:text-red-800">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-stone-400">
                                No membership fees defined yet. Add one to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-stone-400">
            Application and renewal are charged at the same amount per fee. The <strong class="text-stone-500">Default</strong> fee is pre-selected when a member joins.
        </p>
    </div>
</x-layouts.app>
