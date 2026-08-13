<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->addError('email', 'These credentials do not match our records.');
            return;
        }

        session()->regenerate();
        $this->redirect(route('dashboard'), navigate: true);
    }
}

?>

<div class="flex min-h-screen items-center justify-center px-4 bg-stone-50">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="/" class="inline-block">
                <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-12 w-auto mx-auto">
            </a>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-8">
            <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1">Sign In</h2>
            <p class="text-sm text-stone-500 mb-6">Enter your credentials to access the platform.</p>

            @if(session('status'))
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700 mb-5">
                    {{ session('status') }}
                </div>
            @endif

            <form wire:submit="login" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700 mb-1">Email</label>
                    <input wire:model="email" id="email" type="email" required autofocus
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <x-password-field wire:model="password" id="password" label="Password" :required="true"
                    autocomplete="current-password" :checklist="false">
                    @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </x-password-field>

                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input wire:model="remember" id="remember" type="checkbox" class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <label for="remember" class="ml-2 text-sm text-stone-600">Remember me</label>
                    </div>
                    <a href="{{ route('password.request') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">Forgot password?</a>
                </div>

                <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Sign In
                </button>
            </form>
        </div>

        <p class="text-center mt-6 text-sm text-stone-500">
            Don't have an account?
            <a href="{{ route('register') }}" class="text-emerald-700 font-medium hover:text-emerald-800">Register</a>
        </p>
    </div>
</div>
