@php
    use App\Enums\MatchStage;

    $user = auth()->user();
    $canChangeDirector = $user?->can('changeDirector', $match) ?? false;
    $canUpdate = $user?->can('update', $match) ?? false;
    $directorDisplay = $match->match_director ?: $match->creator?->name;
    $disciplineLabel = $match->match_type ?: ($match->series ?: '—');
    $levelLabel = \Illuminate\Support\Str::title($match->series_level ?? '');
    $venueLabel = trim(implode(', ', array_filter([$match->venue_name, $match->venue_location ?: $match->city, $match->province?->name])), ', ');
    $statusMeta = match ($match->status) {
        'open' => ['Open for entries', 'bg-emerald-100 text-emerald-800 ring-emerald-200'],
        'closed' => ['Entries closed', 'bg-stone-100 text-stone-700 ring-stone-200'],
        'completed' => ['Completed', 'bg-sky-100 text-sky-800 ring-sky-200'],
        'cancelled' => ['Cancelled', 'bg-red-100 text-red-700 ring-red-200'],
        default => ['Draft', 'bg-amber-100 text-amber-800 ring-amber-200'],
    };
@endphp

<div class="space-y-6">
    {{-- Header: name, badges, meta, action buttons --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <div class="text-xs text-stone-500">
                <a href="{{ route('matches.index') }}" class="hover:text-emerald-700" wire:navigate>My matches</a>
                <span class="mx-1">/</span>
                <span class="text-stone-700">{{ $match->name }}</span>
            </div>
            <h1 class="mt-1 font-heading text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">
                {{ $match->name }}
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-600">
                <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-semibold text-sky-800 ring-1 ring-sky-200">
                    {{ $disciplineLabel }}
                </span>
                @if($levelLabel)
                    <span class="text-stone-500">{{ $levelLabel }}</span>
                @endif
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $statusMeta[1] }}">
                    {{ $statusMeta[0] }}
                </span>
                @if($venueLabel)
                    <span>·</span>
                    <span>{{ $venueLabel }}</span>
                @endif
                @if($match->match_date)
                    <span>·</span>
                    <span>{{ $match->formatted_date }}</span>
                @endif
                @if($directorDisplay)
                    <span>·</span>
                    <span>MD: {{ $directorDisplay }}</span>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2" x-data="{ moreOpen: false }" @click.outside="moreOpen = false">
            @if($match->published && $match->status !== 'draft')
                <a href="{{ route('events.show', $match) }}"
                   target="_blank"
                   rel="noopener"
                   class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50">
                    <flux:icon.arrow-top-right-on-square class="size-4" />
                    View public page
                </a>
            @endif

            <div class="relative">
                <button type="button"
                        @click="moreOpen = !moreOpen"
                        class="inline-flex items-center gap-1 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50">
                    More
                    <flux:icon.chevron-down class="size-4" />
                </button>
                <div x-show="moreOpen" x-cloak x-transition
                     class="absolute right-0 top-full z-20 mt-1 w-56 overflow-hidden rounded-lg border border-stone-200 bg-white py-1 shadow-xl">
                    @if($canUpdate)
                        <a href="{{ route('matches.settings', $match) }}" wire:navigate
                           class="flex items-center gap-2 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">
                            <flux:icon.pencil-square class="size-4 text-stone-400" />
                            Edit match details
                        </a>
                        <a href="{{ route('matches.export-impact-scoring', $match) }}"
                           class="flex items-center gap-2 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">
                            <flux:icon.arrow-down-tray class="size-4 text-stone-400" />
                            Download entry list (CSV)
                        </a>
                    @endif
                    @if($canChangeDirector)
                        <div class="my-1 border-t border-stone-100"></div>
                        <a href="{{ route('matches.settings', $match) }}#change-director" wire:navigate
                           class="flex items-center gap-2 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">
                            <flux:icon.user class="size-4 text-stone-400" />
                            Change director (Exco)
                        </a>
                    @endif
                    @if($canUpdate && $match->status !== 'cancelled')
                        <div class="my-1 border-t border-stone-100"></div>
                        <a href="{{ route('matches.settings', $match) }}#cancel"
                           wire:navigate
                           class="flex items-center gap-2 px-3 py-2 text-sm text-red-700 hover:bg-red-50">
                            <flux:icon.exclamation-triangle class="size-4 text-red-500" />
                            Cancel match
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Stage stepper --}}
    <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($stageContext['steps'] as $step)
                @php
                    $isCurrent = $step['current'];
                    $isDone = $step['done'];
                    $cellClass = 'px-4 py-3 text-sm border-r border-stone-200 last:border-r-0';
                    if ($isCurrent) { $cellClass .= ' bg-amber-50'; }
                @endphp
                <div class="{{ $cellClass }}">
                    <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide {{ $isCurrent ? 'text-amber-800' : ($isDone ? 'text-emerald-700' : 'text-stone-500') }}">
                        @if($isDone)
                            <flux:icon.check class="size-3.5" />
                        @elseif($isCurrent)
                            <span class="size-2 rounded-full bg-amber-500"></span>
                        @else
                            <span class="size-2 rounded-full bg-stone-300"></span>
                        @endif
                        {{ $step['label'] }}
                    </div>
                    @if($step['detail'])
                        <div class="mt-0.5 text-xs text-stone-500">{{ $step['detail'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Next-action banner --}}
    @if($next = $stageContext['next'])
        @php
            $nextUrl = $next['route'] ? route($next['route'], $next['params']) : null;
            $bannerBg = match ($stageContext['stage']) {
                MatchStage::Draft => 'bg-amber-50 border-amber-200',
                MatchStage::EntriesOpen, MatchStage::EntriesClosed => 'bg-amber-50 border-amber-200',
                MatchStage::Scores => 'bg-sky-50 border-sky-200',
                MatchStage::Results => 'bg-amber-50 border-amber-200',
                MatchStage::Payout => 'bg-emerald-50 border-emerald-200',
            };
        @endphp
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border px-5 py-3 {{ $bannerBg }}">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-stone-900">Next: {{ $next['label'] }}</p>
                @if($next['detail'])
                    <p class="mt-0.5 text-xs text-stone-600">{{ $next['detail'] }}</p>
                @endif
            </div>
            @if($nextUrl)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @if(! empty($next['secondary']['route']))
                        <a href="{{ route($next['secondary']['route'], $next['secondary']['params']) }}"
                           wire:navigate
                           class="inline-flex items-center rounded-md border border-stone-300 bg-white px-3.5 py-2 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50">
                            {{ $next['secondary']['label'] }}
                        </a>
                    @endif
                    {{-- Every next-action route is a plain GET; the actual payout POST
                         lives on its own form page. `matches.export-impact-scoring`
                         is excluded from wire:navigate because it streams a CSV. --}}
                    <a href="{{ $nextUrl }}"
                       @if(! in_array($next['route'], ['matches.export-impact-scoring'])) wire:navigate @endif
                       class="inline-flex items-center rounded-md px-3.5 py-2 text-sm font-semibold shadow-sm {{ $next['variant'] === 'ghost' ? 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' : 'bg-emerald-600 text-white hover:bg-emerald-700' }}">
                        {{ $next['label'] }}
                    </a>
                </div>
            @endif
        </div>
    @endif

    {{-- Tab bar --}}
    <div class="border-b border-stone-200">
        <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Match workspace tabs">
            @foreach($tabs as $tab)
                @php
                    $isActive = $tab['key'] === $activeTab;
                @endphp
                <a href="{{ route($tab['route'], $match) }}"
                   wire:navigate
                   class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition {{ $isActive ? 'border-emerald-600 text-emerald-700' : 'border-transparent text-stone-500 hover:border-stone-300 hover:text-stone-700' }}">
                    {{ $tab['label'] }}
                    @if($tab['count'] !== null)
                        <span class="ml-1 inline-block min-w-[1.25rem] rounded-full px-1.5 py-0.5 text-center text-xs font-medium {{ $isActive ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">
                            {{ $tab['count'] }}
                        </span>
                    @endif
                </a>
            @endforeach
        </nav>
    </div>
</div>
