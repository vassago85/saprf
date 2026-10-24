<?php

use App\Models\MediaItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('features the 2028 Worlds poster on the landing page', function () {
    Storage::fake('public');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $dir = storage_path('framework/testing');
    File::ensureDirectoryExists($dir);
    $path = $dir.DIRECTORY_SEPARATOR.'worlds-2028.png';
    File::put($path, $png);

    $this->artisan('saprf:feature-worlds-2028-poster', [
        '--poster' => $path,
        '--sync' => true,
    ])->assertSuccessful();

    $item = MediaItem::query()->where('is_home_feature', true)->first();

    expect($item)->not->toBeNull()
        ->and($item->home_headline)->toBe('South Africa will host the IPRF Centrefire World Championship')
        ->and($item->home_summary)->toBe('August 2028 in Mpumalanga.')
        ->and($item->width)->toBe(1)
        ->and($item->height)->toBe(1);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('South Africa will host the IPRF Centrefire World Championship', false)
        ->assertSee('August 2028 in Mpumalanga.', false)
        ->assertSee('aspect-ratio: 1/1', false);
});
