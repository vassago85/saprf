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
                        <p class="text-sm text-amber-800 mt-1">As a paid SAPRF member, we require your <strong>SA ID / Passport Number</strong>, <strong>Date of Birth</strong>, <strong>Province</strong>, <strong>Primary Club</strong>, <strong>Gender</strong>, <strong>Ethnicity</strong>, <strong>Previously Disadvantaged</strong> status, <strong>Citizenship</strong>, and <strong>Country of Residence</strong> for SASCOC reporting and IPRF selection. Please fill in the missing fields below.</p>
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
                    <label for="name" class="block text-sm font-medium text-stone-700">Full Name <span class="text-red-600">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email Address <span class="text-red-600">*</span></label>
                    <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-stone-700">Phone Number</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone', $user->phone) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="sa_id_number" class="block text-sm font-medium text-stone-700">SA ID Number <span class="text-red-600">*</span></label>
                    <input type="text" name="sa_id_number" id="sa_id_number" value="{{ old('sa_id_number', $user->sa_id_number) }}" maxlength="13" pattern="\d{13}" placeholder="13-digit SA ID number" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 @if(session('profile_incomplete') && empty($user->sa_id_number) && empty($user->passport_number)) !border-amber-400 !ring-1 !ring-amber-400 @endif">
                    <p class="mt-1 text-xs text-stone-400">Required for SASCOC reporting. 13 digits only. Not a South African citizen? Leave this blank and complete <strong>Passport Number</strong> below instead.</p>
                </div>

                <div>
                    <label for="passport_number" class="block text-sm font-medium text-stone-700">Passport Number</label>
                    <input type="text" name="passport_number" id="passport_number" value="{{ old('passport_number', $user->passport_number) }}" maxlength="50" placeholder="Non-SA citizens only" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Only capture this if you don't have a South African ID.</p>
                </div>

                <div>
                    <label for="mil_le_number" class="block text-sm font-medium text-stone-700">Mil / LE Number</label>
                    <input type="text" name="mil_le_number" id="mil_le_number" value="{{ old('mil_le_number', $user->mil_le_number) }}" maxlength="50" placeholder="Optional — military or law-enforcement service number" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="date_of_birth" class="block text-sm font-medium text-stone-700">Date of Birth <span class="text-red-600">*</span></label>
                    <input type="date" name="date_of_birth" id="date_of_birth" required value="{{ old('date_of_birth', $user->date_of_birth?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 @if(session('profile_incomplete') && empty($user->date_of_birth)) !border-amber-400 !ring-1 !ring-amber-400 @endif">
                    <p class="mt-1 text-xs text-stone-400">Used for SASCOC reporting and eligibility checks.</p>
                </div>

                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700">Province <span class="text-red-600">*</span></label>
                    <select name="province_id" id="province_id" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 @if(session('profile_incomplete') && empty($user->province_id)) !border-amber-400 !ring-1 !ring-amber-400 @endif">
                        <option value="" disabled @selected(empty(old('province_id', $user->province_id)))>— Select province —</option>
                        @foreach($provinces as $province)
                            <option value="{{ $province->id }}" @selected(old('province_id', $user->province_id) == $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="border-t border-stone-200 pt-5">
                    <h3 class="font-heading text-base font-semibold text-stone-900">SASCOC Demographic Reporting</h3>
                    <p class="mt-1 text-sm text-stone-500">Required. SAPRF submits every paid-up member's demographic details to SASCOC (South African Sports Confederation and Olympic Committee), which uses them for Protea Colours motivations.</p>
                </div>

                <div>
                    <label for="gender" class="block text-sm font-medium text-stone-700">Gender <span class="text-red-600">*</span></label>
                    @php($genderCurrent = old('gender', $user->gender))
                    <select name="gender" id="gender" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" disabled @selected(empty($genderCurrent))>— Select —</option>
                        @foreach($genderOptions as $value => $label)
                            <option value="{{ $value }}" @selected($genderCurrent === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="ethnicity" class="block text-sm font-medium text-stone-700">Ethnicity <span class="text-red-600">*</span></label>
                    @php($ethnicityCurrent = old('ethnicity', $user->ethnicity))
                    <select name="ethnicity" id="ethnicity" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" disabled @selected(empty($ethnicityCurrent))>— Select —</option>
                        @foreach($ethnicityOptions as $value => $label)
                            <option value="{{ $value }}" @selected($ethnicityCurrent === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="previously_disadvantaged_choice" class="block text-sm font-medium text-stone-700">Previously Disadvantaged <span class="text-red-600">*</span></label>
                    @php($pdCurrent = old('previously_disadvantaged_choice', $user->previously_disadvantaged === true ? 'yes' : ($user->previously_disadvantaged === false ? 'no' : '')))
                    <select name="previously_disadvantaged_choice" id="previously_disadvantaged_choice" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" disabled @selected($pdCurrent === '')>— Select —</option>
                        <option value="yes" @selected($pdCurrent === 'yes')>Yes</option>
                        <option value="no" @selected($pdCurrent === 'no')>No</option>
                    </select>
                </div>

                <div class="border-t border-stone-200 pt-5">
                    <h3 class="font-heading text-base font-semibold text-stone-900">Membership &amp; Selection Eligibility</h3>
                    <p class="mt-1 text-sm text-stone-500">Used by the IPRF team selection process (citizenship, residence and club affiliation).</p>
                </div>

                <div>
                    <label for="club_id" class="block text-sm font-medium text-stone-700">Primary Club <span class="text-red-600">*</span></label>
                    <select name="club_id" id="club_id" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" disabled @selected(empty(old('club_id', $user->club_id)))>— Select your club —</option>
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
                    <span class="block text-sm font-medium text-stone-700">South African Citizen <span class="text-red-600">*</span></span>
                    @php($saCitizenCurrent = old('sa_citizen', $user->sa_citizen === true ? '1' : ($user->sa_citizen === false ? '0' : '')))
                    <div class="mt-2 flex items-center gap-6 text-sm text-stone-700">
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="sa_citizen" value="1" required @checked($saCitizenCurrent === '1') class="text-emerald-700 focus:ring-emerald-500">
                            <span>Yes</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="sa_citizen" value="0" required @checked($saCitizenCurrent === '0') class="text-emerald-700 focus:ring-emerald-500">
                            <span>No</span>
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-stone-400">Required by IPRF (ELG-02) to represent South Africa.</p>
                </div>

                <div>
                    <label for="country_of_residence" class="block text-sm font-medium text-stone-700">Country of Residence <span class="text-red-600">*</span></label>
                    <select name="country_of_residence" id="country_of_residence" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" disabled @selected(empty(old('country_of_residence', $user->country_of_residence)))>— Select —</option>
                        @foreach($countries as $code => $label)
                            <option value="{{ $code }}" @selected(old('country_of_residence', $user->country_of_residence) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-stone-400">If you live outside South Africa, ELG-04 requires that you shot the mandatory SA Championship match in the qualifying year.</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Public Profile &amp; Privacy</h2>
                    <p class="mt-1 text-sm text-stone-500">
                        Your shooter profile at
                        @if($user->membership?->saprf_number)
                            <a href="{{ route('shooters.show', ['saprfNumber' => $user->membership->saprf_number]) }}" class="text-emerald-700 hover:text-emerald-800 underline">/shooters/{{ $user->membership->saprf_number }}</a>
                        @else
                            <span class="font-mono text-stone-600">/shooters/&laquo;your SAPRF number&raquo;</span>
                        @endif
                        shows your season standings, national-team appearances and gear specs. Choose who can see it.
                    </p>
                    <p class="mt-2 text-xs text-stone-400">
                        Under POPIA (Section 11), you control processing of your personal information for public display.
                        This setting only affects your profile page — federation match results and standings tables remain visible per SAPRF's constitution.
                    </p>
                </div>

                <fieldset class="space-y-3">
                    <legend class="sr-only">Public profile visibility</legend>
                    @foreach($visibilityOptions as $value => $meta)
                        <label class="flex items-start gap-3 rounded-xl border-2 p-4 cursor-pointer transition
                                {{ old('public_profile_visibility', $user->public_profile_visibility ?? \App\Models\User::PROFILE_VISIBILITY_PUBLIC) === $value
                                    ? ($meta['accent'] === 'emerald' ? 'border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-400'
                                        : ($meta['accent'] === 'blue' ? 'border-blue-500 bg-blue-50/50 ring-2 ring-blue-400'
                                            : 'border-stone-500 bg-stone-50 ring-2 ring-stone-400'))
                                    : 'border-stone-200 hover:border-stone-300' }}">
                            <input type="radio" name="public_profile_visibility" value="{{ $value }}"
                                   @checked(old('public_profile_visibility', $user->public_profile_visibility ?? \App\Models\User::PROFILE_VISIBILITY_PUBLIC) === $value)
                                   class="mt-0.5 size-4 shrink-0 border-stone-300 text-emerald-700 focus:ring-emerald-500">
                            <div>
                                <p class="text-sm font-semibold text-stone-900">{{ $meta['label'] }}</p>
                                <p class="text-xs text-stone-500 mt-0.5">{{ $meta['helper'] }}</p>
                            </div>
                        </label>
                    @endforeach
                </fieldset>

                @error('public_profile_visibility')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror

                @if(($user->is_managed_account ?? false) && $user->managed_relationship === 'junior')
                    <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                        This is a managed junior account. The default is <strong>members only</strong> for extra privacy. Consider keeping it that way.
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Change Password</h2>
                    <p class="mt-1 text-sm text-stone-500">Leave blank if you don't want to change your password.</p>
                </div>

                <x-password-field name="current_password" id="current_password" label="Current Password"
                    autocomplete="current-password" :checklist="false">
                    @error('current_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </x-password-field>

                <x-password-field name="new_password" id="new_password" label="New Password"
                    :min="8" :letters="true" :numbers="true">
                    @error('new_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </x-password-field>

                <x-password-field name="new_password_confirmation" id="new_password_confirmation" label="Confirm New Password" :checklist="false" />
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Changes</button>
                <a href="{{ route('dashboard') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
            </div>
        </form>

        <form method="POST" action="{{ route('profile.notification-preferences.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Notification Preferences</h2>
                    <p class="mt-1 text-sm text-stone-500">Email announcements are always kept in your <a href="{{ route('communications.index') }}" class="text-emerald-700 hover:text-emerald-800 underline">Communications</a> archive. Policy changes and urgent notices always send, regardless of these preferences.</p>
                </div>

                <div>
                    <span class="block text-sm font-medium text-stone-700">Mute email for these categories</span>
                    <div class="mt-2 space-y-2">
                        @foreach ($mutableCategories as $cat)
                            <label class="flex items-start gap-3 rounded-lg border border-stone-200 p-3 hover:bg-stone-50 transition cursor-pointer">
                                <input type="checkbox" name="muted_categories[]" value="{{ $cat->value }}"
                                    @checked(in_array($cat->value, $mutedCategories, true))
                                    class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                                <div>
                                    <span class="text-sm font-semibold text-stone-900">{{ $cat->label() }}</span>
                                    <p class="text-xs text-stone-500 mt-0.5">Skip email for {{ strtolower($cat->label()) }} announcements.</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div x-data="pushToggle()" x-init="init()"
                    class="rounded-lg border border-stone-200 p-3">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="push_enabled" value="1"
                            @checked($pushEnabled)
                            x-model="prefEnabled"
                            class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <div class="flex-1">
                            <span class="text-sm font-semibold text-stone-900">Enable push notifications for this account</span>
                            <p class="text-xs text-stone-500 mt-0.5">Also enable push on <em>this device</em> below (per-device browser permission).</p>
                        </div>
                    </label>

                    <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-stone-100 pt-3">
                        <template x-if="!supported">
                            <p class="text-xs text-stone-400">
                                This browser does not support push notifications.
                                On iPhone/iPad you must install the app to your Home Screen from Safari first,
                                then open it from the Home Screen icon to enable notifications.
                                On Android use Chrome.
                            </p>
                        </template>
                        <template x-if="supported">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" @click="toggle()" :disabled="working"
                                    class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 disabled:opacity-50">
                                    <span x-show="!working" x-text="deviceEnabled ? 'Disable push on this device' : 'Enable push on this device'"></span>
                                    <span x-show="working">Working…</span>
                                </button>
                                <span class="text-xs" :class="deviceEnabled ? 'text-emerald-700' : 'text-stone-400'"
                                    x-text="deviceEnabled ? 'Subscribed' : 'Not subscribed'"></span>
                                <button type="button" @click="sendTest()"
                                    x-show="deviceEnabled"
                                    :disabled="testing"
                                    class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 disabled:opacity-50">
                                    <span x-show="!testing">Send test notification</span>
                                    <span x-show="testing">Sending…</span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <template x-if="error">
                        <p class="mt-2 text-xs text-red-700" x-text="error"></p>
                    </template>
                    <template x-if="testResult">
                        <p class="mt-2 text-xs"
                            :class="testResult.ok ? 'text-emerald-700' : 'text-amber-700'"
                            x-text="testResult.message"></p>
                    </template>
                </div>

                <div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Preferences</button>
                </div>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            function pushToggle() {
                return {
                    supported: !!(window.saprfPush && window.saprfPush.supported),
                    deviceEnabled: false,
                    prefEnabled: {{ ($pushEnabled ?? true) ? 'true' : 'false' }},
                    working: false,
                    testing: false,
                    error: null,
                    testResult: null,

                    async init() {
                        if (!this.supported) return;
                        try {
                            const sub = await window.saprfPush.currentSubscription();
                            this.deviceEnabled = !!sub;
                        } catch (e) {
                            this.error = e.message;
                        }
                    },

                    async toggle() {
                        this.working = true;
                        this.error = null;
                        this.testResult = null;
                        try {
                            if (this.deviceEnabled) {
                                await window.saprfPush.unsubscribe();
                                this.deviceEnabled = false;
                            } else {
                                await window.saprfPush.subscribe();
                                this.deviceEnabled = true;
                                this.prefEnabled = true;
                            }
                        } catch (e) {
                            this.error = e.message;
                        } finally {
                            this.working = false;
                        }
                    },

                    async sendTest() {
                        this.testing = true;
                        this.testResult = null;
                        this.error = null;
                        try {
                            const result = await window.saprfPush.sendTest();
                            this.testResult = { ok: result.sent > 0, message: result.message };
                        } catch (e) {
                            this.testResult = { ok: false, message: e.message };
                        } finally {
                            this.testing = false;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-layouts.app>
