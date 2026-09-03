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

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-stone-900">Membership Fees</h2>
                        <p class="text-sm text-stone-500">Annual membership fees are now managed as separate tiers (e.g. Adult, Senior, Military).</p>
                    </div>
                    <a href="{{ route('fees.index') }}" class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 transition-colors">
                        Manage Fees
                    </a>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <h2 class="font-heading text-lg font-semibold text-stone-900">Match Fee Surcharges</h2>
                <p class="text-sm text-stone-500">
                    These amounts are added <strong class="text-stone-700">on top</strong> of the base match entry fee set by the Match Director.
                    Active members pay only the base fee. Surcharges go 100% to <strong class="text-stone-700">SAPRF</strong>, not to the Match Director.
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
                    <p>Active member pays: <span class="font-semibold text-stone-900">R500</span> <span class="text-stone-400">(R500 to MD)</span></p>
                    <p>Lapsed member pays: <span class="font-semibold text-stone-900">R500 + R{{ number_format((float)($settings['lapsed_member_surcharge'] ?? 150), 0) }} = R{{ number_format(500 + (float)($settings['lapsed_member_surcharge'] ?? 150), 0) }}</span> <span class="text-stone-400">(R500 to MD, R{{ number_format((float)($settings['lapsed_member_surcharge'] ?? 150), 0) }} to SAPRF)</span></p>
                    <p>Non-member pays: <span class="font-semibold text-stone-900">R500 + R{{ number_format((float)($settings['non_member_surcharge'] ?? 250), 0) }} = R{{ number_format(500 + (float)($settings['non_member_surcharge'] ?? 250), 0) }}</span> <span class="text-stone-400">(R500 to MD, R{{ number_format((float)($settings['non_member_surcharge'] ?? 250), 0) }} to SAPRF)</span></p>
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
                <h2 class="font-heading text-lg font-semibold text-stone-900">Division Rules</h2>
                <p class="text-sm text-stone-500">
                    Ladies, Junior, and Senior are divisions alongside Open, Factory, Limited, and Production.
                    Every shooter picks exactly one division.
                </p>

                <div>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="division_single_select" value="0">
                        <input type="checkbox" name="division_single_select" value="1" @checked(old('division_single_select', $settings['division_single_select'] ?? '1') == '1') class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">One division per match</span>
                    </label>
                    <p class="mt-1 ml-6 text-xs text-stone-400">A shooter can only compete in one division per match.</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Match Fee Structure</h2>
                    <p class="text-sm text-stone-500">Configure how match registration fees are distributed between SAPRF, the platform, and the match director.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    {{-- SAPRF Fee — editable by owner + developer --}}
                    <div x-data="{ type: '{{ old('saprf_fee_type', $settings['saprf_fee_type'] ?? 'percentage') }}' }">
                        <label class="block text-sm font-medium text-stone-700">SAPRF Fee</label>
                        <div class="mt-1 flex gap-2">
                            <select name="saprf_fee_type" x-model="type"
                                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <option value="percentage">% of match fee</option>
                                <option value="fixed">R fixed amount</option>
                            </select>
                            <div class="relative flex-1">
                                <input type="number" step="0.01" min="0" name="saprf_fee_value"
                                       value="{{ old('saprf_fee_value', $settings['saprf_fee_value'] ?? ($settings['saprf_fee_percentage'] ?? '5')) }}"
                                       required
                                       class="block w-full rounded-lg border border-stone-300 px-3 py-2 pr-8 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <span class="absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : 'R'"></span>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-stone-400" x-show="type === 'percentage'">Percentage of the base match fee paid to the federation.</p>
                        <p class="mt-1 text-xs text-stone-400" x-show="type === 'fixed'">Fixed rand amount per shooter paid to the federation.</p>
                    </div>

                    {{-- Platform Fee — developer writes, owner reads --}}
                    @php
                        $platformType = $settings['platform_fee_type'] ?? 'percentage';
                        $platformValue = $settings['platform_fee_value'] ?? ($settings['platform_fee_percentage'] ?? '5');
                    @endphp
                    @role('developer')
                    <div x-data="{ type: '{{ old('platform_fee_type', $platformType) }}' }">
                        <label class="block text-sm font-medium text-stone-700">Platform Fee
                            <span class="ml-1 inline-flex items-center rounded-full bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 ring-1 ring-inset ring-violet-600/20">Developer</span>
                        </label>
                        <div class="mt-1 flex gap-2">
                            <select name="platform_fee_type" x-model="type"
                                    class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <option value="percentage">% of match fee</option>
                                <option value="fixed">R fixed amount</option>
                            </select>
                            <div class="relative flex-1">
                                <input type="number" step="0.01" min="0" name="platform_fee_value"
                                       value="{{ old('platform_fee_value', $platformValue) }}"
                                       required
                                       class="block w-full rounded-lg border border-stone-300 px-3 py-2 pr-8 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                <span class="absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : 'R'"></span>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-stone-400" x-show="type === 'percentage'">Percentage of the base match fee paid to the platform operator.</p>
                        <p class="mt-1 text-xs text-stone-400" x-show="type === 'fixed'">Fixed rand amount per shooter paid to the platform operator.</p>
                    </div>
                    @else
                    <div>
                        <label class="block text-sm font-medium text-stone-700">Platform Fee
                            <span class="ml-1 inline-flex items-center rounded-full bg-stone-100 px-1.5 py-0.5 text-[10px] font-semibold text-stone-500 ring-1 ring-inset ring-stone-400/20">Read only</span>
                        </label>
                        <div class="mt-1 flex items-center gap-2 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700">
                            <span class="font-mono">{{ $platformType === 'fixed' ? 'R ' . number_format((float) $platformValue, 2) : number_format((float) $platformValue, 2) . ' %' }}</span>
                            <span class="text-xs text-stone-400">{{ $platformType === 'fixed' ? 'per shooter' : 'of match fee' }}</span>
                        </div>
                        <p class="mt-1 text-xs text-stone-400">Platform fee is managed by the developer role.</p>
                    </div>
                    @endrole

                    {{-- Platform operator (payee) — owner/developer choose who gets the
                         monthly platform-fee payout. Empty = payouts can't be generated. --}}
                    <div>
                        <label for="platform_operator_user_id" class="block text-sm font-medium text-stone-700">
                            Platform Operator (payee)
                        </label>
                        <select name="platform_operator_user_id" id="platform_operator_user_id"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="">— Not set —</option>
                            @foreach($platformOperatorCandidates as $candidate)
                                <option value="{{ $candidate->id }}"
                                    @selected((string) old('platform_operator_user_id', $settings['platform_operator_user_id'] ?? '') === (string) $candidate->id)>
                                    {{ $candidate->name }} ({{ $candidate->email }})
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-stone-400">Who receives the monthly platform-fee payout. Only owner and developer accounts are listed.</p>
                    </div>

                    <div>
                        <label for="estimated_gateway_fee_percentage" class="block text-sm font-medium text-stone-700">Est. Gateway Fee (%)</label>
                        <input type="number" step="0.1" min="0" max="20" name="estimated_gateway_fee_percentage" id="estimated_gateway_fee_percentage" value="{{ old('estimated_gateway_fee_percentage', $settings['estimated_gateway_fee_percentage'] ?? '3.5') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Card-rate estimate for unpaid / cash / imported entries and revenue projections. Online PayFast payments use the actual fee from the ITN.</p>
                    </div>

                    <div>
                        <label for="estimated_gateway_flat_fee" class="block text-sm font-medium text-stone-700">Est. Gateway Flat Fee (ZAR)</label>
                        <input type="number" step="0.01" min="0" max="100" name="estimated_gateway_flat_fee" id="estimated_gateway_flat_fee" value="{{ old('estimated_gateway_flat_fee', $settings['estimated_gateway_flat_fee'] ?? '2.00') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Fixed per-transaction estimate (ZAR). Same fallback as the percentage above.</p>
                    </div>
                </div>

                <div>
                    <label for="billing_start_date" class="block text-sm font-medium text-stone-700">Billing Start Date</label>
                    <input type="date" name="billing_start_date" id="billing_start_date"
                           value="{{ old('billing_start_date', $settings['billing_start_date'] ?? '') }}"
                           class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Registrations with <code>registered_at</code> before this date have the platform fee waived (SAPRF still takes its R50 per shooter). Leave blank to always charge the platform fee. Existing rows can be backfilled with <code>php artisan saprf:waive-fees-before-date</code>.</p>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    <p><strong class="text-stone-900">How it works:</strong></p>
                    <p>SAPRF fee and platform fee are calculated on the <strong>base match fee</strong> (active member rate). Non-member and lapsed-member surcharges go 100% to SAPRF. The gateway estimate is used until PayFast reports the real fee, then MD net is recalculated.</p>
                    <p>When Billing Start Date is set, any registration made before that date has the platform fee waived — SAPRF still takes its R50 per shooter, and everything else (minus surcharge and gateway) flows to the match director.</p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Membership & Other Transaction Fees</h2>
                    <p class="text-sm text-stone-500">Platform fee applied to memberships and other non-match transactions. SAPRF retains all membership revenue — this fee covers platform operating costs.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="membership_platform_fee_pct" class="block text-sm font-medium text-stone-700">Platform Fee (%)</label>
                        <input type="number" step="0.1" min="0" max="50" name="membership_platform_fee_pct" id="membership_platform_fee_pct" value="{{ old('membership_platform_fee_pct', $settings['membership_platform_fee_pct'] ?? '2.5') }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Percentage allocated to platform costs on membership payments, donations, etc.</p>
                    </div>

                    <div class="flex items-end pb-1">
                        <p class="text-sm text-stone-500">PayFast online payments use the actual fee. The estimate ({{ $settings['estimated_gateway_fee_percentage'] ?? '3.5' }}% + R{{ $settings['estimated_gateway_flat_fee'] ?? '2.00' }}) is only a fallback for cash, imports, and projections.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    <p><strong class="text-stone-900">How it works:</strong></p>
                    <p>Unlike match fees (which are split with match directors), SAPRF keeps 100% of membership and other income. This platform fee is used in financial reporting to track the cost of running the platform against these revenue streams.</p>
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
                        <input type="password" name="payfast_merchant_key" id="payfast_merchant_key" value="" autocomplete="off" placeholder="{{ !empty($settings['payfast_merchant_key'] ?? '') ? '••••••••  (leave blank to keep)' : 'e.g. 46f0cd694581a' }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Leave blank to keep the current key.</p>
                    </div>

                    <div>
                        <label for="payfast_passphrase" class="block text-sm font-medium text-stone-700">Passphrase</label>
                        <input type="password" name="payfast_passphrase" id="payfast_passphrase" value="" autocomplete="off" placeholder="{{ !empty($settings['payfast_passphrase'] ?? '') ? '••••••••  (leave blank to keep)' : 'Your PayFast salt passphrase' }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Must match the Salt Passphrase in your PayFast dashboard (sandbox or live). Leave blank to keep current. Sandbox defaults use <code class="text-stone-500">jt7NOE43FZPn</code>.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="payfast_sandbox" value="0">
                            <input type="checkbox" name="payfast_sandbox" value="1" @checked(old('payfast_sandbox', $settings['payfast_sandbox'] ?? '1') == '1') class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-stone-700">Sandbox / Test Mode</span>
                        </label>
                        <p class="mt-1 ml-6 text-xs text-stone-400">Use PayFast sandbox for testing. Disable for live payments.</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="payments_enabled" value="0">
                            <input type="checkbox" name="payments_enabled" value="1" @checked(old('payments_enabled', $settings['payments_enabled'] ?? '0') == '1') class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
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

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-stone-900">Email (Mailgun)</h2>
                        <p class="text-sm text-stone-500">Configure Mailgun credentials for sending transactional email (membership confirmations, registration receipts, etc.).</p>
                    </div>
                    @php
                        $mgConfigured = !empty($settings['mailgun_domain'] ?? '') && !empty($settings['mailgun_secret'] ?? '');
                    @endphp
                    @if($mgConfigured)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Configured</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Not Configured</span>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="mailgun_domain" class="block text-sm font-medium text-stone-700">Mailgun Domain</label>
                        <input type="text" name="mailgun_domain" id="mailgun_domain" value="{{ old('mailgun_domain', $settings['mailgun_domain'] ?? '') }}" placeholder="e.g. mg.saprf.co.za"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label for="mailgun_secret" class="block text-sm font-medium text-stone-700">Mailgun API Key</label>
                        <input type="password" name="mailgun_secret" id="mailgun_secret" value="{{ old('mailgun_secret', $settings['mailgun_secret'] ?? '') }}" placeholder="key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Leave blank to keep the current key. Never sent back to the browser.</p>
                    </div>

                    <div>
                        <label for="mailgun_webhook_signing_key" class="block text-sm font-medium text-stone-700">Mailgun Webhook Signing Key</label>
                        <input type="password" name="mailgun_webhook_signing_key" id="mailgun_webhook_signing_key" value="" placeholder="Separate from the API key — see Mailgun → Sending → Webhooks"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">
                            Used to verify inbound webhooks at <code class="text-stone-500">/webhooks/mailgun</code>.
                            Configure Mailgun to POST <em>delivered</em>, <em>failed</em>, and <em>complained</em> events to that URL and paste the signing key here.
                            Without this, we can't turn bounces into suppressions and the delivery table stays stuck on "sent".
                        </p>
                    </div>

                    <div>
                        <label for="mailgun_endpoint" class="block text-sm font-medium text-stone-700">Mailgun Endpoint</label>
                        <select name="mailgun_endpoint" id="mailgun_endpoint"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="api.eu.mailgun.net" @selected(old('mailgun_endpoint', $settings['mailgun_endpoint'] ?? 'api.eu.mailgun.net') === 'api.eu.mailgun.net')>EU (api.eu.mailgun.net)</option>
                            <option value="api.mailgun.net" @selected(old('mailgun_endpoint', $settings['mailgun_endpoint'] ?? '') === 'api.mailgun.net')>US (api.mailgun.net)</option>
                        </select>
                        <p class="mt-1 text-xs text-stone-400">EU region is recommended for South Africa (POPIA compliance).</p>
                    </div>

                    <div>
                        <label for="mail_from_address" class="block text-sm font-medium text-stone-700">From Address</label>
                        <input type="email" name="mail_from_address" id="mail_from_address" value="{{ old('mail_from_address', $settings['mail_from_address'] ?? '') }}" placeholder="noreply@saprf.co.za"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label for="mail_from_name" class="block text-sm font-medium text-stone-700">From Name</label>
                        <input type="text" name="mail_from_name" id="mail_from_name" value="{{ old('mail_from_name', $settings['mail_from_name'] ?? '') }}" placeholder="SAPRF"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <div class="rounded-lg border border-stone-200 bg-stone-50 p-4 space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-stone-900">Federation inboxes</h3>
                        <p class="mt-1 text-xs text-stone-500">Eligibility-to-Compete mail already goes to every user with the ExCo role — there is no second copy to <code class="text-stone-600">admin@precisionrifle.co.za</code> (that address forwards to the whole ExCo). Contact-form enquiries and member replies go to the secretary.</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="secretary_email" class="block text-sm font-medium text-stone-700">Secretary Email</label>
                            <input type="email" name="secretary_email" id="secretary_email" value="{{ old('secretary_email', $settings['secretary_email'] ?? '') }}" placeholder="secretary@precisionrifle.co.za"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <p class="mt-1 text-xs text-stone-400">Contact-form enquiries and the Reply-To on member emails. Must not be the ExCo forwarder.</p>
                        </div>
                        <div>
                            <label for="owner_email" class="block text-sm font-medium text-stone-700">Owner Email</label>
                            <input type="email" name="owner_email" id="owner_email" value="{{ old('owner_email', $settings['owner_email'] ?? '') }}" placeholder="owner@precisionrifle.co.za"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <p class="mt-1 text-xs text-stone-400">Owner-only correspondence. Not used for contact-form, member replies, or eligibility forms.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4 text-sm text-stone-600 space-y-1">
                    <p><strong class="text-stone-900">Setup:</strong> Create a Mailgun account, add and verify your domain, then paste the API key and domain here. The <code class="text-xs bg-stone-200 px-1 py-0.5 rounded">.env</code> values will be used as fallback if these fields are left blank.</p>
                </div>

                {{-- Notification master switch. --}}
                @php
                    $notificationsEnabled = (bool) ($settings['notifications_enabled'] ?? true);
                @endphp
                <div class="rounded-lg border {{ $notificationsEnabled ? 'border-stone-200 bg-white' : 'border-amber-300 bg-amber-50' }} p-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="notifications_enabled" value="0">
                        <input type="checkbox" name="notifications_enabled" value="1"
                               @checked(old('notifications_enabled', $notificationsEnabled ? '1' : '0') == '1')
                               class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="flex-1">
                            <span class="block text-sm font-medium text-stone-800">Send outgoing email notifications</span>
                            <span class="mt-1 block text-xs text-stone-500 leading-relaxed">
                                Master switch for transactional emails — membership confirmations, payment receipts, match registration confirmations, membership expiry warnings, family handover invitations, and admin invitations.
                                <strong class="text-stone-700">Login OTP codes and password-reset emails are always sent</strong>, regardless of this setting, so account access is never blocked.
                            </span>
                            @unless($notificationsEnabled)
                                <span class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-300">
                                    <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/></svg>
                                    Notifications are currently paused
                                </span>
                            @endunless
                        </span>
                    </label>
                </div>
            </div>

            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Save Settings</button>
        </form>

        {{-- Test email. A separate form (HTML forms can't nest) so it can be
             submitted without saving the whole settings page. Sends directly via
             Mail::raw, bypassing the notifications master switch. --}}
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            <div>
                <h2 class="font-heading text-lg font-semibold text-stone-900">Send a test email</h2>
                <p class="text-sm text-stone-500">Send a one-off test message to any address to confirm your Mailgun settings actually deliver. Save your settings above first if you just changed them.</p>
            </div>

            @if (session('test_email_success'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                    {{ session('test_email_success') }}
                </div>
            @endif
            @if (session('test_email_error'))
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    {{ session('test_email_error') }}
                </div>
            @endif

            <form method="POST" action="{{ route('site-settings.test-email') }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
                @csrf
                <div class="flex-1">
                    <label for="test_email" class="block text-sm font-medium text-stone-700">Recipient address</label>
                    <input type="email" name="test_email" id="test_email" required value="{{ old('test_email') }}" placeholder="you@example.com"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    @error('test_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.27 3.13a.6.6 0 0 1 .82-.72l16.5 8.25a.6.6 0 0 1 0 1.08l-16.5 8.25a.6.6 0 0 1-.82-.72L6 12Zm0 0h6"/></svg>
                    Send test email
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
