<x-layouts.app :title="$match->name">
    @include('matches.workspace._chrome')

    <div class="mt-6 space-y-4">
        {{-- Four stat tiles --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Entries</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-stone-900">{{ $counts['counted'] }}</div>
                <div class="mt-0.5 text-xs text-stone-500">
                    {{ $counts['paid'] }} paid · {{ $counts['pending_payment'] }} pending
                    @if($counts['waitlisted'] > 0)
                        · {{ $counts['waitlisted'] }} wait
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Collected</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-stone-900">R {{ number_format((float) $money['gross_revenue'], 0) }}</div>
                <div class="mt-0.5 text-xs text-stone-500">From {{ $money['platform_collected_count'] }} entries paid through the platform</div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Your payout</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-stone-900">R {{ number_format((float) $money['md_net'], 2) }}</div>
                <div class="mt-0.5 text-xs text-stone-500">
                    @if($payout)
                        {{ ucfirst($payout->status) }} · {{ $payout->reference }}
                    @else
                        Projected if everyone pays: R {{ number_format((float) $money['md_payout_projection'], 2) }}
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Results</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-stone-900">
                    {{ $resultsPublished ? 'Published' : '—' }}
                </div>
                <div class="mt-0.5 text-xs text-stone-500">
                    {{ $scoresCount }} scored
                </div>
            </div>
        </div>

        {{-- Match details + recent activity --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-stone-200 bg-white p-5 lg:col-span-2">
                <h3 class="text-sm font-semibold text-stone-900">Match details</h3>
                <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-[160px_1fr]">
                    <dt class="text-stone-500">Discipline</dt>
                    <dd class="text-stone-900">{{ $match->match_type }} · {{ \Illuminate\Support\Str::title($match->series_level) }}</dd>

                    <dt class="text-stone-500">Date</dt>
                    <dd class="text-stone-900">{{ $match->formatted_date ?: '—' }}</dd>

                    <dt class="text-stone-500">Venue</dt>
                    <dd class="text-stone-900">
                        {{ $match->venue_name ?: '—' }}
                        @if($match->location_display)
                            <span class="text-stone-500">· {{ $match->location_display }}</span>
                        @endif
                    </dd>

                    <dt class="text-stone-500">Match director</dt>
                    <dd class="text-stone-900">
                        {{ $match->match_director ?: ($match->creator?->name ?: '—') }}
                        @if($match->match_director_contact)
                            <span class="text-stone-500">· {{ $match->match_director_contact }}</span>
                        @endif
                    </dd>

                    <dt class="text-stone-500">Fees</dt>
                    <dd class="text-stone-900">
                        Member R{{ number_format((float) $match->active_member_fee, 0) }}
                        @if($match->junior_fee !== null)
                            · Junior R{{ number_format((float) $match->junior_fee, 0) }}
                        @endif
                        · Non-member R{{ number_format((float) $match->non_member_fee, 0) }}
                    </dd>

                    @if($match->registration_close_date)
                        <dt class="text-stone-500">Entries close</dt>
                        <dd class="text-stone-900">{{ $match->registration_close_date->format('j M Y') }}</dd>
                    @endif

                    @if($match->max_competitors)
                        <dt class="text-stone-500">Capacity</dt>
                        <dd class="text-stone-900">
                            {{ $counts['counted'] }} / {{ $match->max_competitors }}
                            @if($match->waitlist_enabled)
                                <span class="text-stone-500">· Waitlist on</span>
                            @endif
                        </dd>
                    @endif
                </dl>

                @if($match->description)
                    <div class="mt-4 border-t border-stone-100 pt-4">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-stone-500">Description</h4>
                        <p class="mt-1 whitespace-pre-wrap text-sm text-stone-700">{{ $match->description }}</p>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <h3 class="text-sm font-semibold text-stone-900">Recent activity</h3>
                @if($recentActivity->isEmpty())
                    <p class="mt-3 text-sm text-stone-500">No activity recorded yet.</p>
                @else
                    <ul class="mt-3 divide-y divide-stone-100 text-sm">
                        @foreach($recentActivity as $log)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span class="min-w-0 flex-1 truncate text-stone-700">
                                    {{ str($log->action_type)->replace('.', ' ')->replace('_', ' ')->ucfirst() }}
                                </span>
                                <span class="shrink-0 text-xs text-stone-500">{{ $log->created_at?->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
