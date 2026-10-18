@php
    $ogImage = $album->cover?->variantUrl(1600, 'jpg')
        ?? $album->cover?->originalUrl();

    $lightboxItems = $items->map(fn ($i) => [
        'id' => $i->id,
        'src' => $i->variantUrl(1600, 'jpg') ?? $i->originalUrl(),
        'webp' => $i->variantUrl(1600, 'webp'),
        'alt' => (string) ($i->alt ?? ''),
        'caption' => (string) ($i->caption ?? ''),
        'credit' => (string) ($i->credit ?? ''),
        'width' => $i->width,
        'height' => $i->height,
    ])->values();
@endphp

<x-layouts.public
    :title="$album->title.' — SAPRF Media'"
    :description="'Photos from '.$album->title.'.'"
    :image="$ogImage"
    current="media"
    :forcePublic="true">

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-10 sm:py-14"
          x-data="mediaLightbox({ items: {{ Illuminate\Support\Js::from($lightboxItems) }} })">

        {{-- Header --}}
        <div class="mb-6">
            <a href="{{ route('media.index') }}" class="text-sm text-stone-500 hover:text-emerald-700 inline-flex items-center gap-1 group">
                <svg class="size-4 group-hover:-translate-x-0.5 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                All albums
            </a>
            <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">{{ $album->categoryLabel() }}</div>
                    <h1 class="font-heading font-extrabold text-4xl sm:text-5xl text-stone-900 mt-1">{{ $album->title }}</h1>
                    <p class="text-sm text-stone-500 mt-1">
                        @if($album->venue_label){{ $album->venue_label }}@endif
                        @if($album->venue_label && $album->event_date) · @endif
                        @if($album->event_date){{ $album->event_date->format('j F Y') }}@endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($album->matchEvent)
                        <a href="{{ route('events.show', ['match' => $album->matchEvent]) }}"
                           class="inline-flex items-center min-h-[44px] px-5 rounded-lg border border-stone-300 bg-white text-sm font-semibold text-stone-700 hover:border-emerald-600 hover:text-emerald-700">
                            View results
                        </a>
                    @endif
                    @if($album->nationalTeam)
                        <a href="{{ route('sa-team.year', ['year' => $album->nationalTeam->year]) }}"
                           class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-sm font-semibold text-white hover:bg-emerald-800">
                            View {{ $album->nationalTeam->year }} team
                        </a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Photo grid --}}
        @if($items->isEmpty())
            <div class="mt-8 bg-white border border-stone-200 rounded-2xl p-10 text-center">
                <p class="text-stone-500">No photos in this album yet.</p>
            </div>
        @else
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr));">
                @foreach($items as $i => $item)
                    <button type="button"
                            @click="open({{ $i }})"
                            class="group block bg-stone-100 rounded-xl overflow-hidden focus:outline-none focus:ring-2 focus:ring-emerald-500 aspect-[4/3]">
                        {{-- Caption suppressed: this is a lightbox-trigger thumbnail; the
                             full caption + credit are rendered inside the lightbox dialog. --}}
                        <x-media-picture :item="$item" :showCaption="false"
                                         sizes="(min-width: 1024px) 260px, (min-width: 640px) 50vw, 100vw"
                                         class="w-full h-full"
                                         imgClass="w-full h-full object-cover group-hover:scale-[1.02] transition" />
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Lightbox --}}
        <div x-show="isOpen"
             x-cloak
             @keydown.escape.window="close"
             @keydown.arrow-left.window="prev"
             @keydown.arrow-right.window="next"
             @keydown.tab.prevent="trapTab($event)"
             class="fixed inset-0 z-[100] bg-stone-950/95 flex flex-col"
             role="dialog"
             aria-modal="true"
             aria-labelledby="lightbox-caption"
             x-ref="dialog"
             tabindex="-1">
            <div class="flex items-center justify-between gap-4 px-4 py-3 text-stone-200 text-sm">
                <span x-text="`${index + 1} / ${items.length}`" class="font-mono"></span>
                <button type="button" @click="close" aria-label="Close" class="p-2 rounded-lg hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="relative flex-1 flex items-center justify-center px-2 sm:px-6">
                <button type="button" @click="prev" aria-label="Previous photo"
                        class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 p-3 rounded-full bg-white/10 hover:bg-white/20 text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </button>

                <img :src="current.src" :alt="current.alt"
                     class="max-h-[80vh] max-w-full object-contain">

                <button type="button" @click="next" aria-label="Next photo"
                        class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 p-3 rounded-full bg-white/10 hover:bg-white/20 text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
            </div>

            <div id="lightbox-caption" class="px-6 py-4 text-center text-stone-200 text-sm">
                <template x-if="current.caption">
                    <p x-text="current.caption"></p>
                </template>
                <template x-if="current.credit">
                    <p class="mt-1 text-stone-400 text-xs">Photo: <span x-text="current.credit"></span></p>
                </template>
            </div>
        </div>
    </main>

    @push('scripts')
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('mediaLightbox', (config) => ({
            items: config.items,
            index: 0,
            isOpen: false,

            get current() {
                return this.items[this.index] || {src: '', alt: '', caption: '', credit: ''};
            },

            open(i) {
                this.lastFocus = document.activeElement;
                this.index = i;
                this.isOpen = true;
                document.body.style.overflow = 'hidden';
                this.$nextTick(() => this.$refs.dialog?.focus());
            },
            close() {
                this.isOpen = false;
                document.body.style.overflow = '';
                if (this.lastFocus && typeof this.lastFocus.focus === 'function') {
                    this.lastFocus.focus();
                }
            },
            prev() {
                if (!this.items.length) return;
                this.index = (this.index - 1 + this.items.length) % this.items.length;
            },
            next() {
                if (!this.items.length) return;
                this.index = (this.index + 1) % this.items.length;
            },
            trapTab(event) {
                // Simple focus trap: cycle focus between the focusable
                // elements inside the dialog. Prevents Tab from leaking
                // into the (hidden) page behind.
                const dialog = this.$refs.dialog;
                if (!dialog) return;
                const focusables = dialog.querySelectorAll(
                    'a[href], button:not([disabled]), [tabindex="0"], input, select, textarea'
                );
                if (!focusables.length) return;
                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    first.focus();
                } else {
                    // Let the browser advance focus naturally within trap.
                    const list = Array.from(focusables);
                    const idx = list.indexOf(document.activeElement);
                    const nextIdx = event.shiftKey ? idx - 1 : idx + 1;
                    (list[nextIdx] || first).focus();
                }
            },
        }));
    });
    </script>
    @endpush
</x-layouts.public>
