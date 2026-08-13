<x-layouts.guest>
    <x-slot:title>Set Your Password - SAPRF</x-slot:title>

    <div class="min-h-screen flex items-center justify-center bg-stone-50 px-4 py-12">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-12 w-auto mx-auto">
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6">
                <div>
                    <h1 class="font-heading text-2xl font-bold text-stone-900">Set your password</h1>
                    <p class="mt-2 text-sm text-stone-600">
                        You're using a starter password. Set a personal password to continue.
                    </p>
                </div>

                @if (session('error'))
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {{ session('error') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.force.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <x-password-field name="password" id="password" label="New password" :required="true" :autofocus="true"
                        :min="8" :mixed-case="true" :numbers="true">
                        @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </x-password-field>

                    <x-password-field name="password_confirmation" id="password_confirmation" label="Confirm new password" :required="true" :checklist="false" />

                    <button type="submit"
                            class="w-full inline-flex items-center justify-center rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Set password and continue
                    </button>
                </form>

                <div class="pt-4 border-t border-stone-200">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-stone-500 hover:text-stone-700">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.guest>
