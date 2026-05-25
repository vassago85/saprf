<x-layouts.app :title="$junior->name . ' - My Family'">
    @php
        $age = $junior->date_of_birth ? $junior->date_of_birth->diffInYears(now()) : null;
        $membership = $junior->membership;
        $membershipActive = $membership && $membership->status === 'active' && $membership->payment_status === 'paid';
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <a href="{{ route('family.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; My Family</a>
                <div class="mt-2 flex items-center gap-3">
                    <div class="rounded-full bg-emerald-50 ring-1 ring-emerald-100 w-14 h-14 flex items-center justify-center">
                        <span class="text-lg font-bold text-emerald-700">{{ Str::of($junior->name)->explode(' ')->take(2)->map(fn($p) => Str::substr($p, 0, 1))->implode('') }}</span>
                    </div>
                    <div>
                        <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $junior->name }}</h1>
                        <p class="text-sm text-stone-500">
                            {{ $junior->province?->name ?? '—' }}
                            @if($age !== null) &middot; {{ $age }} years old @endif
                            @if($membership?->saprf_number) &middot; <span class="font-mono">{{ $membership->saprf_number }}</span> @endif
                        </p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('family.edit', $junior) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                    Edit
                </a>
                <a href="{{ route('events.index') }}"
                   class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Browse Matches
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        {{-- Quick Stats --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs font-medium text-stone-500 uppercase">Membership</p>
                @if($membershipActive)
                    <p class="mt-1 text-lg font-semibold text-emerald-700">Active</p>
                    <p class="text-xs text-stone-400">Until {{ $membership->expiry_date?->format('d M Y') }}</p>
                @else
                    <p class="mt-1 text-lg font-semibold text-stone-700">{{ $membership?->status ?? 'None' }}</p>
                    <p class="text-xs text-stone-400">@if(!$membership) <a href="{{ route('memberships.create') }}?for_user={{ $junior->id }}" class="text-emerald-700 hover:underline">Apply for membership</a> @endif</p>
                @endif
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs font-medium text-stone-500 uppercase">Division</p>
                <p class="mt-1 text-lg font-semibold text-stone-900">{{ $junior->division?->name ?? '—' }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs font-medium text-stone-500 uppercase">Categories</p>
                <p class="mt-1 text-sm font-medium text-stone-700 truncate">
                    {{ $junior->categories->pluck('name')->join(', ') ?: '—' }}
                </p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs font-medium text-stone-500 uppercase">Recent Scores</p>
                <p class="mt-1 text-lg font-semibold text-stone-900">{{ $recentScores->count() }}</p>
                <p class="text-xs text-stone-400">Last 5 valid scores</p>
            </div>
        </div>

        {{-- Upcoming Registrations --}}
        <div class="rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="px-5 py-4 border-b border-stone-100 flex items-center justify-between">
                <h2 class="font-semibold text-stone-900">Upcoming Match Registrations</h2>
                <a href="{{ route('events.index') }}?register_for={{ $junior->id }}" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800">+ Register for a match</a>
            </div>
            @if($upcomingRegistrations->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-stone-400">
                    No upcoming registrations.
                    <a href="{{ route('events.index') }}?register_for={{ $junior->id }}" class="block mt-2 text-emerald-700 font-medium hover:underline">Browse upcoming matches &rarr;</a>
                </div>
            @else
                <div class="divide-y divide-stone-100">
                    @foreach($upcomingRegistrations as $reg)
                        <div class="px-5 py-3 flex items-center justify-between gap-3">
                            <div>
                                <a href="{{ route('events.show', $reg->match) }}" class="font-medium text-stone-900 hover:text-emerald-700">{{ $reg->match->name }}</a>
                                <p class="text-xs text-stone-500">{{ $reg->match->match_date->format('l, j F Y') }} &middot; {{ $reg->match->venue_name }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ match($reg->registration_status) {
                                'confirmed' => 'bg-emerald-50 text-emerald-700',
                                'pending' => 'bg-amber-50 text-amber-700',
                                'waitlisted' => 'bg-amber-100 text-amber-800',
                                'cancelled' => 'bg-red-50 text-red-700',
                                default => 'bg-stone-100 text-stone-600',
                            } }}">{{ ucfirst($reg->registration_status) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Recent Scores --}}
        @if($recentScores->isNotEmpty())
            <div class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                <div class="px-5 py-4 border-b border-stone-100">
                    <h2 class="font-semibold text-stone-900">Recent Scores</h2>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                        <tr>
                            <th class="px-5 py-3">Date</th>
                            <th class="px-5 py-3">Match</th>
                            <th class="px-5 py-3 text-right">Raw Score</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($recentScores as $score)
                            <tr class="hover:bg-stone-50">
                                <td class="px-5 py-3 text-stone-500">{{ $score->match_date?->format('d M Y') }}</td>
                                <td class="px-5 py-3 font-medium text-stone-900">{{ $score->match->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-right font-mono text-stone-900">{{ number_format((float) $score->raw_score, 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Handover Panel --}}
        <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-5">
            <div class="flex items-start gap-3">
                <div class="rounded-full bg-white ring-1 ring-amber-200 w-10 h-10 flex items-center justify-center flex-shrink-0">
                    <svg class="h-5 w-5 text-amber-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m12.75 15 3-3m0 0-3-3m3 3h-7.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-stone-900">Hand the account over to {{ $junior->name }}</h3>
                    <p class="mt-1 text-sm text-stone-600">When they have their own email and want to manage things themselves, send them an invitation. They'll set their own password and the account becomes theirs. All scores, registrations, and standings stay with them.</p>

                    @if($junior->hasPendingHandover())
                        <div class="mt-3 rounded-lg border border-amber-300 bg-white p-3 text-sm">
                            <p class="font-medium text-stone-900">Pending invitation</p>
                            <p class="text-xs text-stone-500 mt-0.5">
                                Sent to <strong class="font-mono">{{ $junior->handover_email }}</strong> &middot; expires {{ $junior->handover_expires_at?->diffForHumans() }}
                            </p>
                            <form method="POST" action="{{ route('family.handover.cancel', $junior) }}" class="mt-2">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700"
                                        onclick="return confirm('Cancel the pending handover invitation?')">Cancel invitation</button>
                            </form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('family.handover.start', $junior) }}" class="mt-3 flex flex-col sm:flex-row gap-2 max-w-lg">
                            @csrf
                            <input type="email" name="handover_email" required
                                   placeholder="their.real.email@example.com"
                                   class="flex-1 rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                            <button type="submit"
                                    class="rounded-lg bg-amber-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-800 transition">
                                Send invitation
                            </button>
                        </form>
                        @error('handover_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
