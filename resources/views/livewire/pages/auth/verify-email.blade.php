<?php

use App\Notifications\EmailOtpNotification;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $otp = '';
    public string $status = '';
    public string $error = '';

    public function verifyOtp(): void
    {
        $this->validate(['otp' => ['required', 'string', 'size:6']]);
        $this->error = '';

        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirect(route('dashboard'), navigate: true);
            return;
        }

        if ($user->verifyEmailOtp($this->otp)) {
            $this->redirect(route('dashboard'), navigate: true);
            return;
        }

        $this->error = 'Invalid or expired code. Please try again or request a new one.';
        $this->otp = '';
    }

    public function resendOtp(): void
    {
        $this->error = '';
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirect(route('dashboard'), navigate: true);
            return;
        }

        try {
            $otp = $user->generateEmailOtp();
            $user->notify(new EmailOtpNotification($otp));
            $this->status = 'A new verification code has been sent to your email.';
        } catch (\Throwable $e) {
            logger()->warning('OTP email failed: ' . $e->getMessage());
            $this->status = 'Unable to send verification code. Please try again later.';
        }
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </div>
            </div>

            <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1 text-center">Verify Your Email</h2>
            <p class="text-sm text-stone-500 mb-6 text-center">
                We sent a verification email to <strong class="text-stone-700">{{ Auth::user()->email }}</strong>.
                Tap <strong>Verify Email Address</strong> in that email — it works on any phone or computer.
                Or enter the 6-digit code below.
            </p>

            @if($status)
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700 mb-5">
                    {{ $status }}
                </div>
            @endif

            @if($error)
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 mb-5">
                    {{ $error }}
                </div>
            @endif

            <form wire:submit="verifyOtp" class="space-y-5">
                <div>
                    <label for="otp" class="block text-sm font-medium text-stone-700 mb-1">Verification Code</label>
                    <input wire:model="otp" id="otp" type="text" inputmode="numeric" pattern="[0-9]*"
                        maxlength="6" placeholder="000000" autofocus autocomplete="one-time-code"
                        class="w-full rounded-lg border-stone-300 text-center text-2xl font-mono tracking-[0.5em] py-3 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('otp') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Verify
                </button>
            </form>

            <div class="mt-4 space-y-3">
                <button wire:click="resendOtp" class="w-full rounded-xl bg-stone-100 px-4 py-3 text-sm font-semibold text-stone-600 hover:bg-stone-200 transition">
                    Resend Code
                </button>

                <button wire:click="logout" class="w-full text-sm text-stone-400 hover:text-stone-600 transition py-2">
                    Sign Out
                </button>
            </div>
        </div>

        <p class="text-center mt-6 text-xs text-stone-400">
            Didn't receive the code? Check your spam folder or click resend above.
        </p>
    </div>
</div>
