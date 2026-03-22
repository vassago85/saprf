<x-layouts.guest :title="'Member Verification — SAPRF'">
    <div class="min-h-screen flex items-center justify-center bg-stone-50 p-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="font-heading text-2xl font-bold text-stone-900">SAPRF Member Verification</h1>
                <p class="text-sm text-stone-500 mt-1">South African Precision Rifle Federation</p>
            </div>

            <div class="rounded-xl border bg-white shadow-sm p-6 space-y-5">
                @if($membership)
                    <div class="text-center">
                        @if($membership->status === 'active' && $membership->payment_status === 'paid')
                            <div class="inline-flex items-center justify-center size-14 rounded-full bg-emerald-100 text-emerald-700 mb-3">
                                <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            </div>
                            <span class="inline-block rounded-full bg-emerald-100 text-emerald-800 text-xs font-semibold px-3 py-1 uppercase tracking-wider">Active Member</span>
                        @elseif($membership->isRevoked())
                            <div class="inline-flex items-center justify-center size-14 rounded-full bg-red-100 text-red-700 mb-3">
                                <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                            </div>
                            <span class="inline-block rounded-full bg-red-100 text-red-800 text-xs font-semibold px-3 py-1 uppercase tracking-wider">Revoked</span>
                        @else
                            <div class="inline-flex items-center justify-center size-14 rounded-full bg-amber-100 text-amber-700 mb-3">
                                <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            </div>
                            <span class="inline-block rounded-full bg-amber-100 text-amber-800 text-xs font-semibold px-3 py-1 uppercase tracking-wider">{{ ucfirst($membership->status) }}</span>
                        @endif
                    </div>

                    <div class="space-y-3">
                        <div class="flex justify-between py-2 border-b border-stone-100">
                            <span class="text-sm text-stone-500">Name</span>
                            <span class="text-sm font-semibold text-stone-900">{{ $membership->user->name }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-stone-100">
                            <span class="text-sm text-stone-500">SAPRF Number</span>
                            <span class="text-sm font-semibold text-stone-900 font-mono">{{ $membership->saprf_number }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-stone-100">
                            <span class="text-sm text-stone-500">Status</span>
                            <span class="text-sm font-semibold text-stone-900">{{ ucfirst($membership->status) }}</span>
                        </div>
                        <div class="flex justify-between py-2">
                            <span class="text-sm text-stone-500">Expires</span>
                            <span class="text-sm font-semibold text-stone-900">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</span>
                        </div>
                    </div>

                    <p class="text-xs text-stone-400 text-center pt-2">Verified at {{ now()->format('d M Y H:i') }} SAST</p>
                @else
                    <div class="text-center py-8">
                        <div class="inline-flex items-center justify-center size-14 rounded-full bg-stone-100 text-stone-400 mb-3">
                            <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        </div>
                        <h2 class="text-lg font-bold text-stone-900">No Member Found</h2>
                        <p class="text-sm text-stone-500 mt-1">No SAPRF membership exists for this number.</p>
                    </div>
                @endif
            </div>

            <p class="text-center text-xs text-stone-400 mt-6">&copy; {{ date('Y') }} SAPRF &mdash; South African Precision Rifle Federation</p>
        </div>
    </div>
</x-layouts.guest>
