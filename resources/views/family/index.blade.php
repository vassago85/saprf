<x-layouts.app :title="'My Family - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">My Family</h1>
                <p class="mt-1 text-sm text-stone-500">Manage family members under your care — juniors, your spouse, or other relatives. Handle their match entries, memberships, and standings, and pay for everyone from one account.</p>
            </div>
            <a href="{{ route('family.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Family Member
            </a>
        </div>

        @if($juniors->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-stone-200 bg-white px-6 py-12 text-center">
                <div class="mx-auto w-12 h-12 rounded-full bg-emerald-50 ring-1 ring-emerald-100 flex items-center justify-center mb-4">
                    <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
                    </svg>
                </div>
                <h3 class="font-semibold text-stone-900">No family members yet</h3>
                <p class="mt-1 text-sm text-stone-500 max-w-md mx-auto">Add a child, your spouse, or another relative to your account so you can register them for matches and pay from one place — no separate email needed.</p>
                <a href="{{ route('family.create') }}" class="inline-flex items-center gap-2 mt-4 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add your first family member
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($juniors as $junior)
                    @php
                        $age = $junior->date_of_birth ? (int) floor($junior->date_of_birth->diffInYears(now())) : null;
                        $membership = $junior->membership;
                        $membershipActive = $membership && $membership->status === 'active' && $membership->payment_status === 'paid';
                    @endphp
                    <a href="{{ route('family.show', $junior) }}"
                       class="group rounded-2xl border border-stone-200 bg-white p-5 shadow-sm hover:shadow-md hover:border-emerald-300 transition">
                        <div class="flex items-start justify-between mb-3">
                            <div class="rounded-full bg-emerald-50 ring-1 ring-emerald-100 w-12 h-12 flex items-center justify-center">
                                <span class="text-base font-bold text-emerald-700">{{ Str::of($junior->name)->explode(' ')->take(2)->map(fn($p) => Str::substr($p, 0, 1))->implode('') }}</span>
                            </div>
                            @if($junior->hasPendingHandover())
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
                                    Handover pending
                                </span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-stone-900 truncate">{{ $junior->name }}</h3>
                            @if($junior->managedRelationshipLabel())
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 ring-1 ring-sky-100 shrink-0">{{ $junior->managedRelationshipLabel() }}</span>
                            @endif
                        </div>
                        <p class="text-sm text-stone-500">
                            {{ $junior->province?->name ?? '—' }}
                            @if($age !== null) &middot; {{ $age }} yrs @endif
                        </p>
                        <div class="mt-3 pt-3 border-t border-stone-100 flex flex-wrap gap-1.5">
                            @if($junior->division)
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700">{{ $junior->division->name }}</span>
                            @endif
                            @if($membershipActive)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Active member</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-500">No membership</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="rounded-xl border border-sky-200 bg-sky-50/50 p-4 text-sm text-sky-900">
            <p class="font-semibold mb-1">How family accounts work</p>
            <ul class="list-disc pl-5 space-y-0.5 text-sky-800">
                <li>You manage everything: profile, matches, memberships, results — and pay for the whole family from your account.</li>
                <li>Family members don't need their own email or login.</li>
                <li>When someone is ready to manage their own account (e.g. they get their own email), use the <strong>Hand Over</strong> button on their profile to transfer it to them.</li>
                <li>All their scores, registrations, and standings stay attached — nothing is lost.</li>
            </ul>
        </div>
    </div>
</x-layouts.app>
