<x-layouts.guest>
    <x-slot:title>Activate your SAPRF account</x-slot:title>

    <div class="min-h-screen bg-stone-50 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <a href="/" class="inline-flex items-center gap-2 mb-4">
                    <span class="font-heading text-2xl font-bold text-stone-900 tracking-tight">SAPRF</span>
                </a>
                <h1 class="font-heading text-2xl font-bold text-stone-900">Welcome, {{ $junior->name }}</h1>
                <p class="mt-2 text-sm text-stone-500">{{ $junior->parent?->name ?? 'Your parent' }} has handed your shooting account over to you. Set a password to activate it.</p>
            </div>

            <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                @if($errors->any())
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('family.handover.complete', $token) }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">Email Address</label>
                        <input type="email" value="{{ $junior->handover_email }}" disabled
                               class="w-full rounded-lg border-stone-200 bg-stone-50 text-sm py-2.5 px-3 text-stone-600">
                        <p class="mt-1 text-xs text-stone-400">This is the email you'll use to log in.</p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-stone-700 mb-1">Choose a Password <span class="text-red-500">*</span></label>
                        <input type="password" name="password" id="password" required minlength="8" autocomplete="new-password"
                               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">At least 8 characters.</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-stone-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                        <input type="password" name="password_confirmation" id="password_confirmation" required minlength="8" autocomplete="new-password"
                               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-stone-700 mb-1">Phone <span class="text-stone-400 font-normal">(optional)</span></label>
                        <input type="tel" name="phone" id="phone" maxlength="30" value="{{ old('phone', $junior->phone) }}"
                               class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>

                    <button type="submit"
                            class="w-full rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Activate My Account
                    </button>
                </form>

                <div class="mt-5 pt-5 border-t border-stone-100 text-xs text-stone-500 space-y-1">
                    <p class="font-medium text-stone-700">What happens next?</p>
                    <ul class="list-disc pl-4 space-y-0.5">
                        <li>Your existing scores, registrations, and standings stay attached to your account.</li>
                        <li>Your parent will no longer have access to manage this account.</li>
                        <li>You can register for matches and manage your membership directly.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-layouts.guest>
