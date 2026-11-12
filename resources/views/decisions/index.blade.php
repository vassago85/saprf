<x-layouts.public title="Decision Register — SAPRF" description="Published ExCo decisions with anonymous member votes. Voting does not immediately change a decision — it gives ExCo an idea whether to relook at it." current="decisions">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="mb-8 border-l-4 border-emerald-600 pl-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                SAPRF Governance
            </p>
            <h1 class="mt-1 text-3xl sm:text-4xl font-bold text-stone-900 dark:text-stone-100">
                Decision Register
            </h1>
            <p class="mt-3 max-w-2xl text-sm sm:text-base text-stone-600 dark:text-stone-400">
                Committee decisions published for members. Only active paid-up members may vote
                (👍 For, 👎 Against, or 🤷 Neutral). One anonymous vote each — be careful how you use yours.
            </p>
            <p class="mt-2 max-w-2xl text-sm text-stone-500">
                Voting does not immediately change a decision; it gives ExCo an idea of whether they need to relook at it.
                The system only records that you voted — never which option you chose.
            </p>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {{ session('error') }}
            </div>
        @endif

        <div class="space-y-5">
            @forelse ($decisions as $decision)
                @php($r = $reactions[$decision->id])
                <article
                    id="decision-{{ $decision->id }}"
                    class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6"
                >
                    <header class="mb-4">
                        <h2 class="text-xl font-semibold tracking-tight text-stone-900">
                            {{ $decision->title }}
                        </h2>
                        <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-stone-500">
                            @if ($decision->reference)
                                <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 font-medium text-stone-600">
                                    {{ $decision->reference }}
                                </span>
                            @endif
                            <span>Published {{ $decision->published_at->timezone(config('app.timezone'))->format('d M Y') }}</span>
                        </p>
                    </header>

                    <div class="whitespace-pre-wrap text-sm leading-relaxed text-stone-700">{{ $decision->body }}</div>

                    <div class="mt-5 space-y-3 border-t border-stone-100 pt-5">
                        @if ($r['show_split'])
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-medium text-stone-700">
                                    {{ $r['total'] }} {{ $r['total'] === 1 ? 'reaction' : 'reactions' }}
                                </p>
                                <p class="text-xs text-stone-500">
                                    <span class="text-emerald-700">{{ $r['for_percent'] }}% for</span>
                                    <span class="mx-1 text-stone-300">·</span>
                                    <span class="text-stone-600">{{ $r['neutral_percent'] }}% neutral</span>
                                    <span class="mx-1 text-stone-300">·</span>
                                    <span class="text-red-700">{{ $r['against_percent'] }}% against</span>
                                </p>
                            </div>
                            <div class="flex h-2.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                                @if ($r['for_percent'] > 0)
                                    <div class="bg-emerald-500 transition-all" style="width: {{ $r['for_percent'] }}%"></div>
                                @endif
                                @if ($r['neutral_percent'] > 0)
                                    <div class="bg-stone-400 transition-all" style="width: {{ $r['neutral_percent'] }}%"></div>
                                @endif
                                @if ($r['against_percent'] > 0)
                                    <div class="bg-red-500 transition-all" style="width: {{ $r['against_percent'] }}%"></div>
                                @endif
                            </div>
                        @elseif (! $r['has_enough'])
                            <p class="text-sm text-stone-500">
                                Not enough reactions yet
                                <span class="font-medium text-stone-600">({{ $r['total'] }} of {{ \App\Models\Decision::MIN_REACTIONS_FOR_RESULTS }})</span>
                            </p>
                            <div class="h-2.5 rounded-full bg-stone-100" aria-hidden="true"></div>
                        @else
                            <p class="text-sm text-stone-500">
                                {{ $r['total'] }} reactions · results shown after you react
                            </p>
                            <div class="h-2.5 rounded-full bg-stone-100" aria-hidden="true"></div>
                        @endif

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            @if ($r['can_react'])
                                <form method="POST" action="{{ route('decisions.react', $decision) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="reaction" value="like">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-100"
                                        aria-label="Thumbs up — for">
                                        <span class="text-lg leading-none" aria-hidden="true">👍</span>
                                        For
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('decisions.react', $decision) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="reaction" value="neutral">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-xl border border-stone-200 bg-stone-50 px-4 py-2.5 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-300 hover:bg-stone-100"
                                        aria-label="Shrug — neutral">
                                        <span class="text-lg leading-none" aria-hidden="true">🤷</span>
                                        Neutral
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('decisions.react', $decision) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="reaction" value="dislike">
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 shadow-sm transition hover:border-red-300 hover:bg-red-100"
                                        aria-label="Thumbs down — against">
                                        <span class="text-lg leading-none" aria-hidden="true">👎</span>
                                        Against
                                    </button>
                                </form>
                            @elseif ($r['has_reacted'])
                                <p class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800">
                                    <span aria-hidden="true">✓</span> You have reacted
                                </p>
                            @elseif (! $r['window_open'])
                                <p class="text-sm text-stone-500">Feedback window closed. Final tally stays on the register.</p>
                            @elseif ($r['guest'])
                                <a href="{{ route('login') }}"
                                   class="inline-flex items-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800">
                                    Log in to vote
                                </a>
                            @elseif ($r['not_active_member'])
                                <p class="text-sm text-stone-500">Only active paid-up members may vote.</p>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 py-14 text-center">
                    <p class="text-base font-semibold text-stone-800">No decisions published yet</p>
                    <p class="mt-2 text-sm text-stone-500">When the committee publishes a decision, it will appear here.</p>
                </div>
            @endforelse
        </div>

        @if ($decisions->hasPages())
            <div class="mt-8 flex justify-center">
                {{ $decisions->links() }}
            </div>
        @endif
    </div>
</x-layouts.public>
