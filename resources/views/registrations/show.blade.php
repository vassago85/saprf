<x-layouts.app :title="'Registration Details'">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Registration Details</h1>
        <a href="{{ route('registrations.index') }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Back
        </a>
    </div>

    <div class="mt-8 max-w-3xl space-y-6">
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Registration Information</h2>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Match</dt>
                    <dd class="mt-1 text-sm text-stone-900">
                        <a href="{{ route('events.show', $registration->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $registration->match->name }}</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Shooter</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $registration->user->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Category</dt>
                    <dd class="mt-1 text-sm text-stone-900 capitalize">{{ str_replace('_', ' ', $registration->membership_fee_category ?? '—') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Fee</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $registration->fee_amount ? 'R ' . number_format($registration->fee_amount, 2) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Payment Status</dt>
                    <dd class="mt-1.5">
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
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Registration Status</dt>
                    <dd class="mt-1.5">
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
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Registered</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ ($registration->registered_at ?? $registration->created_at)->format('d M Y H:i') }}</dd>
                </div>
                @if($registration->match?->match_date)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Match Date</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $registration->match->match_date->format('D, d M Y') }}</dd>
                </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Rifle</dt>
                    <dd class="mt-1 text-sm text-stone-900">
                        @if($registration->rifleConfiguration)
                            {{ $registration->rifleConfiguration->nickname ?: $registration->rifleConfiguration->displayName() }}
                            <span class="text-stone-400">
                                {{ collect([$registration->rifleConfiguration->make?->name, $registration->rifleConfiguration->model?->name, $registration->rifleConfiguration->calibre?->name])->filter()->implode(' · ') }}
                            </span>
                        @else
                            <span class="text-stone-400">No rifle selected</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Rifle selection / update --}}
        @if($registration->user_id === auth()->id() && $registration->registration_status !== 'cancelled' && $rifles->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-4">Update Rifle</h2>
                <form method="POST" action="{{ route('registrations.update-rifle', $registration) }}" class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
                    @csrf
                    @method('PUT')
                    <div class="flex-1 w-full">
                        <label for="rifle_configuration_id" class="block text-sm font-medium text-stone-700 mb-1">Rifle Configuration</label>
                        <select name="rifle_configuration_id" id="rifle_configuration_id"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">— No rifle selected</option>
                            @foreach($rifles as $rifle)
                                <option value="{{ $rifle->id }}" @selected($registration->rifle_configuration_id == $rifle->id)>
                                    {{ $rifle->nickname ?: ($rifle->make?->name . ' ' . $rifle->model?->name) }}
                                    @if($rifle->calibre) ({{ $rifle->calibre->name }}) @endif
                                    @if($rifle->is_primary) ★ @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit"
                            class="shrink-0 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Save Rifle
                    </button>
                </form>
                <p class="mt-2 text-xs text-stone-400">You can update your rifle at any time, even after the match.</p>
            </div>
        @endif

        {{-- Shots fired tracker --}}
        @if(($registration->user_id === auth()->id() || auth()->user()?->hasAnyRole(['owner', 'admin', 'match_director'])) && $registration->registration_status !== 'cancelled')
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-4">Shots Fired</h2>
                <form method="POST" action="{{ route('registrations.update-shots', $registration) }}" class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
                    @csrf
                    @method('PUT')
                    <div class="flex-1 w-full sm:max-w-[200px]">
                        <label for="shot_count" class="block text-sm font-medium text-stone-700 mb-1">Round Count</label>
                        <input type="number" name="shot_count" id="shot_count" min="0" max="9999" step="1"
                               value="{{ old('shot_count', $registration->shot_count) }}"
                               placeholder="e.g. 65"
                               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <button type="submit"
                            class="shrink-0 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Save
                    </button>
                </form>
                <p class="mt-2 text-xs text-stone-400">Include zeroing, sighters, and match rounds. You can update this at any time.</p>
                @if($registration->rifleConfiguration && $registration->rifleConfiguration->total_barrel_rounds > 0)
                    <p class="mt-1 text-xs text-stone-500">
                        Barrel lifetime: <strong>{{ number_format($registration->rifleConfiguration->total_barrel_rounds) }}</strong> total rounds
                    </p>
                @endif
            </div>
        @endif

        {{-- Pay Now button for unpaid registrations --}}
        @if($registration->user_id === auth()->id() && in_array($registration->payment_status, ['pending', 'unpaid']) && $registration->registration_status !== 'cancelled')
            @php $pfEnabled = app(\App\Services\PayFastService::class)->isEnabled(); @endphp
            @if($pfEnabled)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-emerald-800">Payment Required</h2>
                        <p class="text-sm text-emerald-700 mt-1">Complete your payment of <strong>R {{ number_format($registration->fee_amount, 2) }}</strong> to confirm your entry.</p>
                    </div>
                    <form method="POST" action="{{ url('/events/' . $registration->match_id . '/register') }}">
                        @csrf
                        @php
                            $existingPayment = \App\Models\Payment::where('payable_type', \App\Models\MatchRegistration::class)
                                ->where('payable_id', $registration->id)
                                ->where('status', 'pending')
                                ->first();
                        @endphp
                        @if($existingPayment)
                            <a href="{{ route('payments.redirect', $existingPayment) }}" class="px-6 py-3 rounded-xl bg-emerald-700 text-white font-semibold hover:bg-emerald-800 transition shadow-sm">
                                Pay Now — R {{ number_format($registration->fee_amount, 2) }}
                            </a>
                        @endif
                    </form>
                </div>
            @endif
        @endif

        {{-- Cancellation details (if cancelled) --}}
        @if($registration->registration_status === 'cancelled' && $registration->cancelled_at)
            <div class="rounded-xl border border-red-200 bg-red-50 p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-red-800 mb-4">Withdrawal Details</h2>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Cancelled At</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $registration->cancelled_at->format('d M Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Admin Fee</dt>
                        <dd class="mt-1 text-sm text-red-800">R {{ number_format($registration->admin_fee_charged ?? 0, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Refund Amount</dt>
                        <dd class="mt-1 text-sm font-semibold {{ ($registration->refund_amount ?? 0) > 0 ? 'text-emerald-700' : 'text-red-800' }}">
                            R {{ number_format($registration->refund_amount ?? 0, 2) }}
                        </dd>
                    </div>
                    @if($registration->cancellation_reason)
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Reason</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $registration->cancellation_reason }}</dd>
                    </div>
                    @endif
                </dl>
            </div>
        @endif

        {{-- Withdraw button for the registrant --}}
        @if($registration->user_id === auth()->id() && $registration->isWithdrawable())
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 shadow-sm" x-data="{ showForm: false }">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-amber-800">Withdraw from Match</h2>
                        @php $calc = $registration->calculateRefund(); @endphp
                        @if($calc['reason'] === 'before_deadline')
                            <p class="text-sm text-amber-700 mt-1">
                                You will receive a refund of <strong>R {{ number_format($calc['refund'], 2) }}</strong>
                                (entry fee minus R {{ number_format($calc['admin_fee'], 2) }} admin fee).
                            </p>
                            <p class="text-xs text-amber-600 mt-1">
                                Deadline: {{ $registration->withdrawalDeadline()->format('D, d M Y H:i') }}
                            </p>
                        @else
                            <p class="text-sm text-red-700 mt-1">
                                The withdrawal deadline has passed. <strong>No refund</strong> will be issued.
                            </p>
                        @endif
                    </div>
                    <button @click="showForm = !showForm"
                            class="shrink-0 px-4 py-2 rounded-lg text-sm font-semibold text-red-700 bg-white border border-red-300 hover:bg-red-50 transition">
                        Withdraw
                    </button>
                </div>

                <form x-show="showForm" x-transition method="POST"
                      action="{{ route('registrations.withdraw', $registration) }}"
                      class="mt-4 space-y-4 border-t border-amber-200 pt-4"
                      onsubmit="return confirm('Are you sure you want to withdraw? This cannot be undone.')">
                    @csrf
                    <div>
                        <label for="cancellation_reason" class="block text-sm font-medium text-amber-800 mb-1">Reason (optional)</label>
                        <textarea name="cancellation_reason" id="cancellation_reason" rows="2" maxlength="500"
                                  placeholder="Why are you withdrawing?"
                                  class="w-full rounded-lg border-amber-300 text-sm py-2 focus:ring-red-500 focus:border-red-500"></textarea>
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition">
                        Confirm Withdrawal
                    </button>
                </form>
            </div>
        @endif

        @role('owner|admin|match_director')
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Update Status</h2>

                <form method="POST" action="{{ route('registrations.update-status', $registration) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="registration_status" class="block text-sm font-medium text-stone-700">Registration Status</label>
                        <select name="registration_status" id="registration_status" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="pending" @selected($registration->registration_status === 'pending')>Pending</option>
                            <option value="confirmed" @selected($registration->registration_status === 'confirmed')>Confirmed</option>
                            <option value="waitlisted" @selected($registration->registration_status === 'waitlisted')>Waitlisted</option>
                            <option value="cancelled" @selected($registration->registration_status === 'cancelled')>Cancelled</option>
                        </select>
                    </div>

                    @if ($errors->any())
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <ul class="list-inside list-disc space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Update Status</button>
                </form>
            </div>
        @endrole
    </div>
</x-layouts.app>
