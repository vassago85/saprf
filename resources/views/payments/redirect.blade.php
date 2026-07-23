<x-layouts.guest>
    <x-slot:title>Processing Payment - SAPRF</x-slot:title>

    <x-public-nav />

    <div class="bg-stone-50 min-h-screen flex items-center justify-center">
        <div class="max-w-md mx-auto px-4 py-16 text-center">
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-8 space-y-6">
                <div class="mx-auto size-12 rounded-full bg-emerald-100 flex items-center justify-center">
                    <svg class="size-6 text-emerald-600 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <div>
                    <h1 class="font-heading text-xl font-bold text-stone-900">Redirecting to PayFast</h1>
                    <p class="mt-2 text-sm text-stone-500">You are being redirected to PayFast to complete your payment of <strong class="text-stone-900">R {{ number_format($payment->amount, 2) }}</strong>.</p>
                </div>

                <p class="text-xs text-stone-400">If you are not redirected automatically, click the button below.</p>

                <form id="payfast-form" method="POST" action="{{ $actionUrl }}">
                    {{-- Only non-blank fields are included (see PayFastService) so the
                         posted payload matches the signed parameter string exactly. --}}
                    @foreach($formData as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-700 text-white font-semibold hover:bg-emerald-800 transition shadow-sm">
                        Pay Now
                    </button>
                </form>
            </div>

            <p class="mt-4 text-xs text-stone-400">Secured by PayFast. Your card details are never stored on our servers.</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () {
                document.getElementById('payfast-form').submit();
            }, 1500);
        });
    </script>
</x-layouts.guest>
