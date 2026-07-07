<x-layouts.app :title="$match->name">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">{{ $match->name }}</h1>

        <div class="flex items-center gap-2">
            @can('update', $match)
                <flux:button href="{{ route('matches.edit', $match) }}" variant="primary" icon="pencil-square">Edit</flux:button>
            @endcan
            <flux:button href="{{ route('matches.index') }}" variant="ghost" icon="arrow-left">Back</flux:button>
        </div>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Match Details</h2>

                <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Type</dt>
                        <dd class="mt-1.5">
                            @if ($match->match_type === 'PRS')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">PRS</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">PR22</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Series Level</dt>
                        <dd class="mt-1.5 text-sm text-stone-900 capitalize">{{ $match->series_level }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Province</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->province?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">
                            {{ $match->match_date->format('d M Y') }}
                            @if($match->isMultiDay())
                                – {{ $match->match_end_date->format('d M Y') }}
                                <span class="text-xs text-stone-400 ml-1">({{ $match->match_date->diffInDays($match->match_end_date) + 1 }} days)</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Venue</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->venue_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Location</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->venue_location ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</dt>
                        <dd class="mt-1.5">
                            @switch($match->status)
                                @case('draft')
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">Draft</span>
                                    @break
                                @case('open')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Open</span>
                                    @break
                                @case('closed')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Closed</span>
                                    @break
                                @case('completed')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Completed</span>
                                    @break
                            @endswitch
                        </dd>
                    </div>
                    @if ($match->description)
                        <div class="sm:col-span-2">
                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Description</dt>
                            <dd class="mt-1.5 text-sm text-stone-700">{{ $match->description }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Registration &amp; Fees</h2>

                <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Registration Opens</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">
                            @if($match->published)
                                <span class="text-emerald-700 font-medium">Immediately on publish</span>
                            @else
                                {{ $match->registration_open_date?->format('d M Y') ?? '—' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Registration Closes</dt>
                        <dd class="mt-1.5 text-sm text-stone-900">{{ $match->registration_close_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Fee (Active Member)</dt>
                        <dd class="mt-1.5 text-sm font-medium text-stone-900">{{ $match->active_member_fee ? 'R ' . number_format($match->active_member_fee, 2) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Fee (Non-Member)</dt>
                        <dd class="mt-1.5 text-sm font-medium text-stone-900">{{ $match->non_member_fee ? 'R ' . number_format($match->non_member_fee, 2) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Fee (Lapsed Member)</dt>
                        <dd class="mt-1.5 text-sm font-medium text-stone-900">{{ $match->lapsed_member_fee ? 'R ' . number_format($match->lapsed_member_fee, 2) : '—' }}</dd>
                    </div>
                    @php
                        $withdrawalFee = (float) app(\App\Services\SettingsService::class)->get('withdrawal_admin_fee', 100);
                        $withdrawalHours = (int) app(\App\Services\SettingsService::class)->get('withdrawal_deadline_hours', 72);
                    @endphp
                    <div class="sm:col-span-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Withdrawal Policy</dt>
                        <dd class="mt-1.5 text-sm text-stone-700">
                            Admin fee: <strong class="text-stone-900">R {{ number_format($withdrawalFee, 2) }}</strong>
                            &middot; Deadline: <strong class="text-stone-900">{{ $withdrawalHours }}h</strong> before match
                        </dd>
                    </div>
                </dl>
            </div>
            @if($financeBreakdown && $financeBreakdown['registration_count'] > 0)
                <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-stone-900">Finance Breakdown</h2>
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Actual</span>
                    </div>

                    <p class="text-xs text-stone-400 mb-4">Based on {{ $financeBreakdown['registration_count'] }} registration(s). Gateway fees are estimates.</p>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-600">Total Collected from Shooters</span>
                            <span class="font-semibold text-stone-900">R {{ number_format($financeBreakdown['total_collected'], 2) }}</span>
                        </div>
                        <div class="border-t border-stone-100"></div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-500">SAPRF Fee</span>
                            <span class="text-red-600">− R {{ number_format($financeBreakdown['total_saprf_fee'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-500">Platform Fee</span>
                            <span class="text-red-600">− R {{ number_format($financeBreakdown['total_platform_fee'], 2) }}</span>
                        </div>
                        @if($financeBreakdown['total_surcharges'] > 0)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-stone-500">Non-Member / Lapsed Surcharges → SAPRF</span>
                                <span class="text-red-600">− R {{ number_format($financeBreakdown['total_surcharges'], 2) }}</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-500">Est. Gateway Fee (PayFast)</span>
                            <span class="text-red-600">− R {{ number_format($financeBreakdown['total_gateway_fee'], 2) }}</span>
                        </div>
                        <div class="border-t border-stone-200"></div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-semibold text-stone-900">Estimated MD Payout</span>
                            <span class="font-bold text-emerald-700">R {{ number_format($financeBreakdown['total_md_net'], 2) }}</span>
                        </div>
                    </div>
                </div>
            @endif

            @if($planningEstimate)
                <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-stone-900">Revenue Projection</h2>
                        <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-600/20">Estimate</span>
                    </div>

                    <p class="text-xs text-stone-400 mb-4">At full capacity ({{ $planningEstimate['capacity'] }} shooters) paying R {{ number_format($planningEstimate['base_fee'], 2) }} each.</p>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-600">Gross Revenue</span>
                            <span class="font-semibold text-stone-900">R {{ number_format($planningEstimate['gross_revenue'], 2) }}</span>
                        </div>
                        <div class="border-t border-stone-100"></div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-500">SAPRF Fee ({{ $planningEstimate['saprf_type'] === 'fixed' ? 'R ' . number_format($planningEstimate['saprf_value'], 2) . ' / shooter' : number_format($planningEstimate['saprf_value'], 1) . '%' }})</span>
                            <span class="text-red-600">− R {{ number_format($planningEstimate['saprf_fee'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-500">Platform Fee ({{ $planningEstimate['platform_type'] === 'fixed' ? 'R ' . number_format($planningEstimate['platform_value'], 2) . ' / shooter' : number_format($planningEstimate['platform_value'], 1) . '%' }})</span>
                            <span class="text-red-600">− R {{ number_format($planningEstimate['platform_fee'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-500">Est. Gateway ({{ number_format($planningEstimate['gateway_pct'], 1) }}%)</span>
                            <span class="text-red-600">− R {{ number_format($planningEstimate['gateway_fee'], 2) }}</span>
                        </div>
                        <div class="border-t border-stone-200"></div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-semibold text-stone-900">Projected MD Payout</span>
                            <span class="font-bold text-emerald-700">R {{ number_format($planningEstimate['md_net'], 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-stone-400">Per shooter net</span>
                            <span class="text-stone-500">R {{ $planningEstimate['capacity'] > 0 ? number_format($planningEstimate['md_net'] / $planningEstimate['capacity'], 2) : '0.00' }}</span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Expense Tracker --}}
            @can('update', $match)
                <div x-data="{ editing: null, showAdd: false }" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-stone-900">Match Expenses</h2>
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">{{ $expenses->count() }} {{ Str::plural('item', $expenses->count()) }}</span>
                    </div>

                    @if($estimatedShooters > 0)
                        <div class="rounded-lg bg-blue-50 border border-blue-200 p-3 mb-4 flex items-center gap-2">
                            <svg class="size-4 text-blue-600 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                            <span class="text-xs text-blue-800">Per-shooter costs calculated for <strong>{{ $estimatedShooters }}</strong> estimated shooters</span>
                        </div>
                    @endif

                    @if($expenses->isNotEmpty())
                        <div class="overflow-x-auto -mx-6 px-6">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-stone-200">
                                        <th class="pb-2 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Description</th>
                                        <th class="pb-2 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Category</th>
                                        <th class="pb-2 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Type</th>
                                        <th class="pb-2 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Unit Cost</th>
                                        <th class="pb-2 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Total</th>
                                        <th class="pb-2 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400 w-20">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach($expenses as $expense)
                                        <tr x-show="editing !== {{ $expense->id }}">
                                            <td class="py-2.5 pr-3">
                                                <span class="text-sm font-medium text-stone-900">{{ $expense->description }}</span>
                                                @if($expense->notes)
                                                    <p class="text-xs text-stone-400 mt-0.5">{{ Str::limit($expense->notes, 60) }}</p>
                                                @endif
                                            </td>
                                            <td class="py-2.5 pr-3">
                                                @if($expense->category)
                                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-semibold text-stone-600">
                                                        {{ \App\Models\MatchExpense::CATEGORIES[$expense->category] ?? $expense->category }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 pr-3">
                                                @if($expense->cost_type === 'per_shooter')
                                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700">Per Shooter</span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full bg-stone-50 px-2 py-0.5 text-[10px] font-semibold text-stone-500">Fixed</span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 text-right text-sm font-mono text-stone-500">
                                                R {{ number_format($expense->amount, 2) }}
                                                @if($expense->cost_type === 'per_shooter')
                                                    <span class="text-[10px] text-stone-400">x{{ $estimatedShooters }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2.5 text-right text-sm font-mono text-stone-700 font-semibold">R {{ number_format($expense->effectiveAmount($estimatedShooters), 2) }}</td>
                                            <td class="py-2.5 text-right">
                                                <div class="flex items-center justify-end gap-1">
                                                    <button @click="editing = {{ $expense->id }}" class="p-1 rounded text-stone-400 hover:text-stone-700 hover:bg-stone-100 transition" title="Edit">
                                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                                    </button>
                                                    <form method="POST" action="{{ route('match-expenses.destroy', [$match, $expense]) }}" onsubmit="return confirm('Remove this expense?')">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="p-1 rounded text-stone-400 hover:text-red-600 hover:bg-red-50 transition" title="Delete">
                                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        {{-- Inline edit row --}}
                                        <tr x-show="editing === {{ $expense->id }}" x-cloak>
                                            <td colspan="6" class="py-3">
                                                <form method="POST" action="{{ route('match-expenses.update', [$match, $expense]) }}" class="space-y-3">
                                                    @csrf @method('PUT')
                                                    <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                                                        <div class="sm:col-span-2">
                                                            <input type="text" name="description" value="{{ $expense->description }}" required placeholder="Description" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                                                        </div>
                                                        <div>
                                                            <select name="category" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500">
                                                                <option value="">No category</option>
                                                                @foreach(\App\Models\MatchExpense::CATEGORIES as $key => $label)
                                                                    <option value="{{ $key }}" {{ $expense->category === $key ? 'selected' : '' }}>{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <select name="cost_type" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500">
                                                                @foreach(\App\Models\MatchExpense::COST_TYPES as $key => $label)
                                                                    <option value="{{ $key }}" {{ $expense->cost_type === $key ? 'selected' : '' }}>{{ $label }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <input type="number" name="amount" value="{{ $expense->amount }}" step="0.01" min="0.01" required placeholder="Amount" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <input type="text" name="notes" value="{{ $expense->notes }}" placeholder="Notes (optional)" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800 transition">Save</button>
                                                        <button type="button" @click="editing = null" class="rounded-lg bg-stone-100 px-4 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-200 transition">Cancel</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-stone-300">
                                        <td colspan="4" class="py-2.5 text-sm font-semibold text-stone-700">Total Expenses</td>
                                        <td class="py-2.5 text-right text-sm font-mono font-semibold text-stone-900">R {{ number_format($totalExpenses, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-stone-400 mb-4">No expenses added yet. Track your match costs here.</p>
                    @endif

                    {{-- Add expense form --}}
                    <div class="mt-4 border-t border-stone-100 pt-4">
                        <button @click="showAdd = !showAdd" x-show="!showAdd" class="flex items-center gap-2 text-sm font-medium text-emerald-700 hover:text-emerald-800 transition">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Add Expense
                        </button>

                        <form x-show="showAdd" x-cloak method="POST" action="{{ route('match-expenses.store', $match) }}" class="space-y-3">
                            @csrf
                            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                                <div class="sm:col-span-2">
                                    <input type="text" name="description" required placeholder="e.g. Range fee, Catering, Venue hire" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                                </div>
                                <div>
                                    <select name="category" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        <option value="">Category</option>
                                        @foreach(\App\Models\MatchExpense::CATEGORIES as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <select name="cost_type" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        @foreach(\App\Models\MatchExpense::COST_TYPES as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <input type="number" name="amount" step="0.01" min="0.01" required placeholder="R Amount" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                                </div>
                            </div>
                            <div>
                                <input type="text" name="notes" placeholder="Notes (optional)" class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800 transition">Add Expense</button>
                                <button type="button" @click="showAdd = false" class="rounded-lg bg-stone-100 px-4 py-2 text-xs font-semibold text-stone-600 hover:bg-stone-200 transition">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Profit & Loss Summary --}}
                @if($planningEstimate)
                    @php
                        $hasActual = $financeBreakdown && $financeBreakdown['registration_count'] > 0;
                        $mdPayout = $hasActual ? $financeBreakdown['total_md_net'] : $planningEstimate['md_net'];
                        $profitLoss = $mdPayout - $totalExpenses;
                        $pnlLabel = $hasActual ? 'Actual' : 'Projected';
                    @endphp
                    <div class="rounded-xl border {{ $profitLoss >= 0 ? 'border-emerald-200 bg-emerald-50/30' : 'border-red-200 bg-red-50/30' }} shadow-sm p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-stone-900">Profit & Loss</h2>
                            <span class="inline-flex items-center rounded-full {{ $hasActual ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-blue-50 text-blue-700 ring-blue-600/20' }} px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset">{{ $pnlLabel }}</span>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-stone-600">{{ $hasActual ? 'MD Payout (actual)' : 'MD Payout (projected)' }}</span>
                                <span class="font-semibold text-stone-900">R {{ number_format($mdPayout, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-stone-500">Total Expenses ({{ $expenses->count() }} {{ Str::plural('item', $expenses->count()) }})</span>
                                <span class="text-red-600">− R {{ number_format($totalExpenses, 2) }}</span>
                            </div>
                            <div class="border-t {{ $profitLoss >= 0 ? 'border-emerald-200' : 'border-red-200' }}"></div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-bold text-stone-900">{{ $profitLoss >= 0 ? 'Estimated Profit' : 'Estimated Loss' }}</span>
                                <span class="font-bold text-lg {{ $profitLoss >= 0 ? 'text-emerald-700' : 'text-red-600' }}">
                                    {{ $profitLoss < 0 ? '−' : '' }} R {{ number_format(abs($profitLoss), 2) }}
                                </span>
                            </div>
                            @if($planningEstimate['capacity'] > 0)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-stone-400">Per shooter</span>
                                    <span class="text-stone-500 {{ $profitLoss < 0 ? 'text-red-500' : '' }}">
                                        {{ $profitLoss < 0 ? '−' : '' }} R {{ number_format(abs($profitLoss) / $planningEstimate['capacity'], 2) }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endcan
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Related</h2>

                <div class="space-y-2">
                    <a href="{{ route('registrations.index', ['match_id' => $match->id]) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.clipboard-document-list class="size-5 text-stone-400" />
                        Registrations
                        <span class="ml-auto inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">{{ $match->registrations_count ?? $match->registrations->count() }}</span>
                    </a>
                    <a href="{{ route('matches.export-impact-scoring', $match) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.arrow-down-tray class="size-5 text-stone-400" />
                        Export for Impact Scoring
                        <span class="ml-auto text-[10px] font-medium text-stone-400">CSV</span>
                    </a>
                    <a href="{{ route('scores.entry', $match) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-emerald-800 bg-emerald-50 hover:bg-emerald-100 transition">
                        <flux:icon.pencil-square class="size-5 text-emerald-600" />
                        Enter Scores
                        @if($match->isMultiDay())
                            <span class="ml-auto inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700">2-day</span>
                        @endif
                    </a>
                    <a href="{{ route('scores.index', ['match_id' => $match->id]) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.chart-bar class="size-5 text-stone-400" />
                        View Scores
                        <span class="ml-auto inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">{{ $match->scores_count ?? $match->scores->count() }}</span>
                    </a>
                    <a href="{{ route('score-imports.index', ['match_id' => $match->id]) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 transition">
                        <flux:icon.arrow-up-tray class="size-5 text-stone-400" />
                        Score Imports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <x-sponsors-strip placement="match_pages" class="mt-8 border-t border-stone-200" />
</x-layouts.app>
