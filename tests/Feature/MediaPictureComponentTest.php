<?php

use App\Models\MediaAlbum;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Blade;

/*
 * The <x-media-picture> component is embedded all over the platform —
 * public hero, admin album picker, team-year cards, media gallery
 * thumbnails. Many of those callers wrap it in a fixed-height or
 * fixed-aspect-ratio container, where its default <figcaption> would
 * overflow the box and visually collide with the surrounding card.
 *
 * The `showCaption` prop (default true) lets a caller opt out of the
 * figcaption without touching the alt text or losing the picture / srcset.
 * These tests pin that contract so no future refactor silently rips the
 * opt-out away.
 */

function makeMediaItemWithCaption(string $caption = 'Team South Africa · 2026 IPRF World Championship'): MediaItem
{
    $album = MediaAlbum::create([
        'title' => 'Component test album',
        'slug' => 'component-test-album-'.uniqid(),
        'category' => 'team_sa',
        'is_published' => true,
    ]);

    return MediaItem::create([
        'media_album_id' => $album->id,
        'disk' => 'public',
        'path' => 'media/'.$album->id.'/test.jpg',
        'alt' => 'A South African team photo',
        'caption' => $caption,
        'credit' => 'On Target Africa',
        'sort_order' => 1,
    ]);
}

it('x-media-picture renders a <figcaption> by default when the item has a caption or credit', function () {
    $item = makeMediaItemWithCaption();

    // Blade::render instantiates the component the same way <x-media-picture>
    // in a real template does — populating $slot etc. — unlike a bare
    // view('components.media-picture', ...) call which leaves $slot undefined.
    $html = Blade::render('<x-media-picture :item="$item" />', ['item' => $item]);

    // The <picture> element itself is always present.
    expect($html)->toContain('<picture>');
    // The alt text comes from item->alt and must survive regardless of
    // the caption toggle.
    expect($html)->toContain('alt="A South African team photo"');
    // Default behaviour: the figcaption is rendered with the caption + credit.
    expect($html)->toContain('<figcaption');
    expect($html)->toContain('Team South Africa · 2026 IPRF World Championship');
    expect($html)->toContain('Photo: On Target Africa');
});

it('x-media-picture omits the <figcaption> entirely when :showCaption="false"', function () {
    $item = makeMediaItemWithCaption();

    $html = Blade::render(
        '<x-media-picture :item="$item" :showCaption="false" />',
        ['item' => $item],
    );

    // The <picture> element is still rendered — we only suppressed the
    // caption, not the image.
    expect($html)->toContain('<picture>');
    expect($html)->toContain('alt="A South African team photo"');

    // No <figcaption> tag, and no caption / credit text anywhere.
    expect($html)->not->toContain('<figcaption');
    expect($html)->not->toContain('Team South Africa · 2026 IPRF World Championship');
    expect($html)->not->toContain('Photo: On Target Africa');
});

it('x-media-picture renders no <figcaption> when the item has neither caption nor credit, regardless of showCaption', function () {
    // Guards the small-case path: even if the caller forgets to opt out,
    // an item with no caption/credit must not emit an empty <figcaption>.
    $album = MediaAlbum::create([
        'title' => 'Empty-caption album',
        'slug' => 'empty-caption-album-'.uniqid(),
        'category' => 'team_sa',
        'is_published' => true,
    ]);
    $item = MediaItem::create([
        'media_album_id' => $album->id,
        'disk' => 'public',
        'path' => 'media/'.$album->id.'/test.jpg',
        'alt' => 'Captionless image',
        'caption' => null,
        'credit' => null,
        'sort_order' => 1,
    ]);

    $html = Blade::render('<x-media-picture :item="$item" />', ['item' => $item]);

    expect($html)->toContain('<picture>');
    expect($html)->not->toContain('<figcaption');
});
