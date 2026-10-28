{{--
    MD-only editable expense ledger. Lives on the Finances tab, replacing
    the old read-only list that pointed back at the dead show.blade.php
    for add/edit. Keeps the "Match expenses" / "Only you see this" strings
    that MatchExpensesVisibilityTest asserts against.
--}}
<div class="rounded-xl border border-stone-200 bg-white p-5"
     x-data="{ editing: null, showAdd: false }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold text-stone-900">Match expenses</h3>
            <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">
                Only you see this
            </span>
        </div>
        <button type="button"
                x-show="!showAdd"
                @click="showAdd = true; editing = null"
                class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
            <flux:icon.plus class="size-3.5" />
            Add expense
        </button>
    </div>

    @if($expenses->isEmpty())
        <p x-show="!showAdd" class="mt-4 rounded-lg border border-dashed border-stone-200 px-4 py-6 text-center text-sm text-stone-500">
            No expenses yet. Add range fees, catering, prizes — anything that
            comes out of your pocket for this match.
        </p>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase tracking-wide text-stone-500">
                    <tr class="border-b border-stone-100">
                        <th class="pb-2 pr-3 text-left font-semibold">Description</th>
                        <th class="pb-2 pr-3 text-left font-semibold">Category</th>
                        <th class="pb-2 pr-3 text-left font-semibold">Type</th>
                        <th class="pb-2 pr-3 text-right font-semibold">Unit</th>
                        <th class="pb-2 pr-3 text-right font-semibold">
                            Effective
                            <span class="block text-[10px] font-normal normal-case tracking-normal text-stone-400">{{ $registeredShooters }} registered</span>
                        </th>
                        <th class="w-16 pb-2 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($expenses as $expense)
                        <tr x-show="editing !== {{ $expense->id }}">
                            <td class="py-2 pr-3">
                                <div class="font-medium text-stone-900">{{ $expense->description }}</div>
                                @if($expense->notes)
                                    <div class="text-xs text-stone-500">{{ \Illuminate\Support\Str::limit($expense->notes, 70) }}</div>
                                @endif
                            </td>
                            <td class="py-2 pr-3">
                                @if($expense->category)
                                    <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600">
                                        {{ \App\Models\MatchExpense::CATEGORIES[$expense->category] ?? $expense->category }}
                                    </span>
                                @else
                                    <span class="text-xs text-stone-400">—</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3">
                                @if($expense->cost_type === 'per_shooter')
                                    <span class="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200">
                                        Per shooter
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-stone-50 px-2 py-0.5 text-[11px] font-semibold text-stone-500 ring-1 ring-inset ring-stone-200">
                                        Fixed
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums text-stone-500">
                                R {{ number_format((float) $expense->amount, 2) }}
                            </td>
                            <td class="py-2 pr-3 text-right font-semibold tabular-nums text-stone-900">
                                R {{ number_format($expense->effectiveAmount($registeredShooters), 2) }}
                            </td>
                            <td class="py-2 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button"
                                            @click="editing = {{ $expense->id }}; showAdd = false"
                                            class="rounded p-1 text-stone-400 hover:bg-stone-100 hover:text-stone-700"
                                            title="Edit">
                                        <flux:icon.pencil-square class="size-4" />
                                    </button>
                                    <form method="POST" action="{{ route('match-expenses.destroy', [$match, $expense]) }}"
                                          onsubmit="return confirm('Remove this expense?')">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="rounded p-1 text-stone-400 hover:bg-red-50 hover:text-red-600"
                                                title="Delete">
                                            <flux:icon.trash class="size-4" />
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr x-show="editing === {{ $expense->id }}" x-cloak>
                            <td colspan="6" class="py-3">
                                <form method="POST" action="{{ route('match-expenses.update', [$match, $expense]) }}" class="space-y-3">
                                    @csrf @method('PUT')
                                    @include('matches.workspace._match-expense-fields', [
                                        'description' => $expense->description,
                                        'category' => $expense->category,
                                        'costType' => $expense->cost_type,
                                        'amount' => $expense->amount,
                                        'notes' => $expense->notes,
                                        'submitLabel' => 'Save',
                                        'cancelHandler' => 'editing = null',
                                    ])
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-stone-900">
                        <td colspan="4" class="pt-3 text-base font-semibold text-stone-900">Profit after costs</td>
                        <td class="pt-3 text-right text-base font-bold tabular-nums {{ $profitAfterCosts >= 0 ? 'text-emerald-700' : 'text-red-600' }}" colspan="2">
                            R {{ number_format($profitAfterCosts, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <div x-show="showAdd" x-cloak class="mt-4 border-t border-stone-100 pt-4">
        <form method="POST" action="{{ route('match-expenses.store', $match) }}" class="space-y-3">
            @csrf
            @include('matches.workspace._match-expense-fields', [
                'description' => old('description'),
                'category' => old('category'),
                'costType' => old('cost_type', 'fixed'),
                'amount' => old('amount'),
                'notes' => old('notes'),
                'submitLabel' => 'Add expense',
                'cancelHandler' => 'showAdd = false',
            ])
        </form>
    </div>
</div>
