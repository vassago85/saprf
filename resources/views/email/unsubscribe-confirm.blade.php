<x-layouts.guest>
    <x-slot:title>Unsubscribe - SAPRF</x-slot:title>

    <div class="mx-auto max-w-lg px-6 py-16">
        <div class="rounded-2xl border border-stone-200 bg-white p-8 shadow-sm">
            <h1 class="font-heading text-2xl font-bold text-stone-900">Unsubscribe from SAPRF email</h1>

            <p class="mt-4 text-sm text-stone-600">
                Hi {{ $user->name }} — confirm and we will stop the matching SAPRF emails.
                Policy changes and urgent notices still send.
            </p>

            <form method="POST" action="{{ $action }}" class="mt-6">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Confirm unsubscribe
                </button>
            </form>

            <a href="{{ url('/') }}"
                class="mt-4 inline-block text-sm font-medium text-stone-500 hover:text-stone-800">
                Keep my emails
            </a>
        </div>
    </div>
</x-layouts.guest>
