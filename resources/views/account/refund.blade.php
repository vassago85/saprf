<x-layouts.app :title="'Request a refund'">
    <div class="max-w-2xl space-y-6">
        <div>
            <a href="{{ route('dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Dashboard</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 mt-2">Request a refund</h1>
            <p class="mt-1 text-sm text-stone-500">The match was cancelled, so you can take the entry fee back in cash instead of keeping it as credit. There is no admin fee.</p>
        </div>

        @unless($enabled)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Cash refunds are not open yet. Your entry credit stays on your account and is applied the next time you pay for a match.
            </div>
        @endunless

        @if($pending)
            <div class="rounded-xl border border-emerald-200 bg-white shadow-sm p-6 space-y-4">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Refund in progress</h2>
                <p class="text-sm text-stone-600">R {{ number_format((float) $pending->amount, 2) }} is held until SAPRF pays it to the account below. It will not be used for another event.</p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-stone-500">Amount</dt>
                        <dd class="font-semibold text-stone-900">R {{ number_format((float) $pending->amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Requested</dt>
                        <dd class="font-semibold text-stone-900">{{ $pending->created_at->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Account holder</dt>
                        <dd class="font-semibold text-stone-900">{{ $pending->account_holder }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Bank</dt>
                        <dd class="font-semibold text-stone-900">{{ $pending->bank_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Account number</dt>
                        <dd class="font-semibold text-stone-900 font-mono">{{ $pending->account_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Branch code</dt>
                        <dd class="font-semibold text-stone-900 font-mono">{{ $pending->branch_code }}</dd>
                    </div>
                </dl>
            </div>
        @elseif($enabled && $summary['available'] > 0)
            @if($latest && $latest->status === 'declined')
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    The last request was declined. Your credit is available again.
                    @if($latest->decline_reason)
                        <span class="block mt-1">Reason: {{ $latest->decline_reason }}</span>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('account.refund.store') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-5">
                @csrf

                @if($summary['entries']->isNotEmpty())
                    <div>
                        <p class="text-sm font-medium text-stone-700">Credit on your account</p>
                        <ul class="mt-2 space-y-1 text-sm text-stone-600">
                            @foreach($summary['entries'] as $entry)
                                <li>{{ $entry->description }} — R {{ number_format((float) $entry->amount, 2) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <label for="amount" class="block text-sm font-medium text-stone-700">Amount to refund</label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" max="{{ $summary['available'] }}"
                           value="{{ old('amount', number_format($summary['available'], 2, '.', '')) }}" required
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Up to R {{ number_format($summary['available'], 2) }}. Anything you leave stays as credit for another event.</p>
                    @error('amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="account_holder" class="block text-sm font-medium text-stone-700">Account holder</label>
                    <input type="text" name="account_holder" id="account_holder" value="{{ old('account_holder', auth()->user()->name) }}" required maxlength="255"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('account_holder')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="bank_name" class="block text-sm font-medium text-stone-700">Bank</label>
                    <input type="text" name="bank_name" id="bank_name" value="{{ old('bank_name') }}" required maxlength="100"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('bank_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="account_number" class="block text-sm font-medium text-stone-700">Account number</label>
                        <input type="text" name="account_number" id="account_number" value="{{ old('account_number') }}" required inputmode="numeric"
                               class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @error('account_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="branch_code" class="block text-sm font-medium text-stone-700">Branch code</label>
                        <input type="text" name="branch_code" id="branch_code" value="{{ old('branch_code') }}" required inputmode="numeric"
                               class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @error('branch_code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label for="member_note" class="block text-sm font-medium text-stone-700">Note <span class="font-normal text-stone-400">(optional)</span></label>
                    <textarea name="member_note" id="member_note" rows="3" maxlength="500"
                              class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">{{ old('member_note') }}</textarea>
                </div>

                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Request refund
                </button>
            </form>
        @elseif($enabled)
            <div class="rounded-xl border border-stone-200 bg-white p-6 text-sm text-stone-600">
                You do not have entry credit available to refund.
            </div>
        @endif
    </div>
</x-layouts.app>
