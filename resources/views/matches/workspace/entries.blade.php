<x-layouts.app :title="$match->name . ' — Entries'">
    @include('matches.workspace._chrome')

    <div class="mt-6 space-y-4">
        {{-- Toolbar: filters + actions. The email action is its own form so it
             is not nested inside the filter GET. --}}
        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4">
        <form method="GET" action="{{ route('matches.entries', $match) }}"
              class="flex flex-1 flex-wrap items-end gap-3">
            <div class="min-w-[180px] flex-1">
                <label class="block text-xs font-semibold text-stone-500">Search shooter</label>
                <input type="text" name="search" value="{{ $filters['search'] }}"
                       placeholder="Name or email"
                       class="mt-1 w-full rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-500">Status</label>
                <select name="status"
                        class="mt-1 rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">All</option>
                    <option value="paid" @selected($filters['status']==='paid')>Paid</option>
                    <option value="pending" @selected($filters['status']==='pending')>Pending</option>
                    <option value="waitlist" @selected($filters['status']==='waitlist')>Waitlist</option>
                    <option value="cancelled" @selected($filters['status']==='cancelled')>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-500">Category</label>
                <select name="category"
                        class="mt-1 rounded-md border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">All</option>
                    <option value="active_member" @selected($filters['category']==='active_member')>Member</option>
                    <option value="lapsed_member" @selected($filters['category']==='lapsed_member')>Lapsed</option>
                    <option value="non_member" @selected($filters['category']==='non_member')>Non-member</option>
                    <option value="club_entry" @selected($filters['category']==='club_entry')>Club entry</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-stone-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-stone-800">
                    Filter
                </button>
                <a href="{{ route('matches.entries', $match) }}" wire:navigate
                   class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                    Reset
                </a>
            </div>
        </form>
            <div class="ml-auto flex flex-wrap gap-2">
                @can('update', $match)
                    @if(($counts['pending_payment'] ?? 0) > 0)
                        <form method="POST" action="{{ route('matches.notify-unpaid', $match) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-semibold text-amber-950 hover:bg-amber-400"
                                    onclick="return confirm('Email all {{ $counts['pending_payment'] }} unpaid shooter(s) on this match?');">
                                Email unpaid ({{ $counts['pending_payment'] }})
                            </button>
                        </form>
                    @endif
                @endcan
                <a href="{{ route('matches.export-impact-scoring', $match) }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                    <flux:icon.arrow-down-tray class="size-4" />
                    Export CSV
                </a>
                <a href="{{ route('matches.settings', $match) }}#add-shooter" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                    <flux:icon.plus class="size-4" />
                    Add entry
                </a>
            </div>
        </div>

        @include('matches.workspace._apply-club-entry')

        {{-- Entries table --}}
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            @if($entries->isEmpty())
                <div class="px-6 py-12 text-center text-sm text-stone-500">
                    No entries match these filters.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold">Shooter</th>
                                <th class="px-4 py-2.5 text-left font-semibold">Category</th>
                                <th class="px-4 py-2.5 text-left font-semibold">Division</th>
                                <th class="px-4 py-2.5 text-left font-semibold">Payment</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Fee</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Entered</th>
                                <th class="px-4 py-2.5 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($entries as $reg)
                                @php
                                    $statusColour = match ($reg->registration_status) {
                                        'confirmed' => 'bg-emerald-50 text-emerald-800',
                                        'pending' => 'bg-amber-50 text-amber-800',
                                        'waitlisted' => 'bg-sky-50 text-sky-800',
                                        'cancelled' => 'bg-stone-100 text-stone-500 line-through',
                                        default => 'bg-stone-100 text-stone-600',
                                    };
                                    $paymentColour = match ($reg->payment_status) {
                                        'paid' => 'bg-emerald-50 text-emerald-800',
                                        'waived' => 'bg-sky-50 text-sky-800',
                                        'refunded' => 'bg-stone-100 text-stone-500',
                                        default => 'bg-amber-50 text-amber-800',
                                    };
                                @endphp
                                <tr class="hover:bg-stone-50/60">
                                    <td class="px-4 py-2.5">
                                        {{-- Shooter name is the primary click target.
                                             MD/admin lands on registrations.show which
                                             has the full edit surface (division, rifle,
                                             shots, mark-settled, withdraw) — all of
                                             which the policies already allow after the
                                             match is completed. --}}
                                        <a href="{{ route('registrations.show', $reg) }}" wire:navigate
                                           class="block font-semibold text-stone-900 hover:text-emerald-700">
                                            {{ $reg->shooter_name ?: $reg->user?->name ?: '—' }}
                                        </a>
                                        @if($reg->email)
                                            <div class="text-xs text-stone-500">{{ $reg->email }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-stone-700">{{ $reg->feeCategoryLabel() }}</td>
                                    <td class="px-4 py-2.5 text-stone-700">{{ $reg->division?->name ?: '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $paymentColour }}">
                                            {{ \Illuminate\Support\Str::title($reg->payment_status) }} · {{ $reg->paymentMethodLabel() }}
                                        </span>
                                        @if($reg->registration_status !== 'confirmed')
                                            <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusColour }}">
                                                {{ \Illuminate\Support\Str::title($reg->registration_status) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-stone-900">
                                        R {{ number_format((float) $reg->fee_amount, 2) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs text-stone-500">
                                        {{ $reg->registered_at?->format('j M') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                        @can('update', $reg)
                                            @if($reg->hasOutstandingPayment() && $reg->canSendPaymentInquiry() && $reg->user?->email && ! str_contains((string) $reg->user->email, '@saprf.walkin'))
                                                <form method="POST" action="{{ route('registrations.payment-inquiry', $reg) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                                                        Email
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                        @if($reg->hasOutstandingPayment() && $reg->registration_status !== 'cancelled')
                                            {{-- Fast path to the mark-settled form on the
                                                 detail page. The #mark-settled anchor
                                                 lives in registrations/show.blade.php so
                                                 the MD scrolls straight to it. --}}
                                            <a href="{{ route('registrations.show', $reg) }}#mark-settled"
                                               wire:navigate
                                               class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">
                                                Mark paid
                                            </a>
                                        @else
                                            <a href="{{ route('registrations.show', $reg) }}" wire:navigate
                                               class="inline-flex items-center gap-1 rounded-md border border-stone-300 bg-white px-2.5 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-50"
                                               title="Open entry to edit division, rifle, shots, or withdraw">
                                                Edit
                                            </a>
                                        @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-stone-100 bg-stone-50 px-4 py-3">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
