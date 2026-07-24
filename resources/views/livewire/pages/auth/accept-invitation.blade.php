<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $token = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $valid = false;
    public string $memberName = '';
    public string $memberEmail = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $user = $this->resolveInvitee();

        if ($user) {
            $this->valid = true;
            $this->memberName = $user->name;
            $this->memberEmail = $user->email;
        }
    }

    private function resolveInvitee(): ?User
    {
        return User::query()
            ->where('invitation_token', hash('sha256', $this->token))
            ->whereNull('invitation_accepted_at')
            ->where('invitation_expires_at', '>', now())
            ->first();
    }

    public function activate(): void
    {
        $user = $this->resolveInvitee();

        if (! $user) {
            $this->valid = false;

            return;
        }

        $this->validate([
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($this->password),
            'email_verified_at' => now(),
            'must_change_password' => false,
            'is_active' => true,
            'email_otp' => null,
            'email_otp_expires_at' => null,
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'invitation_accepted_at' => now(),
        ])->save();

        event(new \Illuminate\Auth\Events\Verified($user));

        Auth::login($user);
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
            @if ($valid)
                <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1">Welcome to SAPRF</h2>
                <p class="text-sm text-stone-500 mb-6">
                    Hi {{ $memberName }}, set a password to activate your account for
                    <span class="font-medium text-stone-700">{{ $memberEmail }}</span>.
                </p>

                <form wire:submit="activate" class="space-y-5">
                    <div>
                        <label for="password" class="block text-sm font-medium text-stone-700 mb-1">Password</label>
                        <input wire:model="password" id="password" type="password" required autofocus autocomplete="new-password"
                            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-stone-700 mb-1">Confirm Password</label>
                        <input wire:model="password_confirmation" id="password_confirmation" type="password" required autocomplete="new-password"
                            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                    </div>

                    <button type="submit" class="w-full rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Activate Account
                    </button>
                </form>
            @else
                <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1">Invitation Unavailable</h2>
                <p class="text-sm text-stone-500 mb-6">
                    This invitation link is invalid, has expired, or has already been used.
                    If you have already activated your account, please sign in. Otherwise, ask an
                    administrator to send you a new invitation.
                </p>
                <a href="{{ route('login') }}" class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Go to Sign In
                </a>
            @endif
        </div>

        @if ($valid)
            <p class="text-center mt-6 text-sm text-stone-500">
                Already have a password?
                <a href="{{ route('login') }}" class="text-emerald-700 font-medium hover:text-emerald-800">Sign in</a>
            </p>
        @endif
    </div>
</div>
