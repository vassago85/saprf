<x-layouts.app :title="'Other Income - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <a href="{{ route('financials.dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Dashboard</a>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Other Income</h1>
                <p class="mt-1 text-sm text-stone-500">Track donations, sponsorships, grants, and other income.</p>
            </div>
            <a href="{{ route('financials.income.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Add Income
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase">Total Income (All Time)</p>
                <p class="mt-1 text-2xl font-bold text-emerald-800">R{{ number_format($totalAll, 2) }}</p>
            </div>
            @if($category)
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">{{ $categories[$category] ?? ucfirst($category) }}</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($totalFiltered, 2) }}</p>
            </div>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('financials.income') }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ !$category ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                All
            </a>
            @foreach($categories as $key => $label)
            <a href="{{ route('financials.income', ['category' => $key]) }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $category === $key ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                {{ $label }}
            </a>
            @endforeach
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Date</th>
                            <th class="py-3 px-4">Category</th>
                            <th class="py-3 px-4">Description</th>
                            <th class="py-3 px-4">Source</th>
                            <th class="py-3 px-4 text-right">Amount</th>
                            <th class="py-3 px-4">Ref</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($incomeItems as $item)
                        <tr class="border-b border-stone-100 hover:bg-stone-50">
                            <td class="py-3 px-4 text-stone-500 text-xs whitespace-nowrap">{{ $item->income_date->format('d M Y') }}</td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-emerald-50 text-emerald-700">
                                    {{ $item->categoryLabel() }}
                                </span>
                                @if($item->is_recurring)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-600 ml-1">Recurring</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-stone-700">
                                {{ $item->description }}
                                @if($item->sponsor)
                                    <span class="block mt-0.5 text-xs text-amber-700 font-medium">&rarr; {{ $item->sponsor->name }}</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-stone-500">{{ $item->source ?? '—' }}</td>
                            <td class="py-3 px-4 text-right font-semibold text-emerald-700">R{{ number_format($item->amount, 2) }}</td>
                            <td class="py-3 px-4 text-xs text-stone-400">{{ $item->reference ?? '—' }}</td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('financials.income.edit', $item) }}" class="text-stone-500 hover:text-stone-700">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                    </a>
                                    <form method="POST" action="{{ route('financials.income.destroy', $item) }}" onsubmit="return confirm('Delete this income entry?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-400 hover:text-red-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-0">
                                <x-empty-state class="!rounded-none !border-0 !border-t !border-dashed"
                                    heading="No income recorded yet"
                                    description="Log sponsorship payments, donations and other non-match income here to keep the Financial Dashboard totals accurate."
                                    cta-label="Add Income"
                                    :cta-href="route('financials.income.create')">
                                    <x-slot:icon>
                                        <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-2.198 0-3.995-.7-3.995-2.42 0-1.72 1.797-2.42 3.995-2.42s3.995.7 3.995 2.42" />
                                        </svg>
                                    </x-slot:icon>
                                </x-empty-state>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $incomeItems->withQueryString()->links() }}</div>
    </div>
</x-layouts.app>
