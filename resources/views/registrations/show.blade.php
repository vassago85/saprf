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
                @php
                    $enteredBy = $registration->registeredBy;
                    $showEnteredBy = $enteredBy && $enteredBy->id !== $registration->user_id;
                    $showPaidBy = ($payer ?? null) && $payer->id !== $registration->user_id;
                @endphp
                @if($showEnteredBy)
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Entered by</dt>
                        <dd class="mt-1 text-sm text-stone-900">{{ $enteredBy->name }}</dd>
                    </div>
                @endif
                @if($showPaidBy)
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Paid by</dt>
                        <dd class="mt-1 text-sm text-stone-900">{{ $payer->name }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Division</dt>
                    <dd class="mt-1.5">
                        @if($registration->division)
                            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">{{ $registration->division->name }}</span>
                        @else
                            <span class="text-sm text-stone-400">—</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Category</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $registration->feeCategoryLabel() }}</dd>
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

        @if($registration->canAccessWhatsappInvite())
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-2">Match WhatsApp group</h2>
                <p class="text-sm text-stone-600 mb-4">Join the group for match notifications, match books, and range updates.</p>
                @include('matches._whatsapp-invite-cta', ['url' => $registration->whatsappInviteUrl()])
            </div>
        @endif

        {{-- Invoice download. Only renders when a completed, non-zero
             payment exists on this registration AND the signed-in user
             paid it (or manages the paying account / is support staff).
             The route reruns the same policy itself. --}}
        @if(!empty($invoicePayment) && (
            $invoicePayment->user_id === auth()->id()
            || auth()->user()?->findManagedAccount($invoicePayment->user_id)
            || auth()->user()?->hasAnyRole(['developer', 'exco', 'owner', 'admin'])
        ))
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Invoice</h2>
                    <p class="text-sm text-stone-500 mt-1">
                        R {{ number_format((float) $invoicePayment->amount, 2) }}
                        &middot; Paid {{ $invoicePayment->paid_at?->format('d M Y') }}
                        &middot; Ref <span class="font-mono text-xs">{{ $invoicePayment->m_payment_id }}</span>
                    </p>
                </div>
                <a href="{{ route('payments.invoice', $invoicePayment) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Download invoice
                </a>
            </div>
        @endif

        {{-- Division change — allowed until registration closes (staff any time) --}}
        @php
            $isStaffViewer = auth()->user()?->hasAnyRole(['owner', 'admin', 'match_director']);
            $ownsRegistration = $registration->user_id === auth()->id();
            $canEditEntry = $registration->canEditEntry();
        @endphp
        @if(($ownsRegistration || $isStaffViewer) && ($canEditEntry || $isStaffViewer) && $divisions->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-4">Change Division</h2>
                <form method="POST" action="{{ route('registrations.update-division', $registration) }}" class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
                    @csrf
                    @method('PUT')
                    <div class="flex-1 w-full">
                        <label for="division_id" class="block text-sm font-medium text-stone-700 mb-1">Division</label>
                        <select name="division_id" id="division_id" required
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="" disabled @selected(!$registration->division_id)>— Select a division —</option>
                            @foreach($divisions as $division)
                                <option value="{{ $division->id }}" @selected($registration->division_id == $division->id)>{{ $division->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit"
                            class="shrink-0 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Save Division
                    </button>
                </form>
                <p class="mt-2 text-xs text-stone-400">
                    @if($registration->match?->registration_close_date)
                        You can change your division until registration closes on {{ $registration->match->registration_close_date->format('D, d M Y H:i') }}.
                    @else
                        You can change your division until registration closes.
                    @endif
                </p>
            </div>
        @endif

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
                                    @if($rifle->primarySeriesLabel()) ({{ $rifle->primarySeriesLabel() }}) @endif
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
            @php
                $pfEnabled = app(\App\Services\PayFastService::class)->isEnabled();
                $creditPreview = app(\App\Services\AccountCreditService::class)->preview(auth()->user(), $registration);
                $payLabel = match (true) {
                    $creditPreview['credit'] >= $creditPreview['fee'] && $creditPreview['fee'] > 0 => 'Use entry credit — R '.number_format($creditPreview['fee'], 2),
                    $creditPreview['credit'] > 0 => 'Pay R '.number_format($creditPreview['card'], 2).' (R '.number_format($creditPreview['credit'], 2).' credit)',
                    default => 'Pay Now — R '.number_format((float) $registration->fee_amount, 2),
                };
            @endphp
            @if($pfEnabled || $creditPreview['card'] <= 0)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-emerald-800">Payment Required</h2>
                        @if($creditPreview['credit'] >= $creditPreview['fee'] && $creditPreview['fee'] > 0)
                            <p class="text-sm text-emerald-700 mt-1">Your account credit covers this entry of <strong>R {{ number_format($creditPreview['fee'], 2) }}</strong>.</p>
                        @elseif($creditPreview['credit'] > 0)
                            <p class="text-sm text-emerald-700 mt-1">Entry fee <strong>R {{ number_format($creditPreview['fee'], 2) }}</strong>. <strong>R {{ number_format($creditPreview['credit'], 2) }}</strong> comes off your credit; the card charge is <strong>R {{ number_format($creditPreview['card'], 2) }}</strong>.</p>
                        @else
                            <p class="text-sm text-emerald-700 mt-1">Complete your payment of <strong>R {{ number_format($registration->fee_amount, 2) }}</strong> to confirm your entry.</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('payments.registration', $registration) }}">
                        @csrf
                        <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-700 text-white font-semibold hover:bg-emerald-800 transition shadow-sm">
                            {{ $payLabel }}
                        </button>
                    </form>
                </div>
            @endif
        @endif

        {{-- MD/admin: mark settled after POP or PayFast screenshot --}}
        @can('update', $registration)
            @if($registration->hasOutstandingPayment() && $registration->registration_status !== 'cancelled')
                {{-- id anchor so the workspace Entries → Mark paid deep link
                     scrolls straight to this card instead of making the MD
                     hunt for it on a long detail page. --}}
                <div id="mark-settled" class="rounded-xl border border-amber-200 bg-amber-50/60 p-6 shadow-sm">
                    <h2 class="font-heading text-lg font-semibold text-stone-900 mb-1">Mark payment settled</h2>
                    <p class="text-sm text-stone-600 mb-4">Use when the shooter replies with a POP or a PayFast confirmation screenshot. This marks the entry paid without a new PayFast charge.</p>
                    <form method="POST" action="{{ route('registrations.mark-settled', $registration) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-stone-700 mb-2">Proof received</label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <label class="flex items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:ring-1 has-[:checked]:ring-emerald-500">
                                    <input type="radio" name="settlement_proof" value="pop" class="text-emerald-600" @checked(old('settlement_proof') === 'pop') required>
                                    POP / EFT
                                </label>
                                <label class="flex items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:ring-1 has-[:checked]:ring-emerald-500">
                                    <input type="radio" name="settlement_proof" value="payfast_screenshot" class="text-emerald-600" @checked(old('settlement_proof') === 'payfast_screenshot')>
                                    PayFast screenshot
                                </label>
                                <label class="flex items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:ring-1 has-[:checked]:ring-emerald-500">
                                    <input type="radio" name="settlement_proof" value="other" class="text-emerald-600" @checked(old('settlement_proof') === 'other')>
                                    Other
                                </label>
                            </div>
                            @error('settlement_proof') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="settlement_note" class="block text-sm font-medium text-stone-700 mb-1">Note <span class="text-red-500">*</span></label>
                            <textarea name="settlement_note" id="settlement_note" rows="2" required minlength="5" maxlength="500"
                                      placeholder="e.g. POP received 19 Oct — EFT to range bank account"
                                      class="w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">{{ old('settlement_note') }}</textarea>
                            @error('settlement_note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit"
                                class="inline-flex items-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition"
                                onclick="return confirm('Mark this entry as paid based on the proof you received?');">
                            Mark as paid
                        </button>
                    </form>
                </div>

                {{-- Comp this entry — alternative to Mark paid. Use when the
                     MD is giving the shooter a free entry (travelling comp,
                     prize comp, exco invite). Fee zeroed; MD covers the
                     SAPRF + platform fees out of their own payout. --}}
                @php
                    $compSaprf = (float) $registration->saprf_fee;
                    $compPlatform = (float) $registration->platform_fee;
                    $compOwed = $compSaprf + $compPlatform;
                @endphp
                <div class="rounded-xl border border-sky-200 bg-sky-50/60 p-6 shadow-sm mt-4">
                    <h2 class="font-heading text-lg font-semibold text-stone-900 mb-1">Comp this entry</h2>
                    <p class="text-sm text-stone-600 mb-4">
                        Use when you're giving this shooter a free entry. The shooter owes nothing;
                        @if($compOwed > 0)
                            <span class="font-semibold">R{{ number_format($compOwed, 2) }}</span>
                            (SAPRF R{{ number_format($compSaprf, 2) }} + platform R{{ number_format($compPlatform, 2) }})
                            comes off your payout for this match.
                        @else
                            no SAPRF or platform fee applies to this row.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('registrations.comp', $registration) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label for="comp_note" class="block text-sm font-medium text-stone-700 mb-1">Reason <span class="text-red-500">*</span></label>
                            <textarea name="comp_note" id="comp_note" rows="2" required minlength="5" maxlength="500"
                                      placeholder="e.g. Travelled from EC, MD covered. Prize comp from previous match."
                                      class="w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-sky-500 focus:border-sky-500">{{ old('comp_note') }}</textarea>
                            @error('comp_note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit"
                                class="inline-flex items-center rounded-xl bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-sky-800 transition"
                                onclick="return confirm('Comp this entry? Shooter owes nothing. R{{ number_format($compOwed, 2) }} (SAPRF+platform) will come off your payout.');">
                            Comp this entry
                        </button>
                    </form>
                </div>
            @endif
        @endcan

        {{-- Cancellation details (if cancelled) --}}
        @if($registration->registration_status === 'cancelled' && $registration->cancelled_at)
            @php
                // Collapse the refund/admin-fee lines whenever no money changed
                // hands — either a free entry, or an unpaid withdrawal where
                // the card never went through. Otherwise we'd show a nonsense
                // "Admin Fee: R 0.00" beside a "Refund: R 0.00" row.
                $noFinancialImpact = ((float) ($registration->refund_amount ?? 0)) === 0.0
                    && ((float) ($registration->admin_fee_charged ?? 0)) === 0.0;
                $wasFreeEntry = $noFinancialImpact && ((float) $registration->fee_amount) <= 0;
                $refundedToBank = $registration->withdrawal_option === 'refund';
                $creditedToAccount = ! $noFinancialImpact && ! $refundedToBank && $registration->hasIssuedCredit();
                $reentryMatch = $registration->match;
                $canReenter = $reentryMatch
                    && in_array(auth()->id(), [$registration->user_id, $registration->registered_by_user_id], true)
                    && ($reentryMatch->isRegistrationOpen() || $reentryMatch->isWaitlistOpen())
                    && ! $reentryMatch->userRegistration($registration->user);
                $reentryCredit = $canReenter ? auth()->user()->accountCreditSummary()['available'] : 0;
            @endphp
            <div class="rounded-xl border border-red-200 bg-red-50 p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-red-800 mb-4">Withdrawal Details</h2>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Cancelled At</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $registration->cancelled_at->format('d M Y H:i') }}</dd>
                    </div>
                    @if($noFinancialImpact)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Financial Impact</dt>
                            <dd class="mt-1 text-sm text-red-800">{{ $wasFreeEntry ? 'Free entry — none.' : 'No payment was collected — none.' }}</dd>
                        </div>
                    @elseif($creditedToAccount)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Credited to Account</dt>
                            <dd class="mt-1 text-sm font-semibold text-emerald-700">R {{ number_format($registration->refund_amount ?? 0, 2) }}</dd>
                        </div>
                    @elseif($refundedToBank)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Withdrawal Fee</dt>
                            <dd class="mt-1 text-sm text-red-800">R {{ number_format($registration->admin_fee_charged ?? 0, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Refund to Bank</dt>
                            <dd class="mt-1 text-sm font-semibold text-emerald-700">R {{ number_format($registration->refund_amount ?? 0, 2) }}</dd>
                        </div>
                    @else
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
                    @endif
                    @if($registration->cancellation_reason)
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Reason</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $registration->cancellation_reason }}</dd>
                    </div>
                    @endif
                </dl>

                @if($canReenter)
                    <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-red-200 pt-4">
                        <a href="{{ route('events.register', $registration->user_id === auth()->id() ? $reentryMatch : [$reentryMatch, 'for_user' => $registration->user_id]) }}"
                           class="inline-flex items-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                            Enter this match again
                        </a>
                        <p class="text-sm text-red-800">
                            @if($reentryCredit > 0)
                                Your R {{ number_format($reentryCredit, 2) }} entry credit is applied first.
                            @elseif($creditedToAccount)
                                Your entry credit has been used, so the entry fee is paid as normal.
                            @endif
                        </p>
                    </div>
                @endif
            </div>
        @endif

        {{-- Withdraw button for the registrant --}}
        @if($registration->user_id === auth()->id() && $registration->isWithdrawable())
            @php
                $calc = $registration->calculateRefund();
                $hasChoice = in_array($calc['reason'], ['before_deadline', 'paid_offline'], true);
                $payerId = $hasChoice ? $registration->withdrawalCreditHolderId() : null;
                $canTakeCash = $calc['reason'] === 'before_deadline' && $calc['refund'] > 0 && $payerId === auth()->id();
                $creditHolder = $hasChoice && $payerId !== auth()->id() ? \App\Models\User::find($payerId) : null;
            @endphp
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 shadow-sm"
                 x-data="{ showForm: {{ $errors->hasAny(['withdrawal_option', 'account_holder', 'bank_name', 'account_number', 'branch_code']) ? 'true' : 'false' }}, option: '{{ old('withdrawal_option', $hasChoice ? 'credit' : '') }}' }">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-amber-800">Withdraw from Match</h2>
                        @switch($calc['reason'])
                            @case('free_entry')
                                <p class="text-sm text-amber-700 mt-1">
                                    This is a <strong>free entry</strong> — you can withdraw at any time with no financial impact.
                                </p>
                                @break
                            @case('unpaid')
                                <p class="text-sm text-amber-700 mt-1">
                                    No payment has been collected yet, so withdrawing carries <strong>no financial impact</strong>.
                                </p>
                                @break
                            @case('paid_offline')
                                <p class="text-sm text-amber-700 mt-1">
                                    This entry was paid outside this website (for example on the previous SAPRF site).
                                    You can withdraw and keep <strong>R {{ number_format($calc['credit'], 2) }}</strong> as entry credit for another match.
                                </p>
                                <p class="text-xs text-amber-600 mt-1">
                                    Deadline: {{ $registration->withdrawalDeadline()->format('D, d M Y H:i') }}
                                </p>
                                @break
                            @case('before_deadline')
                                <p class="text-sm text-amber-700 mt-1">
                                    Keep the full <strong>R {{ number_format($calc['credit'], 2) }}</strong> as entry credit with no fee,
                                    @if($canTakeCash)
                                        or have <strong>R {{ number_format($calc['refund'], 2) }}</strong> refunded to your bank (R {{ number_format($calc['admin_fee'], 2) }} withdrawal fee).
                                    @else
                                        to use on another match.
                                    @endif
                                </p>
                                <p class="text-xs text-amber-600 mt-1">
                                    Deadline: {{ $registration->withdrawalDeadline()->format('D, d M Y H:i') }}
                                </p>
                                @break
                            @default
                                <p class="text-sm text-red-700 mt-1">
                                    The withdrawal deadline has passed. <strong>No refund</strong> will be issued.
                                </p>
                        @endswitch
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
                    @if($hasChoice)
                        <fieldset class="space-y-2">
                            <legend class="block text-sm font-medium text-amber-800 mb-1">What should happen to your entry fee?</legend>
                            <label class="flex items-start gap-3 rounded-lg border border-amber-200 bg-white p-3 cursor-pointer">
                                <input type="radio" name="withdrawal_option" value="credit" x-model="option" class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                                <span class="text-sm text-stone-700">
                                    <strong class="text-stone-900">Keep R {{ number_format($calc['credit'], 2) }} as entry credit</strong> — no fee.
                                    @if($creditHolder)
                                        The credit goes to {{ $creditHolder->name }}, who paid for this entry.
                                    @else
                                        It is applied automatically next time you enter a match.
                                    @endif
                                </span>
                            </label>
                            @if($canTakeCash)
                                <label class="flex items-start gap-3 rounded-lg border border-amber-200 bg-white p-3 cursor-pointer">
                                    <input type="radio" name="withdrawal_option" value="refund" x-model="option" class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                                    <span class="text-sm text-stone-700">
                                        <strong class="text-stone-900">Refund R {{ number_format($calc['refund'], 2) }} to my bank account</strong>
                                        — R {{ number_format($calc['admin_fee'], 2) }} withdrawal fee deducted. SAPRF pays it by EFT.
                                    </span>
                                </label>
                            @endif
                            @error('withdrawal_option')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        </fieldset>

                        @if($canTakeCash)
                            <div x-show="option === 'refund'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="sm:col-span-2">
                                    <label for="account_holder" class="block text-sm font-medium text-amber-800 mb-1">Account holder</label>
                                    <input type="text" name="account_holder" id="account_holder" maxlength="255" value="{{ old('account_holder', auth()->user()->name) }}"
                                           :required="option === 'refund'" :disabled="option !== 'refund'"
                                           class="w-full rounded-lg border-amber-300 text-sm py-2 focus:ring-red-500 focus:border-red-500">
                                    @error('account_holder')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="bank_name" class="block text-sm font-medium text-amber-800 mb-1">Bank</label>
                                    <input type="text" name="bank_name" id="bank_name" maxlength="100" value="{{ old('bank_name') }}"
                                           :required="option === 'refund'" :disabled="option !== 'refund'"
                                           class="w-full rounded-lg border-amber-300 text-sm py-2 focus:ring-red-500 focus:border-red-500">
                                    @error('bank_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="account_number" class="block text-sm font-medium text-amber-800 mb-1">Account number</label>
                                    <input type="text" name="account_number" id="account_number" inputmode="numeric" value="{{ old('account_number') }}"
                                           :required="option === 'refund'" :disabled="option !== 'refund'"
                                           class="w-full rounded-lg border-amber-300 text-sm py-2 focus:ring-red-500 focus:border-red-500">
                                    @error('account_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="branch_code" class="block text-sm font-medium text-amber-800 mb-1">Branch code</label>
                                    <input type="text" name="branch_code" id="branch_code" inputmode="numeric" value="{{ old('branch_code') }}"
                                           :required="option === 'refund'" :disabled="option !== 'refund'"
                                           class="w-full rounded-lg border-amber-300 text-sm py-2 focus:ring-red-500 focus:border-red-500">
                                    @error('branch_code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endif
                    @endif
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
            @role('developer|exco|owner|admin')
            @if($registration->canCorrectCategory())
                <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                    <h2 class="font-heading text-lg font-semibold text-stone-900 mb-2">Correct Category</h2>
                    <p class="text-sm text-stone-500 mb-5">Recalculate the entry fee for a different membership bracket. The surcharge is included or removed automatically. Only available while payment is outstanding.</p>

                    <form method="POST" action="{{ route('registrations.update-category', $registration) }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="membership_fee_category" class="block text-sm font-medium text-stone-700">Category</label>
                            <select name="membership_fee_category" id="membership_fee_category" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <option value="active_member" @selected($registration->membership_fee_category === 'active_member')>Active Member</option>
                                <option value="lapsed_member" @selected($registration->membership_fee_category === 'lapsed_member')>Lapsed Member</option>
                                <option value="non_member" @selected($registration->membership_fee_category === 'non_member')>Non-member</option>
                            </select>
                        </div>

                        <div>
                            <label for="fee_override_reason" class="block text-sm font-medium text-stone-700">Reason</label>
                            <textarea name="fee_override_reason" id="fee_override_reason" rows="2" required minlength="5" maxlength="500"
                                      placeholder="e.g. Member was not lapsed at the time of registration."
                                      class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">{{ old('fee_override_reason', $registration->fee_override_reason) }}</textarea>
                            <p class="mt-1 text-xs text-stone-400">Stored on the entry so the fee change is auditable.</p>
                            @error('fee_override_reason')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Category &amp; Recalculate Fee</button>
                    </form>
                </div>
            @endif
            @endrole

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Update Status</h2>

                @php
                    $creditsOnCancel = $registration->registration_status !== 'cancelled' && $registration->hasCompletedPlatformPayment();
                @endphp

                <form method="POST" action="{{ route('registrations.update-status', $registration) }}" class="space-y-4"
                      x-data="{ status: @js(old('registration_status', $registration->registration_status)) }">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="registration_status" class="block text-sm font-medium text-stone-700">Registration Status</label>
                        <select name="registration_status" id="registration_status" required x-model="status" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="pending" @selected($registration->registration_status === 'pending')>Pending</option>
                            <option value="confirmed" @selected($registration->registration_status === 'confirmed')>Confirmed</option>
                            <option value="waitlisted" @selected($registration->registration_status === 'waitlisted')>Waitlisted</option>
                            <option value="cancelled" @selected($registration->registration_status === 'cancelled')>Cancelled</option>
                        </select>
                    </div>

                    @if($creditsOnCancel)
                        <div x-show="status === 'cancelled'" x-cloak class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <p class="text-sm text-amber-800">This entry was paid online. Cancelling it credits the full amount paid to the payer's SAPRF account and emails them the reason.</p>
                            <label for="staff_cancellation_reason" class="mt-3 block text-sm font-medium text-amber-900">Reason for removal</label>
                            <textarea name="cancellation_reason" id="staff_cancellation_reason" rows="2" minlength="5" maxlength="500"
                                      x-bind:required="status === 'cancelled'"
                                      placeholder="e.g. Shooter can no longer attend."
                                      class="mt-1 block w-full rounded-lg border border-amber-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500">{{ old('cancellation_reason') }}</textarea>
                        </div>
                    @endif

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
