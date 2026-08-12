<x-layouts.app>
    <x-slot:title>Edit Profile - SAPRF</x-slot:title>

    <div class="max-w-2xl space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">Edit Profile</h1>
            <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst(str_replace('_', ' ', $user->getRoleNames()->first() ?? 'member')) }}</span>
        </div>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if(session('profile_incomplete'))
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                <div class="flex items-start gap-3">
                    <svg class="size-5 text-amber-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    <div>
                        <h3 class="text-sm font-semibold text-amber-900">Complete Your Profile</h3>
                        <p class="text-sm text-amber-800 mt-1">As a paid SAPRF member, you are required to complete your <strong>SA ID Number</strong>, <strong>Date of Birth</strong>, and <strong>Province</strong> for SASCOC reporting. Please fill in the missing fields below.</p>
                    </div>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('profile.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Personal Information</h2>

                <div>
                    <label for="name" class="block text-sm font-medium text-stone-700">Full Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email Address</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-stone-700">Phone Number</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone', $user->phone) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="sa_id_number" class="block text-sm font-medium text-stone-700">SA ID Number @if(session('profile_incomplete') && empty($user->sa_id_number))<span class="text-red-600">*</span>@endif</label>
                    <input type="text" name="sa_id_number" id="sa_id_number" value="{{ old('sa_id_number', $user->sa_id_number) }}" maxlength="13" pattern="\d{13}" placeholder="13-digit SA ID number" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 @if(session('profile_incomplete') && empty($user->sa_id_number)) !border-amber-400 !ring-1 !ring-amber-400 @endif">
                    <p class="mt-1 text-xs text-stone-400">Required for SASCOC reporting. 13 digits only.</p>
                </div>

                <div>
                    <label for="date_of_birth" class="block text-sm font-medium text-stone-700">Date of Birth @if(session('profile_incomplete') && empty($user->date_of_birth))<span class="text-red-600">*</span>@endif</label>
                    <input type="date" name="date_of_birth" id="date_of_birth" value="{{ old('date_of_birth', $user->date_of_birth?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 @if(session('profile_incomplete') && empty($user->date_of_birth)) !border-amber-400 !ring-1 !ring-amber-400 @endif">
                    <p class="mt-1 text-xs text-stone-400">Used for SASCOC reporting and eligibility checks.</p>
                </div>

                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700">Province @if(session('profile_incomplete') && empty($user->province_id))<span class="text-red-600">*</span>@endif</label>
                    <select name="province_id" id="province_id" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 @if(session('profile_incomplete') && empty($user->province_id)) !border-amber-400 !ring-1 !ring-amber-400 @endif">
                        <option value="">— Select province —</option>
                        @foreach($provinces as $province)
                            <option value="{{ $province->id }}" @selected(old('province_id', $user->province_id) == $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Membership &amp; Selection Eligibility</h2>
                    <p class="mt-1 text-sm text-stone-500">Used by the IPRF team selection process (citizenship, residence and club affiliation).</p>
                </div>

                <div>
                    <label for="club_id" class="block text-sm font-medium text-stone-700">Primary Club</label>
                    <select name="club_id" id="club_id" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Not affiliated to a club —</option>
                        @foreach($clubs as $provinceName => $provinceClubs)
                            <optgroup label="{{ $provinceName }}">
                                @foreach($provinceClubs as $club)
                                    <option value="{{ $club->id }}" @selected(old('club_id', $user->club_id) == $club->id)>
                                        {{ $club->name }}@unless($club->saprf_recognised) (not SAPRF-recognised)@endunless
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-stone-400">Selection eligibility (ELG-03) requires either provincial residency <em>or</em> membership of a SAPRF-recognised club.</p>
                </div>

                <div>
                    <span class="block text-sm font-medium text-stone-700">South African Citizen</span>
                    <div class="mt-2 flex items-center gap-6 text-sm text-stone-700">
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="sa_citizen" value="1" @checked(old('sa_citizen', $user->sa_citizen === true ? '1' : ($user->sa_citizen === false ? '0' : '')) === '1') class="text-emerald-700 focus:ring-emerald-500">
                            <span>Yes</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="sa_citizen" value="0" @checked(old('sa_citizen', $user->sa_citizen === true ? '1' : ($user->sa_citizen === false ? '0' : '')) === '0') class="text-emerald-700 focus:ring-emerald-500">
                            <span>No</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="sa_citizen" value="" @checked(old('sa_citizen', $user->sa_citizen === true ? '1' : ($user->sa_citizen === false ? '0' : '')) === '') class="text-emerald-700 focus:ring-emerald-500">
                            <span class="text-stone-500">Prefer not to say</span>
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-stone-400">Required by IPRF (ELG-02) to represent South Africa.</p>
                </div>

                <div>
                    <label for="country_of_residence" class="block text-sm font-medium text-stone-700">Country of Residence</label>
                    <select name="country_of_residence" id="country_of_residence" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Not specified —</option>
                        @foreach($countries as $code => $label)
                            <option value="{{ $code }}" @selected(old('country_of_residence', $user->country_of_residence) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-stone-400">If you live outside South Africa, ELG-04 requires that you shot the mandatory SA Championship match in the qualifying year.</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Change Password</h2>
                    <p class="mt-1 text-sm text-stone-500">Leave blank if you don't want to change your password.</p>
                </div>

                <div>
                    <label for="current_password" class="block text-sm font-medium text-stone-700">Current Password</label>
                    <input type="password" name="current_password" id="current_password" autocomplete="current-password" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-stone-700">New Password</label>
                    <input type="password" name="new_password" id="new_password" autocomplete="new-password" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Minimum 8 characters, with letters and numbers.</p>
                </div>

                <div>
                    <label for="new_password_confirmation" class="block text-sm font-medium text-stone-700">Confirm New Password</label>
                    <input type="password" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Changes</button>
                <a href="{{ route('dashboard') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
