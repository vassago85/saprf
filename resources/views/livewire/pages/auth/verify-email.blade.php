<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $status = '';

    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirect(route('dashboard'), navigate: true);
            return;
        }

        $user->sendEmailVerificationNotification();
        $this->status = 'A new verification link has been sent to your email address.';
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/', navigate: true);
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
            <div class="flex items-center justify-center mb-5">
                <div class="rounded-full bg-emerald-50 p-4">
                    <svg class="size-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                    </svg>
                </div>
            </div>

            <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1 text-center">Verify Your Email</h2>
            <p class="text-sm text-stone-500 mb-6 text-center">
                We've sent a verification link to <strong class="text-stone-700">{{ Auth::user()->email }}</strong>. Please check your inbox and click the link to activate your account.
            </p>

            @if($status)
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700 mb-5">
                    {{ $status }}
                </div>
            @endif

            <div class="space-y-3">
                <button wire:click="sendVerification" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Resend Verification Email
                </button>

                <button wire:click="logout" class="w-full rounded-xl bg-stone-100 px-4 py-3 text-sm font-semibold text-stone-600 hover:bg-stone-200 transition">
                    Sign Out
                </button>
            </div>
        </div>

        <p class="text-center mt-6 text-xs text-stone-400">
            Didn't receive the email? Check your spam folder or click resend above.
        </p>
    </div>
</div>
