<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Password;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $email = '';
    public string $status = '';

    public function sendResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $result = Password::sendResetLink(['email' => $this->email]);

            if ($result === Password::RESET_LINK_SENT) {
                $this->status = __($result);
                $this->email = '';
            } else {
                $this->addError('email', __($result));
            }
        } catch (\Throwable $e) {
            logger()->warning('Password reset email failed: ' . $e->getMessage());
            $this->addError('email', 'Unable to send reset email. Please try again later.');
        }
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
            <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1">Forgot Password</h2>
            <p class="text-sm text-stone-500 mb-6">Enter your email and we'll send you a reset link.</p>

            @if($status)
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700 mb-5">
                    {{ $status }}
                </div>
            @endif

            <form wire:submit="sendResetLink" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700 mb-1">Email</label>
                    <input wire:model="email" id="email" type="email" required autofocus
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Send Reset Link
                </button>
            </form>
        </div>

        <p class="text-center mt-6 text-sm text-stone-500">
            Remember your password?
            <a href="{{ route('login') }}" class="text-emerald-700 font-medium hover:text-emerald-800">Sign in</a>
        </p>
    </div>
</div>
