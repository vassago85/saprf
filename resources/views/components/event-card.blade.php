@props(['match', 'compact' => false, 'showPodium' => false])

@php
    $regStatus = $match->registration_status;
    $effectiveEnd = $match->match_end_date ?? $match->match_date;
    // Count whole calendar days so an evening "now" doesn't truncate a 2-days-out
    // match down to "Tomorrow" (compare date-to-date, ignoring time of day).
    $matchDay = $match->match_date->copy()->startOfDay();
    $daysAway = $matchDay->gte(now()->startOfDay())
        ? (int) now()->startOfDay()->diffInDays($matchDay)
        : null;
    $userReg = auth()->check() ? $match->userRegistration(auth()->user()) : null;
@endphp

<div {{ $attributes->merge(['class' => 'group bg-white rounded-2xl border border-stone-200 shadow-[0_1px_3px_0_rgb(0_0_0/0.04)] hover:shadow-[0_4px_12px_-2px_rgb(0_0_0/0.08)] hover:border-stone-300 transition-all duration-200 flex flex-col h-full']) }}>
    {{-- Card body --}}
    <div class="p-5 flex flex-col flex-1">
        {{-- Top row: date badge + urgency cue --}}
        <div class="flex items-start justify-between gap-3 mb-3">
            <x-date-badge :date="$match->match_date" :end-date="$match->match_end_date" :compact="true" />

            <div class="flex flex-col items-end gap-1.5">
                @if($match->is_featured)
                    <span class="bg-amber-50 text-amber-700 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset ring-amber-600/20">Featured</span>
                @endif
                @if($daysAway !== null && $daysAway <= 14 && $daysAway >= 0)
                    <span class="text-[10px] font-bold uppercase tracking-wider {{ $daysAway <= 3 ? 'text-red-600' : 'text-amber-600' }}">
                        {{ $daysAway === 0 ? 'Today' : ($daysAway === 1 ? 'Tomorrow' : $daysAway . 'd away') }}
                    </span>
                @endif
            </div>
        </div>

        {{-- Title --}}
        <a href="{{ url('/events/' . $match->id) }}"
           class="font-semibold text-stone-900 group-hover:text-emerald-800 transition leading-snug line-clamp-2 mb-2">
            {{ $match->name }}
        </a>

        {{-- Venue + Location --}}
        <div class="space-y-1 text-sm mb-3">
            @if($match->venue_name)
                <div class="flex items-center gap-1.5 text-stone-700">
                    <svg class="size-3.5 text-stone-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z" /></svg>
                    <span class="truncate font-medium">{{ $match->venue_name }}</span>
                </div>
            @endif
            @if($match->location_display)
                <div class="flex items-center gap-1.5 text-stone-500">
                    <svg class="size-3.5 text-stone-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                    <span class="truncate">{{ $match->location_display }}</span>
                </div>
            @elseif(!$match->venue_name)
                <div class="flex items-center gap-1.5 text-stone-400">
                    <svg class="size-3.5 text-stone-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z" /></svg>
                    <span>Venue TBC</span>
                </div>
            @endif
            {{-- Match Director --}}
            @if($match->director_name)
                <div class="flex items-center gap-1.5 text-stone-500">
                    <svg class="size-3.5 text-stone-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                    <span class="truncate">MD: {{ $match->director_name }}</span>
                </div>
            @endif
        </div>

        {{-- Tag row --}}
        <div class="flex flex-wrap gap-1.5 mb-4">
            <x-discipline-chip :discipline="$match->match_type" />
            <x-level-chip :level="$match->series_level" />
            @if($match->status !== 'completed')
                <x-status-chip :status="$regStatus" />
            @else
                <x-status-chip status="completed" />
            @endif
        </div>

        {{-- Podium preview for past matches --}}
        @if($showPodium && $match->status === 'completed' && $match->scores->isNotEmpty())
            <div class="border-t border-stone-100 pt-3 mb-3">
                <p class="text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-2">Podium</p>
                <div class="space-y-1.5">
                    @foreach($match->scores->take(3) as $score)
                        @php $medals = ['text-amber-500', 'text-stone-400', 'text-amber-700']; @endphp
                        {{-- Use $loop->iteration so the podium always numbers 1/2/3 even
                             when scores were imported without a placement column set. --}}
                        <div class="flex items-center gap-2.5">
                            <span class="text-xs font-bold w-4 text-right {{ $medals[$loop->index] ?? 'text-stone-300' }}">{{ $loop->iteration }}</span>
                            <span class="text-xs text-stone-700 font-medium truncate flex-1">{{ $score->shooter_name }}</span>
                            <span class="text-[11px] text-stone-400 font-medium tabular-nums">{{ number_format($score->raw_score, 1) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Capacity bar --}}
        @if($match->max_competitors && $regStatus !== 'closed' && $match->status !== 'completed')
            @php
                $filled = $match->confirmedRegistrationCount();
                $pct = min(100, round(($filled / $match->max_competitors) * 100));
            @endphp
            <div class="mb-3">
                <div class="flex items-center justify-between text-[11px] text-stone-400 mb-1">
                    <span>{{ $filled }}/{{ $match->max_competitors }} entries</span>
                    @if($match->available_slots !== null && $match->available_slots <= 5 && $match->available_slots > 0)
                        <span class="text-red-500 font-semibold">{{ $match->available_slots }} left</span>
                    @endif
                </div>
                <div class="h-1.5 bg-stone-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500 {{ $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $pct }}%"></div>
                </div>
            </div>
        @endif

        {{-- Spacer to push CTA to bottom --}}
        <div class="mt-auto"></div>

        {{-- Pricing --}}
        @if($match->status !== 'completed' && $regStatus !== 'closed' && $match->active_member_fee > 0)
            <div class="flex items-center gap-3 text-xs text-stone-500 mb-3">
                <span>Member <strong class="text-emerald-700">R{{ number_format($match->active_member_fee, 0) }}</strong></span>
                @if($match->non_member_fee > 0 && $match->non_member_fee != $match->active_member_fee)
                    <span class="text-stone-300">|</span>
                    <span>Non-member <strong class="text-stone-700">R{{ number_format($match->non_member_fee, 0) }}</strong></span>
                @endif
            </div>
        @endif

        {{-- CTA row --}}
        <div class="flex items-center gap-2">
            @if($userReg)
                <a href="{{ route('registrations.show', $userReg) }}"
                   class="flex-1 text-center px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 text-sm font-semibold ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 transition">
                    View My Entry
                </a>
            @elseif($regStatus === 'open')
                <a href="{{ auth()->check() ? url('/events/' . $match->id . '/register') : route('login', ['redirect' => url('/events/' . $match->id . '/register')]) }}"
                   class="flex-1 text-center px-4 py-2 rounded-xl bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 transition shadow-sm">
                    Register
                </a>
            @elseif($regStatus === 'waitlist')
                <a href="{{ auth()->check() ? url('/events/' . $match->id . '/register') : route('login') }}"
                   class="flex-1 text-center px-4 py-2 rounded-xl bg-amber-50 text-amber-700 text-sm font-semibold ring-1 ring-inset ring-amber-200 hover:bg-amber-100 transition">
                    Join Waitlist
                </a>
            @elseif($regStatus === 'upcoming')
                <span class="flex-1 text-center px-4 py-2 rounded-xl bg-stone-50 text-stone-400 text-sm font-medium ring-1 ring-inset ring-stone-200 cursor-default">
                    Opens {{ $match->registration_open_date?->format('j M') ?? 'Soon' }}
                </span>
            @elseif($match->status === 'completed')
                <a href="{{ url('/events/' . $match->id) }}"
                   class="flex-1 text-center px-4 py-2 rounded-xl bg-stone-50 text-stone-600 text-sm font-semibold ring-1 ring-inset ring-stone-200 hover:bg-stone-100 transition">
                    View Results
                </a>
            @elseif($regStatus === 'setup_incomplete')
                <span class="flex-1 text-center px-4 py-2 rounded-xl bg-stone-50 text-stone-400 text-sm font-medium ring-1 ring-inset ring-stone-200 cursor-default">
                    Not Open Yet
                </span>
            @else
                <span class="flex-1 text-center px-4 py-2 rounded-xl bg-stone-50 text-stone-400 text-sm font-medium ring-1 ring-inset ring-stone-200 cursor-default">
                    Registration Closed
                </span>
            @endif

            @if(!$compact)
                <a href="{{ url('/events/' . $match->id) }}"
                   class="shrink-0 p-2 rounded-xl text-stone-400 hover:text-stone-700 hover:bg-stone-50 transition" title="View Details">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            @endif
        </div>
    </div>
</div>
