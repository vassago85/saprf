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

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Withdrawal / Cancellation Policy</h2>
                <p class="text-sm text-stone-500">
                    Configure the admin fee charged when a shooter withdraws from a match, and the deadline before which they can receive a refund (minus the admin fee).
                    After the deadline passes, <strong class="text-stone-700">no refund</strong> is issued.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="withdrawal_admin_fee" class="block text-sm font-medium text-stone-700">Withdrawal Admin Fee (ZAR)</label>
                        <input type="number" name="withdrawal_admin_fee" id="withdrawal_admin_fee" step="0.01" min="0" value="{{ old('withdrawal_admin_fee', $settings['withdrawal_admin_fee'] ?? '100.00') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Deducted from refund when a shooter withdraws before the deadline.</p>
                    </div>

                    <div>
                        <label for="withdrawal_deadline_hours" class="block text-sm font-medium text-stone-700">Refund Deadline (hours before match)</label>
                        <input type="number" name="withdrawal_deadline_hours" id="withdrawal_deadline_hours" step="1" min="0" value="{{ old('withdrawal_deadline_hours', $settings['withdrawal_deadline_hours'] ?? '72') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Withdrawals after this deadline forfeit the full entry fee.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    @php
                        $adminFee = (float)($settings['withdrawal_admin_fee'] ?? 100);
                        $hours = (int)($settings['withdrawal_deadline_hours'] ?? 72);
                    @endphp
                    <p><strong class="text-stone-900">Example:</strong> Match fee R500, admin fee R{{ number_format($adminFee, 0) }}, deadline {{ $hours }}h:</p>
                    <p>Withdraw <strong>before</strong> {{ $hours }}h cutoff: Refund = <span class="font-semibold text-emerald-700">R{{ number_format(500 - $adminFee, 0) }}</span> (R500 − R{{ number_format($adminFee, 0) }} admin fee)</p>
                    <p>Withdraw <strong>after</strong> {{ $hours }}h cutoff: Refund = <span class="font-semibold text-red-600">R0</span> (full fee forfeited)</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Divisions & Categories Rules</h2>
                <p class="text-sm text-stone-500">Configure how divisions and categories behave across the platform.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="division_single_select" value="0">
                            <input type="checkbox" name="division_single_select" value="1" @checked(old('division_single_select', $settings['division_single_select'] ?? '1') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">One division per match</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">A shooter can only compete in one division per match.</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="category_multi_select" value="0">
                            <input type="checkbox" name="category_multi_select" value="1" @checked(old('category_multi_select', $settings['category_multi_select'] ?? '1') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Allow multiple categories per shooter</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">A shooter can be tagged with more than one category (e.g. Junior + Lady).</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="category_rankings_enabled" value="0">
                            <input type="checkbox" name="category_rankings_enabled" value="1" @checked(old('category_rankings_enabled', $settings['category_rankings_enabled'] ?? '1') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Enable category rankings</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">Show standings and rankings grouped by category.</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="division_awards_enabled" value="0">
                            <input type="checkbox" name="division_awards_enabled" value="1" @checked(old('division_awards_enabled', $settings['division_awards_enabled'] ?? '1') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Enable division awards</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">Award placements per division.</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="category_awards_enabled" value="0">
                            <input type="checkbox" name="category_awards_enabled" value="1" @checked(old('category_awards_enabled', $settings['category_awards_enabled'] ?? '0') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Enable category awards</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">Award placements per category.</p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5" x-data="{ dateMode: '{{ old('age_classification_date_mode', $settings['age_classification_date_mode'] ?? 'first_day_of_calendar_year') }}' }">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Age Category Classification</h2>
                <p class="text-sm text-stone-500">
                    Age-based categories are determined once per season using a classification date.
                    Shooters do not change categories mid-season because of a birthday.
                </p>

                <div>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="season_locked_age_categories" value="0">
                        <input type="checkbox" name="season_locked_age_categories" value="1" @checked(old('season_locked_age_categories', $settings['season_locked_age_categories'] ?? '1') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">Lock age categories for the full season</span>
                    </label>
                    <p class="mt-1 ml-6 text-xs text-stone-400">Once classified, a shooter stays in that age category until next season.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="age_classification_date_mode" class="block text-sm font-medium text-stone-700">Classification Date Mode</label>
                        <select name="age_classification_date_mode" id="age_classification_date_mode" x-model="dateMode" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="first_day_of_calendar_year">1 January of season year</option>
                            <option value="season_start_date">Season start date</option>
                            <option value="custom_date">Custom date</option>
                        </select>
                    </div>

                    <div x-show="dateMode === 'custom_date'" x-cloak>
                        <label for="age_classification_custom_date" class="block text-sm font-medium text-stone-700">Custom Classification Date</label>
                        <input type="date" name="age_classification_custom_date" id="age_classification_custom_date" value="{{ old('age_classification_custom_date', $settings['age_classification_custom_date'] ?? '') }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <h3 class="font-heading text-sm font-semibold text-stone-700 pt-2">Age Thresholds</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div>
                        <label for="prs_junior_max_age" class="block text-sm font-medium text-stone-700">PRS Junior Max Age</label>
                        <p class="text-xs text-stone-400 mb-1">Centrefire</p>
                        <input type="number" name="prs_junior_max_age" id="prs_junior_max_age" min="1" max="99" value="{{ old('prs_junior_max_age', $settings['prs_junior_max_age'] ?? '21') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label for="pr22_junior_max_age" class="block text-sm font-medium text-stone-700">PR22 Junior Max Age</label>
                        <p class="text-xs text-stone-400 mb-1">Rimfire</p>
                        <input type="number" name="pr22_junior_max_age" id="pr22_junior_max_age" min="1" max="99" value="{{ old('pr22_junior_max_age', $settings['pr22_junior_max_age'] ?? '18') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label for="senior_min_age" class="block text-sm font-medium text-stone-700">Senior Min Age</label>
                        <p class="text-xs text-stone-400 mb-1">All series</p>
                        <input type="number" name="senior_min_age" id="senior_min_age" min="1" max="99" value="{{ old('senior_min_age', $settings['senior_min_age'] ?? '55') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600">
                    <p>Age-based categories are determined using the configured classification date and remain fixed for the full season. A shooter does not move categories mid-season because of a birthday.</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Match Fee Structure</h2>
                    <p class="text-sm text-stone-500">Configure how match registration fees are distributed between SAPRF, the platform, and the match director.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="saprf_fee_percentage" class="block text-sm font-medium text-stone-700">SAPRF Fee (%)</label>
                        <input type="number" step="0.1" min="0" max="50" name="saprf_fee_percentage" id="saprf_fee_percentage" value="{{ old('saprf_fee_percentage', $settings['saprf_fee_percentage'] ?? '5') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Percentage of the base match fee paid to the federation.</p>
                    </div>

                    <div>
                        <label for="platform_fee_percentage" class="block text-sm font-medium text-stone-700">Platform Fee (%)</label>
                        <input type="number" step="0.1" min="0" max="50" name="platform_fee_percentage" id="platform_fee_percentage" value="{{ old('platform_fee_percentage', $settings['platform_fee_percentage'] ?? '5') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Percentage of the base match fee paid to the platform operator.</p>
                    </div>

                    <div>
                        <label for="estimated_gateway_fee_percentage" class="block text-sm font-medium text-stone-700">Est. Gateway Fee (%)</label>
                        <input type="number" step="0.1" min="0" max="20" name="estimated_gateway_fee_percentage" id="estimated_gateway_fee_percentage" value="{{ old('estimated_gateway_fee_percentage', $settings['estimated_gateway_fee_percentage'] ?? '3.5') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Estimated PayFast transaction fee (%). Used for MD payout projection.</p>
                    </div>

                    <div>
                        <label for="estimated_gateway_flat_fee" class="block text-sm font-medium text-stone-700">Est. Gateway Flat Fee (ZAR)</label>
                        <input type="number" step="0.01" min="0" max="100" name="estimated_gateway_flat_fee" id="estimated_gateway_flat_fee" value="{{ old('estimated_gateway_flat_fee', $settings['estimated_gateway_flat_fee'] ?? '2.00') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Fixed per-transaction fee by PayFast (ZAR). Used for MD payout projection.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    <p><strong class="text-stone-900">How it works:</strong></p>
                    <p>SAPRF fee and platform fee are calculated on the <strong>base match fee</strong> (active member rate). Non-member and lapsed-member surcharges go 100% to SAPRF. The estimated gateway fee is deducted from the total to project the match director's net payout.</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-stone-900">Payment Gateway (PayFast)</h2>
                        <p class="text-sm text-stone-500">Configure PayFast credentials for online payments. Leave blank to disable online payments.</p>
                    </div>
                    @php
                        $pfConfigured = !empty($settings['payfast_merchant_id'] ?? '') && !empty($settings['payfast_merchant_key'] ?? '');
                    @endphp
                    @if($pfConfigured)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Configured</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Not Configured</span>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="payfast_merchant_id" class="block text-sm font-medium text-stone-700">Merchant ID</label>
                        <input type="text" name="payfast_merchant_id" id="payfast_merchant_id" value="{{ old('payfast_merchant_id', $settings['payfast_merchant_id'] ?? '') }}" placeholder="e.g. 10000100" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label for="payfast_merchant_key" class="block text-sm font-medium text-stone-700">Merchant Key</label>
                        <input type="text" name="payfast_merchant_key" id="payfast_merchant_key" value="{{ old('payfast_merchant_key', $settings['payfast_merchant_key'] ?? '') }}" placeholder="e.g. 46f0cd694581a" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label for="payfast_passphrase" class="block text-sm font-medium text-stone-700">Passphrase</label>
                        <input type="password" name="payfast_passphrase" id="payfast_passphrase" value="{{ old('payfast_passphrase', $settings['payfast_passphrase'] ?? '') }}" placeholder="Your PayFast passphrase" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Set in your PayFast dashboard under Settings &gt; Integration.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="payfast_sandbox" value="0">
                            <input type="checkbox" name="payfast_sandbox" value="1" @checked(old('payfast_sandbox', $settings['payfast_sandbox'] ?? '1') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Sandbox / Test Mode</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">Use PayFast sandbox for testing. Disable for live payments.</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="payments_enabled" value="0">
                            <input type="checkbox" name="payments_enabled" value="1" @checked(old('payments_enabled', $settings['payments_enabled'] ?? '0') == '1') class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Enable Online Payments</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">Master toggle. When off, all payment buttons are hidden and registrations are manual.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    <p><strong class="text-stone-900">Sandbox test credentials:</strong></p>
                    <p>Merchant ID: <code class="text-xs bg-stone-200 px-1 py-0.5 rounded">10000100</code> &nbsp; Key: <code class="text-xs bg-stone-200 px-1 py-0.5 rounded">46f0cd694581a</code> &nbsp; Passphrase: <code class="text-xs bg-stone-200 px-1 py-0.5 rounded">jt7NOE43FZPn</code></p>
                </div>
            </div>

            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Settings</button>
        </form>
    </div>
</x-layouts.app>
