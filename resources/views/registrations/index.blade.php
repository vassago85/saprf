<x-layouts.app :title="'Registrations'">
    @php
        $canViewFinancials = $canViewFinancials ?? false;
        $isPrivileged = $isPrivileged ?? false;
        $match = $match ?? null;
        $colspan = $canViewFinancials ? 9 : 7;
    @endphp

    @if($match)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-stone-400">Entry List</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">{{ $match->name }}</h1>
                <p class="mt-1 text-sm text-stone-500">
                    {{ $registrations->total() }} {{ Str::plural('shooter', $registrations->total()) }} registered
                </p>
            </div>
            <a href="{{ route('events.show', $match) }}" class="inline-flex items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50">
                View Event
            </a>
        </div>
    @else
        <h1 class="font-heading text-3xl font-bold text-stone-900">Registrations</h1>
    @endif

    <div class="mt-8 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full">
            <thead>
                <tr class="border-b-2 border-stone-200 bg-stone-50">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Match</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Shooter</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Division</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Category</th>
                    @if($canViewFinancials)
                        <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Fee</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Payment</th>
                    @endif
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($registrations as $registration)
                    <tr class="hover:bg-stone-50 transition-colors">
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm font-medium text-stone-900">
                            <a href="{{ route('events.show', $registration->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $registration->match->name }}</a>
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-900">{{ $registration->user->name }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            @if($registration->division)
                                <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">{{ $registration->division->name }}</span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-500 ring-1 ring-inset ring-stone-200"
                                      title="Division was not selected at registration — you can set it from the registration details page.">
                                    Not set
                                </span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $registration->feeCategoryLabel() }}</td>
                        @if($canViewFinancials)
                            <td class="whitespace-nowrap px-5 py-3.5 text-sm text-right font-mono text-stone-900">
                                {{ $registration->fee_amount ? 'R ' . number_format($registration->fee_amount, 2) : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                @switch($registration->payment_status)
                                    @case('paid')
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Paid</span>
                                        @break
                                    @case('pending')
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                        @break
                                    @case('waived')
                                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Waived</span>
                                        @break
                                    @default
                                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst($registration->payment_status ?? 'N/A') }}</span>
                                @endswitch
                            </td>
                        @endif
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            @switch($registration->registration_status)
                                @case('confirmed')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Confirmed</span>
                                    @break
                                @case('pending')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                    @break
                                @case('waitlisted')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Waitlisted</span>
                                    @break
                                @case('cancelled')
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Cancelled</span>
                                    @break
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $registration->created_at->format('d M Y') }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                            @if($canViewFinancials || $registration->user_id === auth()->id())
                                <a href="{{ route('registrations.show', $registration) }}" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700" title="View">
                                    <svg class="inline h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                </a>
                            @else
                                <span class="text-stone-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colspan }}" class="px-5 py-12 text-center text-sm text-stone-400">No registrations found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $registrations->withQueryString()->links() }}
    </div>
</x-layouts.app>
