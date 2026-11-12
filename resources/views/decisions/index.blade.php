<x-layouts.public title="Decision Register — SAPRF" description="Published ExCo decisions and anonymous member reactions. Sentiment only — decisions stand as made." current="decisions">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="mb-8">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                SAPRF Governance
            </p>
            <h1 class="mt-1 text-3xl sm:text-4xl font-bold text-stone-900 dark:text-stone-100">
                Decision Register
            </h1>
            <p class="mt-3 max-w-2xl text-sm sm:text-base text-stone-600 dark:text-stone-400">
                Committee decisions published for members. Thumbs up, thumbs down, or Neutral show how members feel —
                the decision itself does not change because of the result. One vote each, anonymous —
                be careful how you use yours.
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

        @forelse ($decisions as $decision)
            @php($r = $reactions[$decision->id])
            <article class="mb-8 border-b border-stone-200 pb-8 last:border-0" id="decision-{{ $decision->id }}">
                <header class="mb-3">
                    <h2 class="text-xl font-semibold text-stone-900 dark:text-stone-100">
                        {{ $decision->title }}
                    </h2>
                    <p class="mt-1 text-xs text-stone-500">
                        @if ($decision->reference)
                            {{ $decision->reference }}
                            <span class="mx-1 text-stone-300">·</span>
                        @endif
                        Published {{ $decision->published_at->timezone(config('app.timezone'))->format('d M Y') }}
                    </p>
                </header>

                <div class="prose prose-stone prose-sm max-w-none whitespace-pre-wrap text-stone-700 dark:text-stone-300">{{ $decision->body }}</div>

                <div class="mt-5 space-y-3">
                    @if ($r['show_split'])
                        <p class="text-sm text-stone-600">
                            {{ $r['total'] }} {{ $r['total'] === 1 ? 'reaction' : 'reactions' }}
                            · {{ $r['for_percent'] }}% for
                            · {{ $r['against_percent'] }}% against
                            · {{ $r['neutral_percent'] }}% neutral
                        </p>
                        <div class="flex h-2 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                            @if ($r['for_percent'] > 0)
                                <div class="bg-emerald-500" style="width: {{ $r['for_percent'] }}%"></div>
                            @endif
                            @if ($r['neutral_percent'] > 0)
                                <div class="bg-stone-400" style="width: {{ $r['neutral_percent'] }}%"></div>
                            @endif
                            @if ($r['against_percent'] > 0)
                                <div class="bg-red-500" style="width: {{ $r['against_percent'] }}%"></div>
                            @endif
                        </div>
                    @elseif (! $r['has_enough'])
                        <p class="text-sm text-stone-500">
                            Not enough reactions yet ({{ $r['total'] }} of {{ \App\Models\Decision::MIN_REACTIONS_FOR_RESULTS }})
                        </p>
                        <div class="h-2 rounded-full bg-stone-200" aria-hidden="true"></div>
                    @else
                        <p class="text-sm text-stone-500">
                            {{ $r['total'] }} reactions · results shown after you react
                        </p>
                        <div class="h-2 rounded-full bg-stone-200" aria-hidden="true"></div>
                    @endif

                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        @if ($r['can_react'])
                            <form method="POST" action="{{ route('decisions.react', $decision) }}" class="inline">
                                @csrf
                                <input type="hidden" name="reaction" value="like">
                                <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100"
                                    aria-label="Thumbs up — for">
                                    <span class="text-lg leading-none" aria-hidden="true">👍</span>
                                    For
                                </button>
                            </form>
                            <form method="POST" action="{{ route('decisions.react', $decision) }}" class="inline">
                                @csrf
                                <input type="hidden" name="reaction" value="neutral">
                                <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl border border-stone-200 bg-stone-50 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-100"
                                    aria-label="Shrug — neutral">
                                    <span class="text-lg leading-none" aria-hidden="true">🤷</span>
                                    Neutral
                                </button>
                            </form>
                            <form method="POST" action="{{ route('decisions.react', $decision) }}" class="inline">
                                @csrf
                                <input type="hidden" name="reaction" value="dislike">
                                <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-800 hover:bg-red-100"
                                    aria-label="Thumbs down — against">
                                    <span class="text-lg leading-none" aria-hidden="true">👎</span>
                                    Against
                                </button>
                            </form>
                        @elseif ($r['has_reacted'])
                            <p class="text-sm font-medium text-emerald-700">✓ You have reacted</p>
                        @elseif (! $r['window_open'])
                            <p class="text-sm text-stone-500">Feedback window closed. Final tally stays on the register.</p>
                        @elseif ($r['guest'])
                            <a href="{{ route('login') }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                                Log in to react
                            </a>
                        @elseif ($r['not_active_member'])
                            <p class="text-sm text-stone-500">Only active paid-up members can react.</p>
                        @endif
                    </div>

                    <p class="text-xs text-stone-500">
                        To protect anonymity you may vote only once. The system does not record which
                        option you chose against your name — only that you voted, so you cannot vote twice.
                        Be careful how you use your vote.
                    </p>
                </div>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 py-12 text-center">
                <p class="text-sm font-medium text-stone-700">No decisions published yet.</p>
                <p class="mt-1 text-sm text-stone-500">When the committee publishes a decision, it will appear here.</p>
            </div>
        @endforelse
    </div>
</x-layouts.public>
