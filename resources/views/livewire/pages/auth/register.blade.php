<?php

use App\Models\Province;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

new #[Layout('components.layouts.guest')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $id_type = 'sa_id';
    public string $sa_id_number = '';
    public string $passport_number = '';
    public string $date_of_birth = '';
    public ?int $province_id = null;
    public string $gender = '';
    public string $ethnicity = '';
    /** Tri-state '' | 'yes' | 'no' — converted to bool|null at save time. */
    public string $previously_disadvantaged_choice = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $terms_accepted = false;

    /**
     * Suggest a default for previously_disadvantaged_choice from ethnicity so
     * the common SASCOC case (Black African / Coloured / Indian) doesn't
     * require an extra click. Leaves the flag alone once the user has
     * explicitly picked a value.
     */
    public function updatedEthnicity(string $value): void
    {
        if ($this->previously_disadvantaged_choice !== '') {
            return;
        }
        if ($value === '') {
            return;
        }
        $this->previously_disadvantaged_choice = in_array($value, User::PREVIOUSLY_DISADVANTAGED_ETHNICITIES, true)
            ? 'yes'
            : 'no';
    }

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'id_type' => ['required', Rule::in(['sa_id', 'passport'])],
            'sa_id_number' => ['nullable', 'required_if:id_type,sa_id', 'digits:13', 'unique:users,sa_id_number'],
            'passport_number' => ['nullable', 'required_if:id_type,passport', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'province_id' => ['required', 'exists:provinces,id'],
            'gender' => ['nullable', Rule::in(array_keys(User::GENDER_OPTIONS))],
            'ethnicity' => ['nullable', Rule::in(array_keys(User::ETHNICITY_OPTIONS))],
            'previously_disadvantaged_choice' => ['nullable', Rule::in(['', 'yes', 'no'])],
            'password' => ['required', 'min:8', 'confirmed'],
            'terms_accepted' => ['accepted'],
        ], [
            'terms_accepted.accepted' => 'You must accept the Terms & Conditions to create an account.',
        ]);

        $previouslyDisadvantaged = match ($validated['previously_disadvantaged_choice'] ?? '') {
            'yes' => true,
            'no' => false,
            default => null,
        };

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'sa_id_number' => $validated['id_type'] === 'sa_id' ? $validated['sa_id_number'] : null,
            'passport_number' => $validated['id_type'] === 'passport' ? $validated['passport_number'] : null,
            'sa_citizen' => $validated['id_type'] === 'sa_id' ? true : null,
            'country_of_residence' => 'ZA',
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'province_id' => $validated['province_id'],
            'gender' => $validated['gender'] ?? null,
            'ethnicity' => $validated['ethnicity'] ?? null,
            'previously_disadvantaged' => $previouslyDisadvantaged,
            'password' => Hash::make($validated['password']),
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

    public function with(): array
    {
        return [
            'provinces' => Province::orderBy('name')->get(['id', 'name']),
            'genderOptions' => User::GENDER_OPTIONS,
            'ethnicityOptions' => User::ETHNICITY_OPTIONS,
        ];
    }
}

?>

<div class="flex min-h-screen items-center justify-center px-4 py-10 bg-stone-50">
    <div class="w-full max-w-xl">
        <div class="text-center mb-8">
            <a href="/" class="inline-block">
                <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-12 w-auto mx-auto">
            </a>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-8">
            <h2 class="font-heading text-2xl font-bold text-stone-900 mb-1">Create Account</h2>
            <p class="text-sm text-stone-500 mb-6">Join SAPRF. Fields marked <span class="text-red-600">*</span> are required.</p>

            <form wire:submit="register" class="space-y-6">
                {{-- Section: contact --}}
                <fieldset class="space-y-4">
                    <legend class="text-sm font-semibold uppercase tracking-wide text-stone-500">Contact</legend>

                    <div>
                        <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Full Name <span class="text-red-600">*</span></label>
                        <input wire:model="name" id="name" type="text" required autofocus
                            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="email" class="block text-sm font-medium text-stone-700 mb-1">Email <span class="text-red-600">*</span></label>
                            <input wire:model="email" id="email" type="email" required
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="phone" class="block text-sm font-medium text-stone-700 mb-1">Phone</label>
                            <input wire:model="phone" id="phone" type="tel"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                            @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                {{-- Section: identity --}}
                <fieldset class="space-y-4 border-t border-stone-200 pt-6">
                    <legend class="text-sm font-semibold uppercase tracking-wide text-stone-500">Identity &amp; residence</legend>

                    <div>
                        <span class="block text-sm font-medium text-stone-700 mb-2">Identification <span class="text-red-600">*</span></span>
                        <div class="flex items-center gap-6 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input wire:model.live="id_type" type="radio" value="sa_id" class="text-emerald-700 focus:ring-emerald-500">
                                <span>SA ID number</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input wire:model.live="id_type" type="radio" value="passport" class="text-emerald-700 focus:ring-emerald-500">
                                <span>Passport (non-SA citizen)</span>
                            </label>
                        </div>

                        @if ($id_type === 'sa_id')
                            <div class="mt-3">
                                <input wire:model="sa_id_number" type="text" inputmode="numeric" maxlength="13" pattern="\d{13}" placeholder="13-digit SA ID number"
                                    class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                                <p class="mt-1 text-xs text-stone-400">Used for SASCOC reporting and IPRF eligibility (ELG-02).</p>
                                @error('sa_id_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @else
                            <div class="mt-3">
                                <input wire:model="passport_number" type="text" placeholder="Passport number"
                                    class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                                @error('passport_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="date_of_birth" class="block text-sm font-medium text-stone-700 mb-1">Date of Birth</label>
                            <input wire:model="date_of_birth" id="date_of_birth" type="date"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                            @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Province you live in <span class="text-red-600">*</span></label>
                            <select wire:model="province_id" id="province_id" required
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— Select —</option>
                                @foreach ($provinces as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                            @error('province_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                {{-- Section: SASCOC demographic (optional but strongly encouraged) --}}
                <fieldset class="space-y-4 border-t border-stone-200 pt-6">
                    <legend class="text-sm font-semibold uppercase tracking-wide text-stone-500">SASCOC reporting</legend>
                    <p class="text-xs text-stone-500 -mt-2">SASCOC (South African Sports Confederation and Olympic Committee) requires this demographic data. All fields are optional but help SAPRF secure Protea Colours motivations for you.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="gender" class="block text-sm font-medium text-stone-700 mb-1">Gender</label>
                            <select wire:model="gender" id="gender"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— Select —</option>
                                @foreach ($genderOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="ethnicity" class="block text-sm font-medium text-stone-700 mb-1">Ethnicity</label>
                            <select wire:model.live="ethnicity" id="ethnicity"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— Select —</option>
                                @foreach ($ethnicityOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="previously_disadvantaged_choice" class="block text-sm font-medium text-stone-700 mb-1">Previously disadvantaged</label>
                        <select wire:model="previously_disadvantaged_choice" id="previously_disadvantaged_choice"
                            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">Prefer not to say</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </div>
                </fieldset>

                {{-- Section: security --}}
                <fieldset class="space-y-4 border-t border-stone-200 pt-6">
                    <legend class="text-sm font-semibold uppercase tracking-wide text-stone-500">Password</legend>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="password" class="block text-sm font-medium text-stone-700 mb-1">Password <span class="text-red-600">*</span></label>
                            <input wire:model="password" id="password" type="password" required autocomplete="new-password"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-stone-700 mb-1">Confirm Password <span class="text-red-600">*</span></label>
                            <input wire:model="password_confirmation" id="password_confirmation" type="password" required autocomplete="new-password"
                                class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500" />
                        </div>
                    </div>
                </fieldset>

                {{-- Section: terms acceptance --}}
                <fieldset class="border-t border-stone-200 pt-6">
                    <label class="flex items-start gap-3 text-sm text-stone-700">
                        <input wire:model="terms_accepted" type="checkbox" id="terms_accepted" required
                            class="mt-0.5 h-4 w-4 rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                        <span>
                            I have read and accept the
                            <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener" class="text-emerald-700 font-medium hover:text-emerald-800 underline">Terms &amp; Conditions</a>
                            and
                            <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener" class="text-emerald-700 font-medium hover:text-emerald-800 underline">Privacy Policy</a>.
                            <span class="text-red-600">*</span>
                        </span>
                    </label>
                    @error('terms_accepted') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                </fieldset>

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
