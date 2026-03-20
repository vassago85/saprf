<x-layouts.app :title="'Site Settings'">
    <div class="space-y-8 max-w-3xl">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">Site Settings</h1>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Owner Only</span>
        </div>

        <form method="POST" action="{{ route('site-settings.update') }}" class="space-y-8">
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
                <h2 class="font-heading text-lg font-semibold text-stone-900">Membership Fees</h2>
                <p class="text-sm text-stone-500">Set the annual membership fee that members pay to join SAPRF.</p>

                <div>
                    <label for="annual_membership_fee" class="block text-sm font-medium text-stone-700">Annual Membership Fee (ZAR)</label>
                    <input type="number" name="annual_membership_fee" id="annual_membership_fee" step="0.01" min="0" value="{{ old('annual_membership_fee', $settings['annual_membership_fee'] ?? '500.00') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Match Fee Surcharges</h2>
                <p class="text-sm text-stone-500">
                    These amounts are added <strong class="text-stone-700">on top</strong> of the base match entry fee set by the Match Director.
                    Active members pay only the base fee.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="non_member_surcharge" class="block text-sm font-medium text-stone-700">Non-Member Surcharge (ZAR)</label>
                        <input type="number" name="non_member_surcharge" id="non_member_surcharge" step="0.01" min="0" value="{{ old('non_member_surcharge', $settings['non_member_surcharge'] ?? '250.00') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label for="lapsed_member_surcharge" class="block text-sm font-medium text-stone-700">Lapsed Member Surcharge (ZAR)</label>
                        <input type="number" name="lapsed_member_surcharge" id="lapsed_member_surcharge" step="0.01" min="0" value="{{ old('lapsed_member_surcharge', $settings['lapsed_member_surcharge'] ?? '150.00') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    <p><strong class="text-stone-900">Example:</strong> If the match entry fee is R500:</p>
                    <p>Active member pays: <span class="font-semibold text-stone-900">R500</span></p>
                    <p>Lapsed member pays: <span class="font-semibold text-stone-900">R500 + R{{ number_format((float)($settings['lapsed_member_surcharge'] ?? 150), 0) }} = R{{ number_format(500 + (float)($settings['lapsed_member_surcharge'] ?? 150), 0) }}</span></p>
                    <p>Non-member pays: <span class="font-semibold text-stone-900">R500 + R{{ number_format((float)($settings['non_member_surcharge'] ?? 250), 0) }} = R{{ number_format(500 + (float)($settings['non_member_surcharge'] ?? 250), 0) }}</span></p>
                </div>
            </div>

            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Settings</button>
        </form>
    </div>
</x-layouts.app>
