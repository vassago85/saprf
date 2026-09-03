<x-layouts.app>
    <x-slot:title>My Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        {{-- Reminder banner when a staff user has switched into shooter mode.
             Pure members never see this because they can't switch. --}}
        @if(auth()->user()->canSwitchViewMode())
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 flex items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <svg class="size-5 text-sky-600 shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.412 15.655.706-.706m-.706.706-3.032 3.032a1.5 1.5 0 0 1-2.121 0l-2.29-2.29a1.5 1.5 0 0 1 0-2.122L7.001 11.253l.706-.706m3.705 5.108-3.705-5.108m3.705 5.108L15.68 12.19m-7.973-1.643L11.412 4.84l4.268 4.268-3.706 3.083m-4.267-1.644L15.68 12.19m-7.973-1.643 4.268-4.267"/>
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-sky-900">Shooter mode</p>
                        <p class="text-xs text-sky-800/80 mt-0.5">You're viewing your own shooter dashboard. Admin tools are hidden until you switch back.</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('dashboard.view-mode') }}">
                    @csrf
                    <input type="hidden" name="mode" value="admin">
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-200 hover:bg-sky-100 whitespace-nowrap">
                        Switch to Admin
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                    </button>
                </form>
            </div>
        @endif

        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <p class="text-sm text-stone-500">Welcome back,</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">{{ Str::before($user->name, ' ') }}</h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('standings.shooter', ['season' => now()->year, 'user' => $user->id]) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:border-emerald-300 hover:text-emerald-700 shadow-sm">
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                    View public profile
                </a>
                @if($membership?->isActiveMember())
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-600/20">SAPRF Member</span>
                @else
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-stone-100 text-stone-600 ring-1 ring-inset ring-stone-500/20">Free Account</span>
                @endif
            </div>
        </div>

        {{-- Membership renewal notice (from 30 days before expiry) --}}
        @if($membership?->shouldShowDashboardRenewalNotice())
            @php $daysLeft = $membership->daysUntilExpiry(); @endphp
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 flex items-center justify-between gap-4 flex-wrap">
                <div class="flex items-start gap-3">
                    <svg class="size-5 text-amber-600 shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-amber-900">Membership renewal due</p>
                        <p class="text-xs text-amber-800/90 mt-0.5">
                            Your membership
                            {{ $daysLeft === 0 ? 'expires today' : ($daysLeft === 1 ? 'expires tomorrow' : "expires in {$daysLeft} days") }}
                            ({{ $membership->expiry_date->format('d M Y') }}). Renew now to keep standings and member rates uninterrupted.
                        </p>
                    </div>
                </div>
                <a href="{{ route('my-membership') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-800 whitespace-nowrap">
                    Renew membership
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                </a>
            </div>
        @endif

        {{-- Membership Status Card --}}
        @if($membership?->isActiveMember())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-heading text-xl font-bold text-stone-900">Membership</h2>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">{{ $membership->effective_status_label }}</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">SAPRF Number</p>
                        <p class="text-lg font-bold text-stone-900 mt-1 font-mono">{{ $membership->saprf_number ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">Province</p>
                        <p class="text-lg font-bold text-stone-900 mt-1">{{ $user->province?->name ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">Status</p>
                        <p class="text-lg font-bold text-stone-900 mt-1">{{ $membership->effective_status_label }}</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">Expires</p>
                        <p class="text-lg font-bold text-stone-900 mt-1">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</p>
                    </div>
                </div>
            </div>
        @elseif($membership && $membership->status === 'pending' && $membership->payment_status !== 'paid')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-start gap-4">
                        <div class="inline-flex items-center justify-center size-10 rounded-lg bg-amber-100 text-amber-700 shrink-0">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <div>
                            <h3 class="font-heading text-lg font-bold text-amber-900">Payment Pending</h3>
                            <p class="text-sm text-amber-800 mt-1">Your membership is ready — complete payment to activate.</p>
                        </div>
                    </div>
                    <a href="{{ route('my-membership') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 shrink-0">Complete Payment</a>
                </div>
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-start gap-4">
                        <div class="inline-flex items-center justify-center size-10 rounded-lg bg-stone-100 text-stone-500 shrink-0">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>
                        </div>
                        <div>
                            <h3 class="font-heading text-lg font-bold text-stone-900">Free Account</h3>
                            <p class="text-sm text-stone-600 mt-1">You can register for matches but won't appear in official standings. Become a member for a SAPRF number, standings eligibility, and reduced match fees.</p>
                        </div>
                    </div>
                    <a href="{{ route('my-membership') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 shrink-0">Become a Member</a>
                </div>
            </div>
        @endif

        {{-- Season Stats — split by discipline (PRS / PR22) and level (Provincial / National).
             PRS and PR22 points are NOT interchangeable — they're separate series with
             separate standings, so they're never rolled into a shared total here. --}}
        <div class="rounded-2xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-stone-50/70 to-white">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-heading text-xl font-bold text-stone-900">Season Stats</h2>
                        <p class="text-sm text-stone-500 mt-1">Broken down by discipline and level for {{ now()->year }}.</p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Total This Season</p>
                        <p class="text-2xl font-bold text-stone-900 mt-0.5 tabular-nums">
                            {{ $matchesShot }} <span class="text-sm font-medium text-stone-500">{{ Str::plural('match', $matchesShot) }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-px bg-stone-100">
                @foreach(['PRS', 'PR22'] as $seriesKey)
                    @php
                        $seriesRows = collect($statsBreakdown)->where('series', $seriesKey)->values();
                        $seriesTotalMatches = $seriesRows->sum('matches');
                        $seriesTotalPoints = $seriesRows->sum('points');
                    @endphp
                    <div class="bg-white p-6 space-y-4">
                        {{-- Discipline header --}}
                        <div class="flex items-center justify-between gap-3">
                            <x-discipline-chip :discipline="$seriesKey" class="!px-3 !py-1 !text-sm" />
                            @if($seriesTotalMatches > 0)
                                <span class="text-xs font-medium text-stone-500 tabular-nums">
                                    <span class="text-stone-700 font-semibold">{{ $seriesTotalMatches }}</span> {{ Str::plural('match', $seriesTotalMatches) }}
                                    <span class="mx-1 text-stone-300">·</span>
                                    <span class="text-emerald-700 font-semibold">{{ number_format($seriesTotalPoints) }}</span> pts
                                </span>
                            @else
                                <span class="text-xs text-stone-400">No {{ $seriesKey }} matches yet</span>
                            @endif
                        </div>

                        @foreach($seriesRows as $row)
                            @php
                                $isProvincial = $row['level'] === 'provincial';
                                $best = $row['best'];
                                // Podium colouring — gold/silver/bronze for #1/#2/#3.
                                $bestTileClass = match(true) {
                                    $best === 1 => 'from-amber-100 to-amber-50 ring-amber-300',
                                    $best === 2 => 'from-stone-200 to-stone-50 ring-stone-300',
                                    $best === 3 => 'from-orange-100 to-orange-50 ring-orange-300',
                                    default     => 'from-white to-stone-50/40 ring-stone-200',
                                };
                                $bestTextClass = match(true) {
                                    $best === 1 => 'text-amber-800',
                                    $best === 2 => 'text-stone-700',
                                    $best === 3 => 'text-orange-800',
                                    default     => 'text-stone-800',
                                };
                                $bestIconClass = match(true) {
                                    $best === 1 => 'text-amber-500',
                                    $best === 2 => 'text-stone-500',
                                    $best === 3 => 'text-orange-500',
                                    default     => 'text-stone-400',
                                };
                            @endphp
                            <div class="rounded-xl border border-stone-200 bg-gradient-to-br from-stone-50/60 to-white p-5 space-y-4">
                                {{-- Level header --}}
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        @if($isProvincial)
                                            {{-- Map-pin: provincial --}}
                                            <svg class="size-4 text-amber-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z"/></svg>
                                        @else
                                            {{-- Globe: national --}}
                                            <svg class="size-4 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                                        @endif
                                        <span class="text-sm font-bold uppercase tracking-wider text-stone-700">{{ $row['level'] }}</span>
                                    </div>
                                    @if($row['matches'] > 0)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-stone-600 ring-1 ring-inset ring-stone-200 tabular-nums">
                                            <svg class="size-3 text-stone-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                                            {{ $row['matches'] }} {{ Str::plural('match', $row['matches']) }}
                                        </span>
                                    @endif
                                </div>

                                @if($row['matches'] === 0)
                                    {{-- Empty state --}}
                                    <div class="text-center py-6 px-4">
                                        <div class="mx-auto inline-flex size-10 items-center justify-center rounded-full bg-stone-100 mb-2">
                                            <svg class="size-5 text-stone-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
                                        </div>
                                        <p class="text-xs text-stone-400">No {{ $seriesKey }} {{ $row['level'] }} matches shot yet.</p>
                                    </div>
                                @else
                                    {{-- Stat tiles --}}
                                    <div class="grid grid-cols-3 gap-3">
                                        {{-- Best Placement — podium colours --}}
                                        <div class="rounded-lg bg-gradient-to-br {{ $bestTileClass }} ring-1 ring-inset p-3 text-center">
                                            <svg class="mx-auto size-4 {{ $bestIconClass }} mb-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/>
                                            </svg>
                                            <p class="text-[9px] font-bold uppercase tracking-wider {{ $bestTextClass }} opacity-80">Best</p>
                                            <p class="text-xl font-bold font-mono mt-0.5 {{ $bestTextClass }}">
                                                {{ $best ? '#'.$best : '—' }}
                                            </p>
                                        </div>

                                        {{-- Average Placement --}}
                                        <div class="rounded-lg bg-gradient-to-br from-sky-50 to-white ring-1 ring-inset ring-sky-200 p-3 text-center">
                                            <svg class="mx-auto size-4 text-sky-600 mb-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 13.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
                                            </svg>
                                            <p class="text-[9px] font-bold uppercase tracking-wider text-sky-700 opacity-80">Average</p>
                                            <p class="text-xl font-bold font-mono mt-0.5 text-sky-800">
                                                {{ $row['avg'] ? '#'.$row['avg'] : '—' }}
                                            </p>
                                        </div>

                                        {{-- Points --}}
                                        <div class="rounded-lg bg-gradient-to-br from-emerald-50 to-white ring-1 ring-inset ring-emerald-200 p-3 text-center">
                                            <svg class="mx-auto size-4 text-emerald-600 mb-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/>
                                            </svg>
                                            <p class="text-[9px] font-bold uppercase tracking-wider text-emerald-700 opacity-80">Points</p>
                                            <p class="text-xl font-bold font-mono mt-0.5 text-emerald-800 tabular-nums">
                                                {{ number_format($row['points']) }}
                                            </p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        {{-- My Rifles --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-heading text-xl font-bold text-stone-900">My Rifles</h2>
                <div class="flex items-center gap-3">
                    @if($rifleCount > 3)
                        <a href="{{ route('rifle-configurations.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">View All ({{ $rifleCount }})</a>
                    @endif
                    <a href="{{ route('rifle-configurations.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Add Rifle
                    </a>
                </div>
            </div>

            @if($rifles->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach($rifles as $rifle)
                        <a href="{{ route('rifle-configurations.show', $rifle) }}" class="block rounded-lg border border-stone-200 p-4 hover:border-emerald-300 hover:shadow-sm transition group">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-semibold text-stone-900 group-hover:text-emerald-700">{{ $rifle->nickname }}</h3>
                                <x-rifle-primary-badge :rifle="$rifle" />
                            </div>
                            @if($rifle->make || $rifle->model)
                                <p class="text-sm text-stone-600">{{ $rifle->make?->name }} {{ $rifle->model?->name }}</p>
                            @endif
                            @if($rifle->calibre)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-stone-100 text-stone-600 mt-2">{{ $rifle->calibre->name }}</span>
                            @endif
                            <p class="text-xs text-stone-400 mt-2">{{ $rifle->registrations_count }} {{ Str::plural('match', $rifle->registrations_count) }}</p>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-sm text-stone-400">No rifle configurations yet. Add your first rifle to start tracking performance.</p>
                </div>
            @endif
        </div>

        {{-- Recent Match History --}}
        @if($recentMatches->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-200">
                    <h2 class="font-heading text-xl font-bold text-stone-900">Recent Match History</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Match</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Province</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Placement</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Impacts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($recentMatches as $score)
                                @php $rank = $score->displayRank(); @endphp
                                <tr class="hover:bg-stone-50">
                                    <td class="px-5 py-3 text-stone-600 whitespace-nowrap">{{ $score->match?->match_date?->format('d M Y') }}</td>
                                    <td class="px-5 py-3 font-medium text-stone-900">
                                        @if($score->match)
                                            <a href="{{ route('events.show', $score->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $score->match->name }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-stone-600">{{ $score->match?->province?->abbreviation ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right font-mono font-bold {{ $rank && $rank <= 3 ? 'text-amber-600' : 'text-stone-900' }}">
                                        {{ $rank ? '#'.$rank : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-stone-700">{{ $score->raw_score ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Season Rankings — one card per series (PRS / PR22) showing the
             shooter's National and Provincial rank, plus a per-division
             chip row (Open, Factory, Senior, Ladies, ...) because a single
             shooter often ends up with a separate rank in each division they
             competed in. Backed by the same ShooterStandingsSummaryService
             that powers the public shooter profile page, so the numbers here
             always match what appears on /standings/{year}/shooter/{user}. --}}
        @if($seasonRankings->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="font-heading text-xl font-bold text-stone-900">Season Rankings — {{ now()->year }}</h2>
                        <p class="text-sm text-stone-500 mt-1">Your national and provincial position this season, plus how you're ranked in each division you've competed in.</p>
                    </div>
                    <a href="{{ route('standings.shooter', ['season' => now()->year, 'user' => $user->id]) }}"
                       class="text-xs font-semibold text-emerald-700 hover:text-emerald-900 whitespace-nowrap">
                        Full profile &rarr;
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach($seasonRankings as $entry)
                        <div class="rounded-lg border border-stone-200 bg-gradient-to-br from-stone-50 to-white p-5 space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="font-heading text-lg font-bold text-stone-900">{{ $entry['series'] }}</span>
                                    <x-discipline-chip :discipline="$entry['series']" />
                                </div>
                            </div>

                            <div class="flex items-start gap-6 flex-wrap">
                                {{-- National --}}
                                @if($entry['overall_rank'] !== null)
                                    <div class="flex-1 min-w-[140px]">
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-600">National</p>
                                        <div class="flex items-baseline gap-2">
                                            <p class="text-3xl font-bold text-stone-900">#{{ $entry['overall_rank'] }}</p>
                                            <p class="text-xs text-stone-500 tabular-nums">{{ number_format($entry['overall_points'] ?? 0, 2) }} pts</p>
                                        </div>
                                        @if(!empty($entry['divisions']))
                                            <div class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5">
                                                @foreach($entry['divisions'] as $div)
                                                    <span class="text-[11px] text-stone-500">
                                                        {{ $div['name'] }}:
                                                        <span class="font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                {{-- Provincial --}}
                                @if(!empty($entry['has_provincial']))
                                    <div class="flex-1 min-w-[140px] {{ $entry['overall_rank'] !== null ? 'lg:border-l lg:border-stone-200 lg:pl-6' : '' }}">
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-600">
                                            Provincial @if($entry['province_name'])<span class="text-blue-400">&middot; {{ $entry['province_name'] }}</span>@endif
                                        </p>
                                        <div class="flex items-baseline gap-2">
                                            <p class="text-3xl font-bold text-stone-900">#{{ $entry['provincial_rank'] ?? '—' }}</p>
                                            <p class="text-xs text-stone-500 tabular-nums">{{ number_format($entry['provincial_points'] ?? 0, 2) }} pts</p>
                                        </div>
                                        @if(!empty($entry['provincial_divisions']))
                                            <div class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5">
                                                @foreach($entry['provincial_divisions'] as $div)
                                                    <span class="text-[11px] text-stone-500">
                                                        {{ $div['name'] }}:
                                                        <span class="font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Qualification Progress — PRS + PR22 --}}
        @if(!empty($qualificationProgress))
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-5">
                <div>
                    <h2 class="font-heading text-xl font-bold text-stone-900">Qualification Progress</h2>
                    <p class="text-sm text-stone-500 mt-1">Season match process for both PRS and PR22. Out-of-province nationals are shown when required for finals selection.</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach($qualificationProgress as $series => $progress)
                        @php
                            $matchPct = $progress['matches_required'] > 0
                                ? min(100, ($progress['matches_completed'] / $progress['matches_required']) * 100)
                                : 0;
                            $oop = $progress['oop'] ?? ['required' => 0, 'completed' => 0, 'qualified' => false];
                            $oopPct = ($oop['required'] ?? 0) > 0
                                ? min(100, (($oop['completed'] ?? 0) / $oop['required']) * 100)
                                : 0;
                        @endphp
                        <div class="rounded-xl border border-stone-200 bg-stone-50/60 p-5 space-y-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-stone-900">{{ $progress['label'] ?? $series }}</p>
                                    <p class="text-xs text-stone-500 mt-1 leading-relaxed">{{ $progress['description'] }}</p>
                                </div>
                                @if(($oop['required'] ?? 0) > 0 && ($oop['qualified'] ?? false))
                                    <span class="shrink-0 inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800">Finals eligible</span>
                                @elseif(!($progress['has_rule'] ?? false))
                                    <span class="shrink-0 inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-stone-200 text-stone-600">No rules</span>
                                @endif
                            </div>

                            @if(($progress['has_rule'] ?? false) && ($progress['matches_required'] ?? 0) > 0)
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-semibold uppercase tracking-wider text-stone-500">Qualifying matches</span>
                                        <span class="text-xs font-medium text-stone-700">{{ $progress['matches_completed'] }}/{{ $progress['matches_required'] }}</span>
                                    </div>
                                    <div class="h-2 bg-stone-200 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full bg-emerald-600 transition-all" style="width: {{ $matchPct }}%"></div>
                                    </div>
                                </div>
                            @endif

                            @if(!empty($progress['steps']))
                                <ul class="space-y-2">
                                    @foreach($progress['steps'] as $step)
                                        @php
                                            $stepDone = ($step['required'] ?? 0) > 0 && ($step['completed'] ?? 0) >= $step['required'];
                                        @endphp
                                        <li class="flex items-start gap-2.5 text-sm">
                                            <span class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full {{ $stepDone ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-500' }}">
                                                @if($stepDone)
                                                    <svg class="size-2.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" /></svg>
                                                @else
                                                    <span class="size-1.5 rounded-full bg-current"></span>
                                                @endif
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex items-baseline justify-between gap-2">
                                                    <span class="font-medium text-stone-800">{{ $step['label'] }}</span>
                                                    <span class="shrink-0 text-xs tabular-nums text-stone-500">{{ $step['completed'] }}/{{ $step['required'] }}</span>
                                                </span>
                                                @if(!empty($step['detail']))
                                                    <span class="block text-xs text-stone-400 mt-0.5">{{ $step['detail'] }}</span>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if(($oop['required'] ?? 0) > 0)
                                <div class="pt-3 border-t border-stone-200">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-semibold uppercase tracking-wider text-stone-500">Out-of-province nationals</span>
                                        <span class="text-xs font-medium text-stone-700">
                                            {{ $oop['completed'] }}/{{ $oop['required'] }}
                                            @if($oop['qualified'] ?? false)
                                                <span class="text-emerald-600 ml-1">Met</span>
                                            @endif
                                        </span>
                                    </div>
                                    <div class="h-2 bg-stone-200 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full {{ ($oop['qualified'] ?? false) ? 'bg-emerald-500' : 'bg-amber-500' }} transition-all" style="width: {{ $oopPct }}%"></div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Next Match --}}
        @if($nextMatch ?? null)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-emerald-600 uppercase tracking-wider font-semibold">Next Match</p>
                        <h3 class="font-heading text-xl font-bold text-emerald-900 mt-1">{{ $nextMatch->name }}</h3>
                        <p class="text-sm text-emerald-700 mt-1">{{ $nextMatch->match_date->format('d M Y') }} &middot; {{ $nextMatch->province?->name ?? 'TBA' }}</p>
                    </div>
                    <a href="{{ route('events.show', $nextMatch) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">View / Register</a>
                </div>
            </div>
        @endif
    </div>

    <x-dev-switcher />
</x-layouts.app>
