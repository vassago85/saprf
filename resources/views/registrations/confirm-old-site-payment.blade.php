<x-layouts.guest>
    <x-slot:title>Confirm payment on the previous SAPRF site</x-slot:title>

    <div class="min-h-screen bg-stone-50 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg">
            <div class="text-center mb-6">
                <a href="/" class="inline-flex items-center gap-2 mb-4">
                    <span class="font-heading text-2xl font-bold text-stone-900 tracking-tight">SAPRF</span>
                </a>
                <h1 class="font-heading text-2xl font-bold text-stone-900">Confirm your legacy payment</h1>
                <p class="mt-2 text-sm text-stone-500">
                    Hi {{ $registration->user->name ?? $registration->shooter_name }} — you're about to confirm you paid your entry fee for
                    <strong>{{ $registration->match->name }}</strong> via the previous SAPRF site.
                </p>
            </div>

            <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-6">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</dt>
                        <dd class="mt-1 text-sm text-stone-900">{{ $registration->match->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</dt>
                        <dd class="mt-1 text-sm text-stone-900">{{ $registration->match->match_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Entry Fee</dt>
                        <dd class="mt-1 text-sm font-mono text-stone-900">R {{ number_format((float) $registration->fee_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Category</dt>
                        <dd class="mt-1 text-sm text-stone-900">{{ $registration->feeCategoryLabel() }}</dd>
                    </div>
                </dl>

                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-6">
                    <p class="font-semibold">Only click Confirm if you actually paid this entry fee via the previous SAPRF site.</p>
                    <p class="mt-1">
                        If you didn't, or you're not sure, close this page and reply to the email from the match director instead.
                        Falsely confirming payment leaves the match director short and will be reversed.
                    </p>
                </div>

                <form method="POST" action="{{ URL::signedRoute('registrations.confirm-old-site-payment.apply', ['registration' => $registration->id], now()->addDays(30)) }}" class="flex flex-col gap-3 sm:flex-row-reverse sm:justify-start">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition">
                        Yes, I paid on the old site — confirm
                    </button>
                    <a href="{{ route('events.show', $registration->match) }}"
                       class="inline-flex items-center justify-center rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50 transition">
                        Cancel
                    </a>
                </form>
            </div>

            <p class="mt-4 text-center text-xs text-stone-400">
                This link is unique to your registration and expires 30 days after the inquiry email was sent.
            </p>
        </div>
    </div>
</x-layouts.guest>
