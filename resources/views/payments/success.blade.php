<x-layouts.guest>
    <x-slot:title>Payment Successful - SAPRF</x-slot:title>

    <x-public-nav />

    <div class="bg-stone-50 min-h-screen flex items-center justify-center">
        <div class="max-w-md mx-auto px-4 py-16 text-center">
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-8 space-y-6">
                <div class="mx-auto size-14 rounded-full bg-emerald-100 flex items-center justify-center">
                    <svg class="size-7 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </div>

                <div>
                    <h1 class="font-heading text-2xl font-bold text-stone-900">Payment Received</h1>
                    <p class="mt-2 text-sm text-stone-500">Your payment has been submitted to PayFast for processing. You will receive confirmation once it is finalised.</p>
                </div>

                @if($payment)
                    <div class="rounded-xl border border-stone-200 p-4 text-left space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Reference</span>
                            <span class="font-mono text-stone-900">{{ $payment->m_payment_id }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Amount</span>
                            <span class="font-semibold text-stone-900">R {{ number_format($payment->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-stone-500">Status</span>
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                {{ $payment->isCompleted() ? 'Paid' : 'Processing' }}
                            </span>
                        </div>
                    </div>
                @endif

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    @if($payment && $payment->payable_type === 'App\\Models\\MatchRegistration')
                        <a href="{{ route('registrations.show', $payment->payable_id) }}" class="px-5 py-2.5 rounded-xl bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 transition">View Registration</a>
                    @endif
                    <a href="{{ route('dashboard') }}" class="px-5 py-2.5 rounded-xl bg-stone-100 text-stone-700 text-sm font-semibold hover:bg-stone-200 transition">Go to Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <x-public-footer />
</x-layouts.guest>
