<x-layouts.app :title="'Edit Membership: ' . $membership->user->name">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Edit Membership</h1>
    <p class="mt-1 text-sm text-stone-500">{{ $membership->user->name }} — {{ $membership->saprf_number ?? 'No SAPRF #' }}</p>

    @php
        $user = $membership->user;
        $currentType = old('membership_type', $membership->membership_type);
        $currentStatus = old('status', $membership->status);
        $currentPayment = old('payment_status', $membership->payment_status);
        $typeOptions = ['paid' => 'Paid', 'full' => 'Full', 'associate' => 'Associate', 'junior' => 'Junior', 'free' => 'Free / Non-member'];
        $statusOptions = ['pending' => 'Pending', 'active' => 'Active', 'lapsed' => 'Lapsed', 'suspended' => 'Suspended', 'expired' => 'Expired', 'revoked' => 'Revoked'];
        $paymentOptions = ['unpaid' => 'Unpaid', 'pending' => 'Pending', 'paid' => 'Paid', 'partial' => 'Partial', 'overdue' => 'Overdue', 'waived' => 'Waived'];
        $pdCurrent = old('previously_disadvantaged_choice', $user->previously_disadvantaged === true ? 'yes' : ($user->previously_disadvantaged === false ? 'no' : ''));
        $saCitizenCurrent = old('sa_citizen', $user->sa_citizen === true ? '1' : ($user->sa_citizen === false ? '0' : ''));
    @endphp

    <form method="POST" action="{{ route('memberships.update', $membership) }}" class="mt-8 max-w-3xl space-y-6">
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

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Personal Details</h2>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700">Full Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email Address</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Used for login, invitations and notifications. Must be unique.</p>
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-stone-700">Phone</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone', $user->phone) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="date_of_birth" class="block text-sm font-medium text-stone-700">Date of Birth</label>
                    <input type="date" name="date_of_birth" id="date_of_birth" value="{{ old('date_of_birth', $user->date_of_birth?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="sa_id_number" class="block text-sm font-medium text-stone-700">SA ID Number</label>
                    <input type="text" name="sa_id_number" id="sa_id_number" value="{{ old('sa_id_number', $user->sa_id_number) }}" maxlength="13" inputmode="numeric" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="mil_le_number" class="block text-sm font-medium text-stone-700">Mil / LE Number</label>
                    <input type="text" name="mil_le_number" id="mil_le_number" value="{{ old('mil_le_number', $user->mil_le_number) }}" maxlength="50" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="gender" class="block text-sm font-medium text-stone-700">Gender</label>
                    <select name="gender" id="gender" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Select —</option>
                        @foreach($genderOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('gender', $user->gender) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="ethnicity" class="block text-sm font-medium text-stone-700">Ethnicity</label>
                    <select name="ethnicity" id="ethnicity" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Select —</option>
                        @foreach($ethnicityOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('ethnicity', $user->ethnicity) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="previously_disadvantaged_choice" class="block text-sm font-medium text-stone-700">Previously Disadvantaged</label>
                    <select name="previously_disadvantaged_choice" id="previously_disadvantaged_choice" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" @selected($pdCurrent === '')>— Select —</option>
                        <option value="yes" @selected($pdCurrent === 'yes')>Yes</option>
                        <option value="no" @selected($pdCurrent === 'no')>No</option>
                    </select>
                </div>

                <div>
                    <label for="sa_citizen" class="block text-sm font-medium text-stone-700">South African Citizen</label>
                    <select name="sa_citizen" id="sa_citizen" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="" @selected($saCitizenCurrent === '')>— Select —</option>
                        <option value="1" @selected($saCitizenCurrent === '1')>Yes</option>
                        <option value="0" @selected($saCitizenCurrent === '0')>No</option>
                    </select>
                </div>

                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700">Province</label>
                    <select name="province_id" id="province_id" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Select —</option>
                        @foreach($provinces as $province)
                            <option value="{{ $province->id }}" @selected((string) old('province_id', $user->province_id) === (string) $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="club_id" class="block text-sm font-medium text-stone-700">Primary Club</label>
                    <select name="club_id" id="club_id" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Select —</option>
                        @foreach($clubs as $provinceName => $provinceClubs)
                            <optgroup label="{{ $provinceName }}">
                                @foreach($provinceClubs as $club)
                                    <option value="{{ $club->id }}" @selected((string) old('club_id', $user->club_id) === (string) $club->id)>{{ $club->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="country_of_residence" class="block text-sm font-medium text-stone-700">Country of Residence</label>
                    <select name="country_of_residence" id="country_of_residence" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Select —</option>
                        @foreach($countries as $code => $label)
                            <option value="{{ $code }}" @selected(old('country_of_residence', $user->country_of_residence) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Address</h2>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="address_line_1" class="block text-sm font-medium text-stone-700">Address Line 1</label>
                    <input type="text" name="address_line_1" id="address_line_1" value="{{ old('address_line_1', $user->address_line_1) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div class="sm:col-span-2">
                    <label for="address_line_2" class="block text-sm font-medium text-stone-700">Address Line 2</label>
                    <input type="text" name="address_line_2" id="address_line_2" value="{{ old('address_line_2', $user->address_line_2) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div class="sm:col-span-2">
                    <label for="address_line_3" class="block text-sm font-medium text-stone-700">Address Line 3</label>
                    <input type="text" name="address_line_3" id="address_line_3" value="{{ old('address_line_3', $user->address_line_3) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div>
                    <label for="city" class="block text-sm font-medium text-stone-700">Town / City</label>
                    <input type="text" name="city" id="city" value="{{ old('city', $user->city) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
                <div>
                    <label for="postal_code" class="block text-sm font-medium text-stone-700">Postcode</label>
                    <input type="text" name="postal_code" id="postal_code" value="{{ old('postal_code', $user->postal_code) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Membership</h2>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <label for="saprf_number" class="block text-sm font-medium text-stone-700">SAPRF Number</label>
                    <input type="text" name="saprf_number" id="saprf_number" value="{{ old('saprf_number', $membership->saprf_number) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="membership_type" class="block text-sm font-medium text-stone-700">Membership Type</label>
                    <select name="membership_type" id="membership_type" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($currentType === $value)>{{ $label }}</option>
                        @endforeach
                        @if ($currentType && ! array_key_exists($currentType, $typeOptions))
                            <option value="{{ $currentType }}" selected>{{ ucfirst($currentType) }}</option>
                        @endif
                    </select>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-stone-700">Status</label>
                    <select name="status" id="status" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($currentStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="payment_status" class="block text-sm font-medium text-stone-700">Payment Status</label>
                    <select name="payment_status" id="payment_status" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        @foreach ($paymentOptions as $value => $label)
                            <option value="{{ $value }}" @selected($currentPayment === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-stone-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $membership->start_date?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="expiry_date" class="block text-sm font-medium text-stone-700">Expiry Date</label>
                    <input type="date" name="expiry_date" id="expiry_date" value="{{ old('expiry_date', $membership->expiry_date?->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Update Membership</button>
            <a href="{{ route('memberships.show', $membership) }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
        </div>
    </form>
</x-layouts.app>
