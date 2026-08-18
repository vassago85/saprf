<x-layouts.app :title="'Membership: ' . $membership->user->name">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Membership Details</h1>

        <div class="flex items-center gap-2">
            <a href="{{ route('memberships.edit', $membership) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                Edit
            </a>
            <a href="{{ route('memberships.index') }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back
            </a>
        </div>
    </div>

    <div class="mt-8 max-w-3xl space-y-6">
        {{-- One-shot display of a temporary password just set by an admin.
             Rendered only via session flash on the request that performed
             the reset — never re-shown, never emailed, never logged. --}}
        @if(session('temp_password'))
            <div class="rounded-xl border-2 border-amber-300 bg-amber-50 p-6 shadow-sm" x-data="{ copied: false }">
                <div class="flex items-start gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-amber-700 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-heading font-semibold text-amber-900">Temporary password for {{ session('temp_password_for', $membership->user->name) }} — shown once, copy it now</h3>
                        <p class="text-sm text-amber-800 mt-1">This is the only time the password is visible. Share it with the member directly (phone, WhatsApp, in person) — they will be forced to change it on their next login. It has not been emailed.</p>
                        <div class="mt-4 flex items-center gap-3">
                            <code id="temp-password-value" class="flex-1 min-w-0 rounded-lg border border-amber-300 bg-white px-4 py-3 font-mono text-lg text-stone-900 break-all select-all">{{ session('temp_password') }}</code>
                            <button type="button"
                                    @click="navigator.clipboard.writeText(document.getElementById('temp-password-value').innerText); copied = true; setTimeout(() => copied = false, 2000)"
                                    class="shrink-0 rounded-lg bg-amber-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-amber-800">
                                <span x-show="!copied">Copy</span>
                                <span x-show="copied" x-cloak>Copied ✓</span>
                            </button>
                        </div>
                        @if(session('temp_password_reason'))
                            <p class="mt-3 text-xs text-amber-700"><span class="font-semibold">Reason recorded in audit log:</span> {{ session('temp_password_reason') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Member Information</h2>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Name</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->user->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Email</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->user->email }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">SAPRF Number</dt>
                    <dd class="mt-1 text-sm font-mono text-stone-900">{{ $membership->saprf_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Type</dt>
                    <dd class="mt-1 text-sm text-stone-900 capitalize">{{ $membership->membership_type }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Status</dt>
                    <dd class="mt-1.5">
                        @switch($membership->status)
                            @case('active')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                @break
                            @case('pending')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                @break
                            @case('lapsed')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Lapsed</span>
                                @break
                            @case('expired')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Expired</span>
                                @break
                            @case('suspended')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Suspended</span>
                                @break
                            @case('revoked')
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800 ring-1 ring-inset ring-red-700/30">Revoked</span>
                                @break
                            @default
                                {{-- Fallback so a newly-added enum value never renders as a silent blank. --}}
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-700 ring-1 ring-inset ring-stone-500/20 capitalize">{{ $membership->status ?? 'Unknown' }}</span>
                        @endswitch
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Payment Status</dt>
                    <dd class="mt-1.5">
                        @switch($membership->payment_status)
                            @case('paid')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Paid</span>
                                @break
                            @case('waived')
                                {{-- Fee has been formally waived by an admin — counts as paid for validation, but distinguished visually. --}}
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Waived</span>
                                @break
                            @case('partial')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Partial</span>
                                @break
                            @case('pending')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                @break
                            @case('unpaid')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Unpaid</span>
                                @break
                            @case('overdue')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Overdue</span>
                                @break
                            @default
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-700 ring-1 ring-inset ring-stone-500/20 capitalize">{{ $membership->payment_status ?? 'Unknown' }}</span>
                        @endswitch
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Start Date</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->start_date?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Expiry Date</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Pay Now button for unpaid memberships --}}
        @if(in_array($membership->payment_status, ['pending', 'unpaid', 'overdue']) && $membership->membership_type === 'paid')
            @php $pfEnabled = app(\App\Services\PayFastService::class)->isEnabled(); @endphp
            @if($pfEnabled)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-emerald-800">Payment Required</h2>
                        <p class="text-sm text-emerald-700 mt-1">Pay your annual membership fee of <strong>R {{ number_format((float) app(\App\Services\SettingsService::class)->get('annual_membership_fee', 500), 2) }}</strong> to activate your membership.</p>
                    </div>
                    <form method="POST" action="{{ route('payments.membership', $membership) }}">
                        @csrf
                        <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-700 text-white font-semibold hover:bg-emerald-800 transition shadow-sm">
                            Pay Now
                        </button>
                    </form>
                </div>
            @endif
        @endif

        {{-- Revocation details --}}
        @if($membership->isRevoked())
            <div class="rounded-xl border border-red-200 bg-red-50 p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-red-800 mb-4">Membership Revoked</h2>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Revoked At</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $membership->revoked_at->format('d M Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Revoked By</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $membership->revokedByUser?->name ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-red-400">Reason</dt>
                        <dd class="mt-1 text-sm text-red-800">{{ $membership->revocation_reason }}</dd>
                    </div>
                </dl>

                @role('owner|admin')
                    <form method="POST" action="{{ route('memberships.reinstate', $membership) }}"
                          class="mt-5 border-t border-red-200 pt-4"
                          onsubmit="return confirm('Reinstate this membership? The member will be set back to active.')">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                            Reinstate Membership
                        </button>
                    </form>
                @endrole
            </div>
        @endif

        {{-- Reset password (admin only) — for members who cannot receive
             invitation or password-reset emails. Bypasses the email flow
             entirely; admin hands the temp password to the member out-of-band. --}}
        @role('developer|exco|owner|admin')
            @if($membership->user)
                <div class="rounded-xl border border-sky-200 bg-white p-6 shadow-sm" x-data="{ showForm: false }">
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="font-heading text-lg font-semibold text-sky-900">Reset Password</h2>
                            <p class="text-sm text-stone-500 mt-1">Set a temporary password for this member and force them to change it on next login. Use when the member reports not receiving invitation or password-reset emails. The temporary password is shown once — copy it and share it with the member directly.</p>
                        </div>
                        <button @click="showForm = !showForm"
                                class="shrink-0 rounded-lg border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-50">
                            Reset
                        </button>
                    </div>

                    <form x-show="showForm" x-transition method="POST"
                          action="{{ route('memberships.reset-password', $membership) }}"
                          class="mt-4 space-y-4 border-t border-sky-100 pt-4"
                          onsubmit="return confirm('Set a temporary password for {{ addslashes($membership->user->name) }}? Their old password will stop working immediately, and they will be forced to change to a new one on next login.')">
                        @csrf
                        <div>
                            <label for="reason" class="block text-sm font-medium text-stone-700 mb-1">Reason <span class="text-red-500">*</span></label>
                            <textarea name="reason" id="reason" rows="2" maxlength="500" required
                                      placeholder="e.g. Member reports not receiving password-reset emails; issuing temporary password manually."
                                      class="w-full rounded-lg border border-stone-300 py-2 text-sm focus:border-sky-500 focus:ring-sky-500">{{ old('reason') }}</textarea>
                            @error('reason')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="custom_password" class="block text-sm font-medium text-stone-700 mb-1">Custom password <span class="font-normal text-stone-400">(optional — leave blank to auto-generate a strong random one)</span></label>
                            <input type="text" name="custom_password" id="custom_password" minlength="12" maxlength="64"
                                   autocomplete="off"
                                   placeholder="Leave blank to generate"
                                   class="w-full rounded-lg border border-stone-300 py-2 text-sm focus:border-sky-500 focus:ring-sky-500">
                            @error('custom_password')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-stone-400">Minimum 12 characters. Auto-generated passwords are 16 alphanumeric characters (no symbols — easier to relay by phone or WhatsApp).</p>
                        </div>
                        <button type="submit" class="rounded-lg bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-800">
                            Set Temporary Password
                        </button>
                    </form>
                </div>
            @endif
        @endrole

        {{-- Revoke action (admin only, only if not already revoked) --}}
        @role('owner|admin')
            @if(! $membership->isRevoked())
                <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm" x-data="{ showForm: false }">
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="font-heading text-lg font-semibold text-stone-900">Revoke Membership</h2>
                            <p class="text-sm text-stone-500 mt-1">Revoke this member's membership. This will mark it as revoked and record the reason.</p>
                        </div>
                        <button @click="showForm = !showForm"
                                class="shrink-0 px-4 py-2 rounded-lg text-sm font-semibold text-red-700 bg-white border border-red-300 hover:bg-red-50 transition">
                            Revoke
                        </button>
                    </div>

                    <form x-show="showForm" x-transition method="POST"
                          action="{{ route('memberships.revoke', $membership) }}"
                          class="mt-4 space-y-4 border-t border-stone-200 pt-4"
                          onsubmit="return confirm('Are you sure you want to revoke this membership? This action is logged.')">
                        @csrf
                        <div>
                            <label for="revocation_reason" class="block text-sm font-medium text-stone-700 mb-1">Reason for revocation <span class="text-red-500">*</span></label>
                            <textarea name="revocation_reason" id="revocation_reason" rows="3" maxlength="1000" required
                                      placeholder="Describe why this membership is being revoked..."
                                      class="w-full rounded-lg border border-stone-300 text-sm py-2 focus:ring-red-500 focus:border-red-500">{{ old('revocation_reason') }}</textarea>
                            @error('revocation_reason')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition">
                            Confirm Revocation
                        </button>
                    </form>
                </div>
            @endif
        @endrole

        {{-- Delete account (admin only) — for removing duplicate imported accounts --}}
        @role('owner|admin')
            <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm" x-data="{ confirm: false }">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="font-heading text-lg font-semibold text-red-800">Delete Account</h2>
                        <p class="text-sm text-stone-500 mt-1">Permanently remove this member — use this to clean up duplicate accounts. The account is soft-deleted and can be restored from the deleted users list in User Management.</p>
                    </div>
                    <button @click="confirm = !confirm"
                            class="shrink-0 px-4 py-2 rounded-lg text-sm font-semibold text-red-700 bg-white border border-red-300 hover:bg-red-50 transition">
                        Delete
                    </button>
                </div>

                <form x-show="confirm" x-transition method="POST"
                      action="{{ route('memberships.destroy', $membership) }}"
                      class="mt-4 border-t border-red-200 pt-4"
                      onsubmit="return confirm('Delete {{ addslashes($membership->user->name) }}? This soft-deletes the account and can be undone from User Management.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition">
                        Confirm Delete Account
                    </button>
                </form>
            </div>
        @endrole

        @if ($membership->payments->count())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Payment History</h2>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b-2 border-stone-200">
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Method</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($membership->payments as $payment)
                                <tr class="hover:bg-stone-50 transition-colors">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-900">{{ $payment->created_at->format('d M Y') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-right font-mono text-stone-900">R {{ number_format($payment->amount, 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-500 capitalize">{{ $payment->payment_method ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-500">{{ $payment->payment_reference ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
