<x-layouts.app>
    <x-slot:title>Edit Profile - SAPRF</x-slot:title>

    <div class="max-w-2xl space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">Edit Profile</h1>
            <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst(str_replace('_', ' ', $user->getRoleNames()->first() ?? 'member')) }}</span>
        </div>

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
                    <label for="sa_id_number" class="block text-sm font-medium text-stone-700">SA ID Number</label>
                    <input type="text" name="sa_id_number" id="sa_id_number" value="{{ old('sa_id_number', $user->sa_id_number) }}" maxlength="13" pattern="\d{13}" placeholder="13-digit SA ID number" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Required for SASCOC reporting. 13 digits only.</p>
                </div>

                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700">Province</label>
                    <select name="province_id" id="province_id" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="">— Select province —</option>
                        @foreach($provinces as $province)
                            <option value="{{ $province->id }}" @selected(old('province_id', $user->province_id) == $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Changes</button>
                <a href="{{ route('dashboard') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
