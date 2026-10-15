<?php

use App\Jobs\ProcessMediaItem;
use App\Models\MediaAlbum;
use App\Models\MediaItem;
use App\Models\NationalTeam;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
});

// ── Public /media ──────────────────────────────────────────────────────

it('/media returns 200 for guests and lists published albums only', function () {
    $publishedAlbum = MediaAlbum::create([
        'title' => 'Published album', 'category' => 'team_sa', 'is_published' => true,
    ]);
    $draftAlbum = MediaAlbum::create([
        'title' => 'Draft album', 'category' => 'nationals', 'is_published' => false,
    ]);

    $response = $this->get('/media');

    $response->assertOk();
    $response->assertSee('Published album');
    $response->assertDontSee('Draft album');
});

it('/media filters albums by ?category=', function () {
    MediaAlbum::create(['title' => 'Team SA photos', 'category' => 'team_sa', 'is_published' => true]);
    MediaAlbum::create(['title' => 'Nationals photos', 'category' => 'nationals', 'is_published' => true]);

    $this->get('/media?category=team_sa')
        ->assertOk()
        ->assertSee('Team SA photos')
        ->assertDontSee('Nationals photos');
});

it('/media/{slug} 404s for unpublished albums even if the slug is correct', function () {
    MediaAlbum::create([
        'title' => 'Hidden', 'slug' => 'hidden-album', 'category' => 'other', 'is_published' => false,
    ]);

    $this->get('/media/hidden-album')->assertNotFound();
});

it('/media/{slug} shows the album header for a published album', function () {
    $album = MediaAlbum::create([
        'title' => 'Open album', 'slug' => 'open-album', 'category' => 'team_sa',
        'is_published' => true, 'venue_label' => 'Lipan, Texas',
    ]);

    $this->get('/media/open-album')
        ->assertOk()
        ->assertSee('Open album')
        ->assertSee('Lipan, Texas');
});

// ── Single-feature invariant ──────────────────────────────────────────

it('only one media item can be featured on the homepage at any time', function () {
    $album = MediaAlbum::create([
        'title' => 'A', 'category' => 'team_sa', 'is_published' => true,
    ]);
    $first = MediaItem::create([
        'media_album_id' => $album->id, 'path' => 'a/1.jpg', 'alt' => 'a',
        'is_home_feature' => true,
    ]);
    $second = MediaItem::create([
        'media_album_id' => $album->id, 'path' => 'a/2.jpg', 'alt' => 'b',
    ]);

    $second->featureOnHomepage();

    expect($second->fresh()->is_home_feature)->toBeTrue();
    expect($first->fresh()->is_home_feature)->toBeFalse();
    expect(MediaItem::where('is_home_feature', true)->count())->toBe(1);
});

it('featuring an item busts the home.feature cache', function () {
    $album = MediaAlbum::create(['title' => 'A', 'category' => 'team_sa', 'is_published' => true]);
    $item = MediaItem::create([
        'media_album_id' => $album->id, 'path' => 'a/1.jpg', 'alt' => 'a',
    ]);

    Cache::put(MediaItem::HOME_FEATURE_CACHE_KEY, 'stale', 60);
    $item->featureOnHomepage();

    expect(Cache::get(MediaItem::HOME_FEATURE_CACHE_KEY))->toBeNull();
});

// ── Homepage band fallback order ──────────────────────────────────────

it('the home feature band hides itself when there is nothing to show', function () {
    Cache::flush();
    $response = $this->get('/');

    $response->assertOk();
    // The band's label pill is never emitted when $mode === null.
    $response->assertDontSee('Team South Africa</span>', false);
});

it('the home feature band uses the latest team cover when no item is featured', function () {
    Cache::flush();

    $album = MediaAlbum::create(['title' => 'T', 'category' => 'team_sa', 'is_published' => true]);
    $cover = MediaItem::create([
        'media_album_id' => $album->id, 'path' => 'team/cover.jpg', 'alt' => 'c',
    ]);
    NationalTeam::create([
        'year' => 2026,
        'championship_name' => '2026 IPRF World Championship (Centrefire)',
        'discipline' => 'centrefire',
        'cover_media_item_id' => $cover->id,
        'city' => 'Lipan, Texas',
    ]);

    $response = $this->get('/');
    $response->assertOk();
    // "Meet the team" is the default primary CTA when the fallback kicks in.
    $response->assertSee('Meet the team');
});

it('the home feature band prefers a featured item over the team-cover fallback', function () {
    Cache::flush();

    $album = MediaAlbum::create(['title' => 'A', 'category' => 'team_sa', 'is_published' => true]);
    $teamCover = MediaItem::create([
        'media_album_id' => $album->id, 'path' => 'a/cover.jpg', 'alt' => 'c',
    ]);
    NationalTeam::create([
        'year' => 2026, 'championship_name' => '2026 Worlds',
        'discipline' => 'centrefire',
        'cover_media_item_id' => $teamCover->id,
    ]);

    $featured = MediaItem::create([
        'media_album_id' => $album->id, 'path' => 'a/featured.jpg', 'alt' => 'f',
        'home_label' => 'Feature!', 'home_headline' => 'Hand-picked headline',
        'home_summary' => 'The admin pinned this specific photo.',
        'home_link_type' => 'sa_team',
    ]);
    $featured->featureOnHomepage();

    $response = $this->get('/');
    $response->assertOk();
    $response->assertSee('Hand-picked headline');
    $response->assertSee('Feature!');
});

it('a featured item inside an unpublished album is ignored by the home band', function () {
    Cache::flush();

    $draft = MediaAlbum::create(['title' => 'Draft', 'category' => 'team_sa', 'is_published' => false]);
    $item = MediaItem::create([
        'media_album_id' => $draft->id, 'path' => 'd/1.jpg', 'alt' => 'd',
        'home_label' => 'Should not show', 'home_headline' => 'Should not show headline',
    ]);
    $item->featureOnHomepage();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Should not show headline');
});

// ── Admin guard ────────────────────────────────────────────────────────

it('guests are redirected to login when hitting the admin media routes', function () {
    $this->get('/admin/media/albums')
        ->assertRedirect('/login');
    $this->get('/admin/media/albums/create')
        ->assertRedirect('/login');
});

it('plain members get 403 on admin media routes', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get('/admin/media/albums')
        ->assertForbidden();
});

// ── Image processing job ───────────────────────────────────────────────

it('ProcessMediaItem writes WebP + JPEG variants and marks the item processed', function () {
    Storage::fake('public');

    $album = MediaAlbum::create(['title' => 'J', 'category' => 'team_sa', 'is_published' => true]);

    // Store a real test image on the fake disk.
    $file = UploadedFile::fake()->image('team.jpg', 1200, 800);
    $path = $file->storeAs('media/'.$album->id, 'team.jpg', 'public');

    $item = MediaItem::create([
        'media_album_id' => $album->id,
        'disk' => 'public',
        'path' => $path,
        'alt' => 'Team photo',
    ]);

    (new ProcessMediaItem($item))->handle();

    $item->refresh();
    expect($item->processed_at)->not->toBeNull();
    expect($item->width)->toBe(1200);
    expect($item->height)->toBe(800);
    expect($item->variants)->toBeArray();

    foreach ([480, 960, 1600] as $w) {
        expect($item->variants[(string) $w]['jpg'])->toBeString();
        expect($item->variants[(string) $w]['webp'])->toBeString();
        Storage::disk('public')->assertExists($item->variants[(string) $w]['jpg']);
        Storage::disk('public')->assertExists($item->variants[(string) $w]['webp']);
    }
});
