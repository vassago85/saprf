@props([
    'item',         // App\Models\MediaItem
    'sizes' => '100vw',
    'class' => '',
    'imgClass' => 'block w-full h-auto',
    'loading' => 'lazy',
    'alt' => null,  // Override item alt text if needed (e.g. decorative)
])

{{--
    Responsive picture element for a MediaItem.

    Uses WebP + JPEG srcsets written by ProcessMediaItem; falls back to the
    original full-resolution file when the job hasn't run yet. The caller
    passes `sizes` so the browser can pick the right variant for its viewport.
--}}

@php
    $webp = $item->srcset('webp');
    $jpg = $item->srcset('jpg');
    $fallback = $item->variantUrl(960, 'jpg') ?? $item->originalUrl();
    $altText = $alt !== null ? $alt : (string) ($item->alt ?? '');
@endphp

@if($fallback)
<figure class="{{ $class }}">
    <picture>
        @if($webp)
            <source type="image/webp" srcset="{{ $webp }}" sizes="{{ $sizes }}">
        @endif
        @if($jpg)
            <source type="image/jpeg" srcset="{{ $jpg }}" sizes="{{ $sizes }}">
        @endif
        <img
            src="{{ $fallback }}"
            alt="{{ $altText }}"
            @if($item->width) width="{{ $item->width }}" @endif
            @if($item->height) height="{{ $item->height }}" @endif
            loading="{{ $loading }}"
            decoding="async"
            class="{{ $imgClass }}">
    </picture>
    @if(($item->caption || $item->credit) && $slot->isNotEmpty() === false)
        <figcaption class="mt-2 text-xs text-stone-500">
            @if($item->caption)<span>{{ $item->caption }}</span>@endif
            @if($item->caption && $item->credit)<span class="mx-1 text-stone-300">·</span>@endif
            @if($item->credit)<span class="text-stone-400">Photo: {{ $item->credit }}</span>@endif
        </figcaption>
    @endif
    {{ $slot }}
</figure>
@endif
