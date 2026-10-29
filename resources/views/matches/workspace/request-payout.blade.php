<x-layouts.app :title="$match->name . ' — Request payout'">
    @php
        $counts = [
            'paid' => (int) ($summary['paid_registrations'] ?? 0),
            'excluded' => (int) ($summary['pending_registrations'] ?? 0),
        ];
        $projectedNet = (float) ($money['md_payout_projection'] ?? ($summary['md_net'] ?? 0));
        $settledNet = (float) ($summary['md_net'] ?? 0);
        $hasProjection = $projectedNet > $settledNet + 0.009;

        // Projected entries = registrations the shooter hasn't paid for yet,
        // but with worst-case fees applied so the MD can see what they'll be
        // owed IF the person coughs up. Driving a dedicated warning off this
        // list — not off the raw count — so an all-zero stub registration
        // doesn't show up as "shooter owes you money".
        $unpaid = ($money['projected_entries'] ?? collect())->values();
        $unpaidOwedToMd = (float) $unpaid->sum('md_net_amount');
    @endphp

    <div class="mx-auto max-w-4xl space-y-6">
        {{-- Breadcrumb --}}
        <div class="text-xs text-stone-500">
            <a href="{{ route('matches.index') }}" class="hover:text-emerald-700" wire:navigate>My matches</a>
            <span class="mx-1">/</span>
            <a href="{{ route('matches.overview', $match) }}" class="hover:text-emerald-700" wire:navigate>{{ $match->name }}</a>
            <span class="mx-1">/</span>
            <span class="text-stone-700">Request payout</span>
        </div>

        <div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">
                Request your match-director payout
            </h1>
            <p class="mt-1 text-sm text-stone-600">
                Confirm the amount, give us the account to pay, and we'll mark the match completed. An admin processes
                payment from here.
            </p>
        </div>

        {{-- Amount summary. We show BOTH the settled payout (money collected
             to date) and the projected payout (settled + worst-case gateway
             on pending entries, because the MD will chase them). The payout
             request is raised against the SETTLED number — SAPRF won't pay
             out money that hasn't landed yet — but the projection is what
             the MD will end up with once the stragglers settle. --}}
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">
                        {{ $hasProjection ? 'Payout now (settled entries)' : "You'll receive" }}
                    </p>
                    <p class="mt-1 font-heading text-3xl font-bold tabular-nums text-stone-900">
                        R {{ number_format($settledNet, 2) }}
                    </p>
                    <p class="mt-1 text-xs text-stone-500">
                        Based on {{ $counts['paid'] }} paid {{ \Illuminate\Support\Str::plural('entry', $counts['paid']) }}.
                        @if($counts['excluded'] > 0)
                            {{ $counts['excluded'] }} pending {{ \Illuminate\Support\Str::plural('entry', $counts['excluded']) }} not yet in your pocket.
                        @endif
                    </p>
                </div>
                <a href="{{ route('matches.finances', $match) }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                    <flux:icon.chart-bar class="size-4" />
                    See the breakdown
                </a>
            </div>

            @if($hasProjection)
                <div class="mt-4 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-amber-200">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Projected total (incl. pending)</p>
                        <p class="mt-0.5 text-xs text-amber-700">
                            Worst-case gateway fee applied to the {{ $counts['excluded'] }} pending
                            {{ \Illuminate\Support\Str::plural('entry', $counts['excluded']) }} — on the assumption
                            you'll chase the shooter and collect the money.
                        </p>
                    </div>
                    <p class="font-heading text-xl font-bold tabular-nums text-amber-900">
                        R {{ number_format($projectedNet, 2) }}
                    </p>
                </div>
            @endif

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-stone-100 pt-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-stone-500">Collected</dt>
                    <dd class="font-semibold tabular-nums text-stone-900">R {{ number_format((float) ($summary['gross_revenue'] ?? 0), 2) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">SAPRF levy</dt>
                    <dd class="font-semibold tabular-nums text-stone-700">− R {{ number_format((float) ($summary['saprf_fees'] ?? 0), 2) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">Platform fee</dt>
                    <dd class="font-semibold tabular-nums text-stone-700">− R {{ number_format((float) ($summary['platform_fees'] ?? 0), 2) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-stone-500">Gateway</dt>
                    <dd class="font-semibold tabular-nums text-stone-700">− R {{ number_format((float) ($summary['gateway_fees'] ?? 0), 2) }}</dd>
                </div>
            </dl>
        </div>

        @if(($walkInsNeedingScores ?? collect())->isNotEmpty() || ($scoresNotImported ?? false))
            <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-6 shrink-0 text-amber-600" />
                    <div class="flex-1">
                        <h2 class="font-heading text-lg font-semibold text-amber-900">
                            Walk-ins start off negative next time
                        </h2>
                        @if(($walkInsNeedingScores ?? collect())->isNotEmpty())
                            <p class="mt-1 text-sm text-amber-800">
                                {{ $walkInsNeedingScores->count() }}
                                {{ \Illuminate\Support\Str::plural('walk-in', $walkInsNeedingScores->count()) }}
                                still {{ $walkInsNeedingScores->count() === 1 ? 'needs' : 'need' }} a score.
                                {{ $walkInsNeedingScores->count() === 1 ? 'That entry starts' : 'Those entries start' }}
                                off negative next time. The fee you collected stays with you. The SAPRF levy and the platform fee come off your next payout, not this one.
                            </p>
                            <ul class="mt-3 divide-y divide-amber-200 rounded-lg border border-amber-200 bg-white/60">
                                @foreach($walkInsNeedingScores as $reg)
                                    <li class="px-4 py-2 text-sm font-medium text-stone-900">
                                        {{ $reg->shooter_name ?: ($reg->user?->name ?? 'Walk-in') }}
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-sm text-amber-800">
                                Scores are not in yet. If you still have walk-ins whose scores need tracking, they will start off negative next time. The fee you collected stays with you. The SAPRF levy and the platform fee come off your next payout, not this one.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Unpaid shooters warning. Shown ONLY when at least one shooter
             still owes money. We want the MD to actively notice this before
             they lock in the payout, because any money that lands after the
             payout is raised sits in SAPRF's account and takes an admin to
             release. --}}
        @if($unpaid->isNotEmpty())
            <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-6 shrink-0 text-amber-600" />
                    <div class="flex-1">
                        <h2 class="font-heading text-lg font-semibold text-amber-900">
                            {{ $unpaid->count() }}
                            {{ \Illuminate\Support\Str::plural('shooter', $unpaid->count()) }}
                            {{ $unpaid->count() === 1 ? 'still owes' : 'still owe' }}
                            you money
                        </h2>
                        <p class="mt-1 text-sm text-amber-800">
                            If you raise the payout now, the
                            <strong class="tabular-nums">R {{ number_format($unpaidOwedToMd, 2) }}</strong>
                            below is <strong>not</strong> included. Anything they pay afterwards sits with SAPRF
                            until an admin releases a top-up — so if you're expecting these to settle, consider
                            chasing them first.
                        </p>

                        <ul class="mt-4 divide-y divide-amber-200 rounded-lg border border-amber-200 bg-white/60">
                            @foreach($unpaid as $reg)
                                @php
                                    $name = $reg->shooter_name ?: ($reg->user?->name ?? 'Unknown shooter');
                                    $contact = $reg->email ?: $reg->user?->email;
                                @endphp
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-stone-900">{{ $name }}</p>
                                        @if($contact)
                                            <p class="truncate text-xs text-stone-500">{{ $contact }}</p>
                                        @endif
                                    </div>
                                    <p class="shrink-0 font-semibold tabular-nums text-stone-900">
                                        R {{ number_format((float) $reg->md_net_amount, 2) }}
                                    </p>
                                </li>
                            @endforeach
                            <li class="flex items-center justify-between gap-3 bg-amber-100/70 px-4 py-2.5 text-sm">
                                <p class="font-semibold text-amber-900">Total still outstanding</p>
                                <p class="font-heading text-base font-bold tabular-nums text-amber-900">
                                    R {{ number_format($unpaidOwedToMd, 2) }}
                                </p>
                            </li>
                        </ul>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('matches.entries', $match) }}" wire:navigate
                               class="inline-flex items-center gap-1.5 rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-50">
                                <flux:icon.users class="size-4" />
                                Chase them in Entries
                            </a>
                            <span class="inline-flex items-center text-xs text-amber-800">
                                or scroll down to raise the payout anyway.
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Banking form --}}
        <form method="POST" action="{{ route('matches.complete-and-request-payout', $match) }}"
              class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
            @csrf

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Where should we pay you?</h2>
                    <p class="mt-1 text-sm text-stone-500">
                        These details are saved onto the payout record so the finance team can process your transfer
                        without chasing you.
                    </p>
                </div>
                @if($banking)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
                        <flux:icon.check-circle class="size-3.5" />
                        Pre-filled from your last payout
                    </span>
                @endif
            </div>

            <div>
                <label for="account_holder" class="block text-sm font-medium text-stone-700">
                    Account holder <span class="text-red-500">*</span>
                </label>
                <input type="text" name="account_holder" id="account_holder" required maxlength="255"
                       value="{{ old('account_holder', $banking['account_holder'] ?? auth()->user()->name) }}"
                       class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                @error('account_holder')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="bank_name" class="block text-sm font-medium text-stone-700">
                    Bank <span class="text-red-500">*</span>
                </label>
                <input type="text" name="bank_name" id="bank_name" required maxlength="100"
                       value="{{ old('bank_name', $banking['bank_name'] ?? '') }}"
                       placeholder="e.g. FNB, Standard Bank, Capitec"
                       class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                @error('bank_name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="account_number" class="block text-sm font-medium text-stone-700">
                        Account number <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="account_number" id="account_number" required inputmode="numeric"
                           minlength="6" maxlength="30"
                           value="{{ old('account_number', $banking['account_number'] ?? '') }}"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono tracking-wide">
                    @error('account_number')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="branch_code" class="block text-sm font-medium text-stone-700">
                        Branch code <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="branch_code" id="branch_code" required inputmode="numeric"
                           minlength="6" maxlength="6" pattern="[0-9]{6}"
                           value="{{ old('branch_code', $banking['branch_code'] ?? '') }}"
                           placeholder="6-digit universal code"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono tracking-wide">
                    @error('branch_code')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
                <strong class="font-semibold">Heads up:</strong> once you submit, the match is marked completed and
                the payout request is locked. If you spot an incorrect banking detail after submitting, ask an admin
                to update it before they pay.
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-4">
                <a href="{{ route('matches.finances', $match) }}" wire:navigate
                   class="text-sm font-medium text-stone-600 hover:text-stone-900">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                    <flux:icon.banknotes class="size-4" />
                    Complete match &amp; request payout
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
