<?php

use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function register(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $user->assignRole('member');

        Auth::login($user);
        session()->regenerate();

        try {
            $otp = $user->generateEmailOtp();
            $user->notify(new EmailOtpNotification($otp));
        } catch (\Throwable $e) {
            logger()->warning('OTP email failed at registration: ' . $e->getMessage());
        }

        $this->redirect(route('verification.notice'), navigate: true);
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
            <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1">Create Account</h2>
            <p class="text-sm text-stone-500 mb-6">Join SAPRF and start competing.</p>

            <form wire:submit="register" class="space-y-5">
                <div>
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Full Name</label>
                    <input wire:model="name" id="name" type="text" required autofocus
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700 mb-1">Email</label>
                    <input wire:model="email" id="email" type="email" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-stone-700 mb-1">Password</label>
                    <input wire:model="password" id="password" type="password" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-stone-700 mb-1">Confirm Password</label>
                    <input wire:model="password_confirmation" id="password_confirmation" type="password" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Create Account
                </button>
            </form>
        </div>

        <p class="text-center mt-6 text-sm text-stone-500">
            Already have an account?
            <a href="{{ route('login') }}" class="text-emerald-700 font-medium hover:text-emerald-800">Sign in</a>
        </p>
    </div>
</div>
