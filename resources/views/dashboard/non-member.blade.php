<x-layouts.app>
    <x-slot:title>Dashboard - SAPRF</x-slot:title>

    @php
        $status = $membership?->membershipStatus();
        $statusLabel = $status?->label() ?? 'No membership';
        $saprfNumber = $membership?->saprf_number;
    @endphp

    <div class="space-y-6">
        {{-- Hero card: welcome + current membership status --}}
        <div class="rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white shadow-sm overflow-hidden">
            <div class="p-6 sm:p-8 flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Welcome</p>
                    <h1 class="mt-1 text-2xl font-bold text-stone-900 sm:text-3xl">
                        {{ $user->name ?: 'Shooter' }}
                    </h1>
                    <p class="mt-2 text-sm text-stone-600 max-w-xl">
                        You're signed in without an active SAPRF membership. You can still register for any
                        open match on the platform — only member tooling (certificates, firearm shooting
                        record, kit trackers, standings stats) needs a paid membership.
                    </p>
                    <div class="mt-3 inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 ring-1 ring-stone-200">
                        <span class="size-2 rounded-full
                            {{ $status?->value === 'lapsed' ? 'bg-amber-500' : ($status?->value === 'expired' ? 'bg-red-500' : 'bg-stone-400') }}">
                        </span>
                        <span class="text-xs font-medium text-stone-700">{{ $statusLabel }}</span>
                        @if($saprfNumber)
                            <span class="text-xs text-stone-400">· {{ $saprfNumber }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex flex-col gap-2 sm:items-end">
                    <a href="{{ route('my-membership') }}"
                       class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                        @if($status?->value === 'lapsed' || $status?->value === 'expired')
                            Renew membership
                        @else
                            Join SAPRF
                        @endif
                    </a>
                    <a href="{{ route('events.index') }}"
                       class="inline-flex items-center justify-center rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-stone-700 ring-1 ring-stone-200 hover:bg-stone-50">
                        Browse matches
                    </a>
                </div>
            </div>
        </div>

        {{-- What membership unlocks: three-card checklist --}}
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="flex items-start gap-3">
                    <flux:icon.identification class="size-6 text-emerald-700 shrink-0" />
                    <div>
                        <h3 class="text-sm font-semibold text-stone-900">Membership certificate</h3>
                        <p class="mt-1 text-xs text-stone-500">
                            PDF proof of SAPRF membership for firearm licence applications and competition entries.
                        </p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="flex items-start gap-3">
                    <flux:icon.document-chart-bar class="size-6 text-emerald-700 shrink-0" />
                    <div>
                        <h3 class="text-sm font-semibold text-stone-900">Shooting record</h3>
                        <p class="mt-1 text-xs text-stone-500">
                            Full activity report of every match and score. Required by the Firearms Control Act for renewals.
                        </p>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="flex items-start gap-3">
                    <flux:icon.wrench-screwdriver class="size-6 text-emerald-700 shrink-0" />
                    <div>
                        <h3 class="text-sm font-semibold text-stone-900">Rifle &amp; load trackers</h3>
                        <p class="mt-1 text-xs text-stone-500">
                            Barrel round counts, ladder analyser, confirmation strings — the full kit workbench.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-xs text-stone-500">
            Already paid? If this message is wrong, your renewal may still be processing — give it a few
            minutes, or <a href="{{ route('contact.create') }}" class="underline hover:text-stone-700">contact us</a>.
        </p>
    </div>

    <x-dev-switcher />
</x-layouts.app>
