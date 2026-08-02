<x-layouts.app :title="'Platform Expenses - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <a href="{{ route('financials.dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Dashboard</a>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Platform Expenses</h1>
                <p class="mt-1 text-sm text-stone-500">Track equipment, bank charges, insurance, and other operating costs.</p>
            </div>
            <a href="{{ route('financials.expenses.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Add Expense
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="rounded-xl border border-red-200 bg-red-50 shadow-sm p-5">
                <p class="text-xs font-medium text-red-700 uppercase">Total Expenses (All Time)</p>
                <p class="mt-1 text-2xl font-bold text-red-800">R{{ number_format($totalAll, 2) }}</p>
            </div>
            @if($category)
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">{{ $categories[$category] ?? ucfirst($category) }}</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($totalFiltered, 2) }}</p>
            </div>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('financials.expenses') }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ !$category ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                All
            </a>
            @foreach($categories as $key => $label)
            <a href="{{ route('financials.expenses', ['category' => $key]) }}"
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
                            <th class="py-3 px-4">Vendor</th>
                            <th class="py-3 px-4 text-right">Amount</th>
                            <th class="py-3 px-4">Ref</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenses as $expense)
                        <tr class="border-b border-stone-100 hover:bg-stone-50">
                            <td class="py-3 px-4 text-stone-500 text-xs whitespace-nowrap">{{ $expense->expense_date->format('d M Y') }}</td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-stone-100 text-stone-600">
                                    {{ $expense->categoryLabel() }}
                                </span>
                                @if($expense->is_recurring)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-600 ml-1">Recurring</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-stone-700">{{ $expense->description }}</td>
                            <td class="py-3 px-4 text-stone-500">{{ $expense->vendor ?? '—' }}</td>
                            <td class="py-3 px-4 text-right font-semibold text-red-600">R{{ number_format($expense->amount, 2) }}</td>
                            <td class="py-3 px-4 text-xs text-stone-400">{{ $expense->reference ?? '—' }}</td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('financials.expenses.edit', $expense) }}" class="text-stone-500 hover:text-stone-700">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                    </a>
                                    <form method="POST" action="{{ route('financials.expenses.destroy', $expense) }}" onsubmit="return confirm('Delete this expense?')">
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
                                    heading="No expenses recorded yet"
                                    description="Track your platform's fixed costs (hosting, tools, subscriptions) here to see accurate net revenue on the Financial Dashboard."
                                    cta-label="Add Expense"
                                    :cta-href="route('financials.expenses.create')">
                                    <x-slot:icon>
                                        <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
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

        <div>{{ $expenses->withQueryString()->links() }}</div>
    </div>
</x-layouts.app>
