<x-layouts.app :title="'Credit refunds - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Dashboard</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Credit refunds</h1>
            <p class="mt-1 text-sm text-stone-500">Cash refunds of entry credit from a cancelled match. The cancellation was not the shooter's fault, so the amount they asked for is paid in full.</p>
        </div>

        @unless($enabled)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Members cannot request a refund yet. This stays off until finance is ready to pay them out.
                @if(auth()->user()->hasAnyRole(['developer', 'exco', 'owner']))
                    <a href="{{ route('site-settings.index') }}" class="font-semibold underline">Turn it on in Site Settings.</a>
                @else
                    An owner can turn it on in Site Settings.
                @endif
            </div>
        @endunless

        <div class="flex flex-wrap gap-2 text-sm">
            @foreach(['pending' => 'Pending', 'paid' => 'Paid', 'declined' => 'Declined', 'all' => 'All'] as $key => $label)
                <a href="{{ route('financials.credit-refunds.index', ['status' => $key]) }}"
                   class="rounded-lg px-3 py-1.5 font-medium {{ $status === $key ? 'bg-emerald-700 text-white' : 'bg-stone-100 text-stone-700 hover:bg-stone-200' }}">
                    {{ $label }}@if($key === 'pending' && $pendingCount > 0) ({{ $pendingCount }})@endif
                </a>
            @endforeach
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            @if($refunds->isEmpty())
                <p class="p-6 text-sm text-stone-500">No refund requests in this list.</p>
            @else
                <div class="divide-y divide-stone-100">
                    @foreach($refunds as $refund)
                        <div class="p-5 space-y-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-stone-900">{{ $refund->user?->name ?? 'Member' }}</p>
                                    <p class="text-sm text-stone-500">{{ $refund->user?->email }} · {{ $refund->created_at->format('d M Y H:i') }}</p>
                                </div>
                                <p class="text-xl font-bold text-stone-900 tabular-nums">R {{ number_format((float) $refund->amount, 2) }}</p>
                            </div>

                            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                                <div>
                                    <dt class="text-stone-500">Account holder</dt>
                                    <dd class="font-medium text-stone-900">{{ $refund->account_holder }}</dd>
                                </div>
                                <div>
                                    <dt class="text-stone-500">Bank</dt>
                                    <dd class="font-medium text-stone-900">{{ $refund->bank_name }}</dd>
                                </div>
                                <div>
                                    <dt class="text-stone-500">Account number</dt>
                                    <dd class="font-medium text-stone-900 font-mono">{{ $refund->account_number }}</dd>
                                </div>
                                <div>
                                    <dt class="text-stone-500">Branch code</dt>
                                    <dd class="font-medium text-stone-900 font-mono">{{ $refund->branch_code }}</dd>
                                </div>
                            </dl>

                            @if($refund->member_note)
                                <p class="text-sm text-stone-600">Note: {{ $refund->member_note }}</p>
                            @endif

                            @if($refund->status === 'paid')
                                <p class="text-sm text-emerald-800">Paid {{ $refund->paid_at?->format('d M Y') }} · {{ $refund->payment_reference }}</p>
                            @elseif($refund->status === 'declined')
                                <p class="text-sm text-amber-800">Declined: {{ $refund->decline_reason }}</p>
                            @else
                                <div class="flex flex-col lg:flex-row gap-3">
                                    <form method="POST" action="{{ route('financials.credit-refunds.pay', $refund) }}" class="flex flex-1 flex-wrap items-end gap-2">
                                        @csrf
                                        <div class="flex-1 min-w-[12rem]">
                                            <label class="block text-xs font-medium text-stone-500">Payment reference</label>
                                            <input type="text" name="payment_reference" required maxlength="100" placeholder="EFT reference"
                                                   class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                        </div>
                                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Mark paid</button>
                                    </form>
                                    <form method="POST" action="{{ route('financials.credit-refunds.decline', $refund) }}" class="flex flex-1 flex-wrap items-end gap-2">
                                        @csrf
                                        <div class="flex-1 min-w-[12rem]">
                                            <label class="block text-xs font-medium text-stone-500">Reason if declining</label>
                                            <input type="text" name="decline_reason" required maxlength="500"
                                                   class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                        </div>
                                        <button type="submit" class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-200">Decline</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="px-5 py-4 border-t border-stone-100">{{ $refunds->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
