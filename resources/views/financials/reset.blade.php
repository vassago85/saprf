<x-layouts.app :title="'Clear Finance Data - SAPRF'">
    @php
        $labels = [
            'financial_transactions' => 'Financial Transactions',
            'payout_items' => 'Payout Items',
            'payouts' => 'Payouts',
            'platform_expenses' => 'Platform Expenses',
            'platform_income' => 'Platform Income',
            'match_expenses' => 'Match Expenses',
            'payments' => 'Payments',
            'membership_payments' => 'Membership Payments',
        ];
    @endphp

    <div class="max-w-xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-red-900">Clear Finance Data</h1>
            <a href="{{ route('financials.dashboard') }}" class="text-sm text-stone-600 font-medium hover:text-stone-800">&larr; Back</a>
        </div>

        <div class="rounded-xl border-2 border-red-300 bg-red-50 p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="inline-flex items-center justify-center size-10 rounded-lg bg-red-100 text-red-700 shrink-0 mt-0.5">
                    <svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </div>
                <div>
                    <h2 class="font-heading text-lg font-bold text-red-900">This action is irreversible</h2>
                    <p class="text-sm text-red-800 mt-1">
                        This permanently deletes all financial records and resets every match registration to unpaid,
                        so the dashboard starts at <strong>R0</strong>. Members, matches, scores, fee tiers and settings are kept.
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-4">Data that will be cleared</h2>

            <div class="space-y-1">
                @foreach($preview['ledger'] as $table => $count)
                    <div class="flex items-center justify-between py-2 border-b border-stone-100">
                        <span class="text-sm text-stone-700">{{ $labels[$table] ?? $table }}</span>
                        <span class="text-sm font-mono font-semibold {{ $count > 0 ? 'text-red-700' : 'text-stone-400' }}">{{ number_format($count) }} deleted</span>
                    </div>
                @endforeach
                <div class="flex items-center justify-between py-2 border-b border-stone-100">
                    <span class="text-sm text-stone-700">Membership Payment Flags</span>
                    <span class="text-sm font-semibold text-amber-700">reset to unpaid</span>
                </div>
                <div class="flex items-center justify-between py-2">
                    <span class="text-sm text-stone-700">Match Registrations</span>
                    <span class="text-sm font-mono font-semibold {{ $preview['total_registrations'] > 0 ? 'text-amber-700' : 'text-stone-400' }}">
                        {{ number_format($preview['total_registrations']) }} reset
                        @if($preview['paid_registrations'] > 0)
                            <span class="text-xs text-red-600 font-normal ml-1">({{ number_format($preview['paid_registrations']) }} paid &rarr; unpaid)</span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-base font-semibold text-stone-900 mb-3">Confirm by typing the phrase</h2>
            <p class="text-sm text-stone-500 mb-4">
                Type <strong class="font-mono text-red-700">CLEAR FINANCE</strong> below to permanently clear all finance data.
            </p>

            <form method="POST" action="{{ route('financials.reset.perform') }}" class="space-y-4"
                  onsubmit="return confirm('Permanently clear ALL finance data? This cannot be undone.');">
                @csrf

                <div>
                    <input type="text" name="confirmation" required autocomplete="off" placeholder="CLEAR FINANCE"
                           class="w-full rounded-lg border-red-300 text-sm py-2.5 focus:ring-red-500 focus:border-red-500 placeholder:text-stone-300">
                    @error('confirmation')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition">
                        Clear All Finance Data
                    </button>
                    <a href="{{ route('financials.dashboard') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
