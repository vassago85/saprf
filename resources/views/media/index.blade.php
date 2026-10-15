<x-layouts.public
    title="Media — SAPRF"
    description="Photos from national championships, Team South Africa and SAPRF matches around the country."
    current="media"
    :forcePublic="true">

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-10 sm:py-14">

        <h1 class="font-heading font-extrabold text-4xl sm:text-5xl text-stone-900">Media</h1>
        <p class="mt-2 text-stone-500 max-w-xl text-base sm:text-lg">
            Photos from national championships, Team South Africa and SAPRF matches around the country.
        </p>

        {{-- =========================================================
             FEATURED CARD
             ========================================================= --}}
        @if($featured)
            <a href="{{ route('media.show', ['album' => $featured->album->slug]) }}"
               class="mt-10 block bg-emerald-950 rounded-3xl overflow-hidden text-white hover:ring-2 hover:ring-emerald-500 transition grid"
               style="grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr));">
                <div class="min-h-[260px] sm:min-h-[340px] bg-stone-800 relative">
                    <x-media-picture :item="$featured" sizes="(min-width: 768px) 50vw, 100vw"
                                     class="absolute inset-0"
                                     imgClass="absolute inset-0 w-full h-full object-cover" />
                </div>
                <div class="p-8 sm:p-10 flex flex-col justify-center gap-3">
                    <span class="self-start inline-block px-3 py-1.5 rounded-full bg-amber-400/15 text-amber-300 text-[11px] font-bold uppercase tracking-[0.15em]">
                        Featured
                    </span>
                    <h2 class="font-heading font-extrabold text-3xl sm:text-4xl leading-tight">
                        {{ $featured->home_headline ?? $featured->album->title }}
                    </h2>
                    @if($featured->home_summary)
                        <p class="text-stone-200 text-base max-w-prose">{{ $featured->home_summary }}</p>
                    @endif
                    <div class="text-xs text-stone-400 mt-1">
                        {{ $featured->album->items_count ?? $featured->album->items()->count() }} photos
                        @if($featured->credit)
                            · Photo credit: {{ $featured->credit }}
                        @endif
                    </div>
                </div>
            </a>
        @endif

        {{-- =========================================================
             CATEGORY CHIPS — bound to the URL
             ========================================================= --}}
        <nav class="mt-10 flex flex-wrap gap-2.5" aria-label="Category">
            @php $allActive = $activeCategory === null; @endphp
            <a href="{{ route('media.index') }}"
               aria-current="{{ $allActive ? 'page' : 'false' }}"
               class="inline-flex items-center min-h-[44px] px-5 rounded-full border text-sm font-semibold transition
                      {{ $allActive
                         ? 'bg-emerald-700 border-emerald-700 text-white'
                         : 'bg-white border-stone-300 text-stone-700 hover:border-emerald-600 hover:text-emerald-700' }}">
                All
            </a>
            @foreach($categories as $catKey => $catLabel)
                @php $active = $activeCategory === $catKey; @endphp
                <a href="{{ route('media.index', ['category' => $catKey]) }}"
                   aria-current="{{ $active ? 'page' : 'false' }}"
                   class="inline-flex items-center min-h-[44px] px-5 rounded-full border text-sm font-semibold transition
                          {{ $active
                             ? 'bg-emerald-700 border-emerald-700 text-white'
                             : 'bg-white border-stone-300 text-stone-700 hover:border-emerald-600 hover:text-emerald-700' }}">
                    {{ $catLabel }}
                </a>
            @endforeach
        </nav>

        {{-- =========================================================
             ALBUM GRID
             ========================================================= --}}
        @if($albums->isEmpty())
            <div class="mt-10 bg-white border border-stone-200 rounded-2xl p-10 text-center">
                <p class="text-stone-500">No albums in this category yet. Check back soon.</p>
            </div>
        @else
            <div class="mt-8 grid gap-6" style="grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr));">
                @foreach($albums as $album)
                    <a href="{{ route('media.show', ['album' => $album->slug]) }}"
                       class="group block bg-white border border-stone-200 rounded-2xl overflow-hidden hover:border-emerald-600 transition">
                        <div class="h-52 bg-stone-800 relative">
                            @if($album->cover)
                                <x-media-picture :item="$album->cover" sizes="320px" class="h-52"
                                                 imgClass="w-full h-52 object-cover group-hover:scale-[1.02] transition" />
                            @else
                                <div class="w-full h-52 bg-gradient-to-br from-stone-700 to-stone-900 flex items-center justify-center text-stone-600 text-xs uppercase tracking-wider">
                                    Album cover
                                </div>
                            @endif
                        </div>
                        <div class="p-5">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">{{ $album->categoryLabel() }}</div>
                            <div class="mt-1 font-semibold text-stone-900 text-base">{{ $album->title }}</div>
                            <div class="mt-1 text-sm text-stone-500">
                                @if($album->venue_label){{ $album->venue_label }}@endif
                                @if($album->venue_label && $album->event_date) · @endif
                                @if($album->event_date){{ $album->event_date->format('M Y') }}@endif
                                @if(($album->venue_label || $album->event_date) && $album->items_count) · @endif
                                @if($album->items_count){{ $album->items_count }} photos @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </main>
</x-layouts.public>
