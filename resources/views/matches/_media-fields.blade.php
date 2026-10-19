{{--
    Listing banner (wide crop) + optional full poster (vertical flyer).
    Pass $match on edit/settings; omit on create.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;
@endphp

<div class="sm:col-span-2 space-y-5">
    <div>
        <label for="banner" class="block text-sm font-medium text-stone-700 mb-1">
            Listing banner <span class="text-stone-400">(optional)</span>
        </label>
        @if ($match?->bannerUrl())
            <div class="mb-3 overflow-hidden rounded-lg border border-stone-200">
                <img src="{{ $match->bannerUrl() }}" alt="Current listing banner for {{ $match->name }}" class="h-32 w-full object-cover object-[center_28%]">
            </div>
            <label class="mb-3 inline-flex items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" name="remove_banner" value="1" class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                Remove current listing banner
            </label>
        @endif
        <input type="file" name="banner" id="banner" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-emerald-700 hover:file:bg-emerald-100" />
        <p class="mt-1 text-xs text-stone-400">
            Wide photo-only crop for event cards (~1200×500). Crop above any orange title bar on the flyer so the card chrome stays clean. JPG, PNG or WebP, max 4&nbsp;MB.
            @if ($match) Leave empty to keep the current banner. @endif
        </p>
        @error('banner') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="poster" class="block text-sm font-medium text-stone-700 mb-1">
            Full poster <span class="text-stone-400">(optional)</span>
        </label>
        @if ($match?->posterUrl())
            <div class="mb-3 overflow-hidden rounded-lg border border-stone-200 bg-stone-50 p-3 flex justify-center">
                <img src="{{ $match->posterUrl() }}" alt="Current poster for {{ $match->name }}" class="max-h-48 w-auto rounded shadow-sm">
            </div>
            <label class="mb-3 inline-flex items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" name="remove_poster" value="1" class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                Remove current poster
            </label>
        @endif
        <input type="file" name="poster" id="poster" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-emerald-700 hover:file:bg-emerald-100" />
        <p class="mt-1 text-xs text-stone-400">
            Full vertical flyer (logos, orange bar, venues). Shown intact on the match page. JPG, PNG or WebP, max 6&nbsp;MB.
            @if ($match) Leave empty to keep the current poster. @endif
        </p>
        @error('poster') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
