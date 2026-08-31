<x-layouts.guest>
    <x-slot:title>Payment confirmed</x-slot:title>

    <div class="min-h-screen bg-stone-50 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg text-center">
            <a href="/" class="inline-flex items-center gap-2 mb-4">
                <span class="font-heading text-2xl font-bold text-stone-900 tracking-tight">SAPRF</span>
            </a>

            <div class="rounded-2xl border border-emerald-200 bg-white p-8 shadow-sm">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-7 w-7 text-emerald-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>

                <h1 class="mt-4 font-heading text-xl font-bold text-stone-900">Thanks — your entry is settled.</h1>
                <p class="mt-2 text-sm text-stone-600">
                    Your registration for <strong>{{ $registration->match->name }}</strong> has been marked as paid via the previous SAPRF site.
                    Nothing more to do.
                </p>

                <a href="{{ route('events.show', $registration->match) }}"
                   class="mt-6 inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 transition">
                    View match results
                </a>
            </div>
        </div>
    </div>
</x-layouts.guest>
