<x-layouts.app :title="'Create Platform Payout - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.payouts') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Back to Payouts</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Create Platform Payout</h1>
            <p class="mt-1 text-sm text-stone-500">
                Bundles every platform fee from paid registrations in the chosen month into a single payout to the platform operator.
                Cashflow is grouped by <strong class="text-stone-700">when the shooter paid</strong>, not when the match runs.
            </p>
        </div>

        @if(!$operatorConfigured)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Platform operator is not set.</p>
            <p class="mt-1">Pick who receives the payout in
                <a href="{{ route('site-settings.index') }}" class="underline underline-offset-2">Site Settings → Match Fee Structure</a>
                before generating a payout.
            </p>
        </div>
        @endif

        <form method="GET" action="{{ route('financials.payouts.platform.create') }}"
              class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4 max-w-xl">
            <div>
                <label for="month" class="block text-sm font-medium text-stone-700 mb-1">Payout Month</label>
                <input type="month" name="month" id="month"
                       value="{{ $month->format('Y-m') }}"
                       max="{{ now()->format('Y-m') }}"
                       class="rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                <p class="mt-1 text-xs text-stone-400">Any date inside the month is fine — the payout always covers the 1st to the last day.</p>
            </div>
            <div>
                <button type="submit"
                        class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-200 transition">
                    Preview
                </button>
            </div>
        </form>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <h2 class="font-heading text-lg font-semibold text-stone-900">
                {{ $preview['period_start']->format('F Y') }}
            </h2>
            <p class="mt-1 text-xs text-stone-500">
                {{ $preview['period_start']->format('d M Y') }} — {{ $preview['period_end']->format('d M Y') }}
            </p>

            <dl class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <dt class="text-xs uppercase text-stone-500">Paid Registrations</dt>
                    <dd class="mt-1 text-2xl font-bold text-stone-900">{{ $preview['entry_count'] }}</dd>
                </div>
                <div class="rounded-lg border border-violet-200 bg-violet-50 p-4">
                    <dt class="text-xs uppercase text-violet-700">Platform Fees</dt>
                    <dd class="mt-1 text-2xl font-bold text-violet-900">R{{ number_format($preview['platform_fees'], 2) }}</dd>
                </div>
                <div class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <dt class="text-xs uppercase text-stone-500">Status</dt>
                    <dd class="mt-1 text-sm font-semibold">
                        @if($preview['existing_payout'])
                            <span class="text-emerald-700">Already generated</span>
                            <div class="mt-1 text-xs font-normal text-stone-500">
                                {{ $preview['existing_payout']->reference }} · {{ ucfirst($preview['existing_payout']->status) }}
                            </div>
                        @elseif($preview['entry_count'] === 0)
                            <span class="text-stone-500">Nothing to settle</span>
                        @else
                            <span class="text-amber-700">Not yet generated</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if($preview['existing_payout'])
                <p class="mt-4 text-sm text-stone-500">
                    This month has already been rolled into
                    <span class="font-mono text-xs">{{ $preview['existing_payout']->reference }}</span>.
                </p>
            @elseif($preview['entry_count'] === 0)
                <p class="mt-4 text-sm text-stone-500">
                    No paid registrations booked a platform fee in {{ $preview['period_start']->format('F Y') }}.
                    Pick another month or wait until registrations settle.
                </p>
            @else
                <form method="POST" action="{{ route('financials.payouts.platform.store') }}"
                      class="mt-6 space-y-4 border-t border-stone-100 pt-6">
                    @csrf
                    <input type="hidden" name="month" value="{{ $preview['period_start']->format('Y-m') }}">

                    @if ($errors->any())
                        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <div>
                        <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes (optional)</label>
                        <textarea name="notes" id="notes" rows="3" maxlength="500"
                                  class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500"
                                  placeholder="Any notes about this payout...">{{ old('notes') }}</textarea>
                    </div>

                    <button type="submit"
                            @disabled(!$operatorConfigured)
                            class="rounded-xl bg-violet-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-violet-800 transition disabled:bg-stone-300 disabled:cursor-not-allowed">
                        Generate Payout for {{ $preview['period_start']->format('F Y') }}
                    </button>
                </form>
            @endif
        </div>

        @if(!empty($unsettledMonths))
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <h2 class="font-heading text-base font-semibold text-stone-900">Other unsettled months</h2>
            <ul class="mt-3 divide-y divide-stone-100">
                @foreach($unsettledMonths as $unsettled)
                <li class="flex items-center justify-between py-2 text-sm">
                    <div>
                        <p class="font-medium text-stone-800">{{ $unsettled['month']->format('F Y') }}</p>
                        <p class="text-xs text-stone-500">{{ $unsettled['entry_count'] }} entries — R{{ number_format($unsettled['platform_fees'], 2) }}</p>
                    </div>
                    <a href="{{ route('financials.payouts.platform.create', ['month' => $unsettled['month']->format('Y-m')]) }}"
                       class="text-violet-700 hover:text-violet-900 text-xs font-semibold">
                        Preview &rarr;
                    </a>
                </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
</x-layouts.app>
