@if($mode === null)
    {{-- No photo to feature and no team cover to fall back on — band is
         hidden rather than rendering an empty frame. --}}
@else
<section class="relative overflow-hidden bg-stone-950 text-white">
    <div class="absolute inset-0 bg-gradient-to-br from-emerald-950/60 via-stone-900 to-stone-900"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-20">
        <div class="grid lg:grid-cols-12 gap-10 lg:gap-14 items-center">
            <div class="lg:col-span-5">
                <div class="inline-flex items-center gap-2 rounded-full bg-emerald-500/10 border border-emerald-400/20 px-4 py-1.5 mb-6">
                    <span class="size-2 rounded-full bg-amber-400"></span>
                    <span class="text-xs font-semibold text-emerald-300 uppercase tracking-wider">{{ $label }}</span>
                </div>

                <h2 class="font-heading text-3xl sm:text-4xl font-bold tracking-tight text-white leading-tight">
                    {{ $headline }}
                </h2>

                @if($summary)
                    <p class="mt-4 text-base sm:text-lg text-stone-300 leading-relaxed max-w-md">
                        {{ $summary }}
                    </p>
                @endif

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ $primaryUrl }}"
                       class="inline-flex items-center min-h-[48px] px-6 rounded-xl bg-emerald-700 text-white font-semibold hover:bg-emerald-800 transition shadow-sm">
                        {{ $primaryLabel }}
                    </a>
                    @if($secondaryUrl && $secondaryUrl !== $primaryUrl)
                        <a href="{{ $secondaryUrl }}"
                           class="inline-flex items-center min-h-[48px] px-6 rounded-xl border border-white/25 text-white font-semibold hover:bg-white/5 transition">
                            View gallery
                        </a>
                    @endif
                </div>
            </div>

            <div class="lg:col-span-7">
                <figure class="mx-auto max-w-[720px]">
                    <div class="rounded-2xl overflow-hidden ring-1 ring-white/10 shadow-2xl shadow-black/40 bg-stone-800 aspect-[16/9]">
                        <x-media-picture
                            :item="$item"
                            sizes="(min-width: 1024px) 720px, 100vw"
                            class="w-full h-full"
                            imgClass="w-full h-full object-cover"
                            loading="lazy" />
                    </div>
                    @if($caption)
                        <figcaption class="mt-3 text-xs text-stone-400 text-center">
                            {{ $caption }}
                        </figcaption>
                    @endif
                </figure>
            </div>
        </div>
    </div>
</section>
@endif
