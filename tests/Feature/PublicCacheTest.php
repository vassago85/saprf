<?php

use App\Models\MediaAlbum;
use App\Models\MediaItem;
use App\Models\NationalTeam;
use App\Models\NationalTeamAppearance;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    seedRoles();
    Cache::flush();
});

/*
 * Regression harness for the "500 on the second request" bug.
 *
 * The public /sa-team, /media and home-feature band controllers USED to
 * wrap their query results in Cache::remember(). That worked on the first
 * request (cold cache -> runs the query, serialises the Eloquent
 * Collection into Redis). On the second request, PHP's unserialize()
 * handed back an __PHP_Incomplete_Class for the Collection and the first
 * method call (`$allAppearances->pluck('year')`) crashed with a 500.
 *
 * The fix is to NOT cache the raw Eloquent graph. These tests assert
 * exactly that — hit each public page twice back-to-back; both must
 * return 200. If anyone re-adds a Cache::remember() around a Collection
 * of models, these tests will catch it the moment the cache is primed.
 */

// ────────────────────────────────────────────────────────────────────────
// /sa-team
// ────────────────────────────────────────────────────────────────────────

it('/sa-team survives repeated requests for guests (no cached incomplete class)', function () {
    $this->get('/sa-team')->assertOk();
    $this->get('/sa-team')->assertOk();
    $this->get('/sa-team')->assertOk();
});

it('/sa-team survives repeated requests for authed users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/sa-team')->assertOk();
    $this->actingAs($user)->get('/sa-team')->assertOk();
});

it('/sa-team survives a write between two reads', function () {
    $this->get('/sa-team')->assertOk();

    NationalTeam::create([
        'year' => 2027,
        'championship_name' => '2027 Worlds',
        'discipline' => 'centrefire',
    ]);

    $this->get('/sa-team')->assertOk();
});

// ────────────────────────────────────────────────────────────────────────
// /media
// ────────────────────────────────────────────────────────────────────────

it('/media survives repeated requests at the same category', function () {
    $this->get('/media')->assertOk();
    $this->get('/media')->assertOk();
    $this->get('/media?category=team_sa')->assertOk();
    $this->get('/media?category=team_sa')->assertOk();
});

it('/media survives an album + item write between two reads', function () {
    $this->get('/media')->assertOk();

    $album = MediaAlbum::create([
        'title' => 'Fresh', 'category' => 'nationals', 'is_published' => true,
    ]);
    MediaItem::create([
        'media_album_id' => $album->id,
        'original_filename' => 'photo.jpg',
        'path' => 'media/'.$album->id.'/photo.jpg',
        'disk' => 'public',
    ]);

    $this->get('/media')->assertOk();
});

// ────────────────────────────────────────────────────────────────────────
// Home feature band (lives on every public landing-page response)
// ────────────────────────────────────────────────────────────────────────

it('the home feature band survives repeated landing-page requests', function () {
    $album = MediaAlbum::create([
        'title' => 'Band test', 'category' => 'team_sa', 'is_published' => true,
    ]);
    $item = MediaItem::create([
        'media_album_id' => $album->id,
        'original_filename' => 'cover.jpg',
        'path' => 'media/'.$album->id.'/cover.jpg',
        'disk' => 'public',
        'is_home_feature' => true,
        'home_label' => 'Team South Africa',
        'home_headline' => 'Flying the flag',
    ]);

    $this->get('/')->assertOk();
    $this->get('/')->assertOk();
    $this->get('/')->assertOk();
});

// ────────────────────────────────────────────────────────────────────────
// Belt-and-braces: no cache key for the removed caches should be written.
// If this ever fails it means someone has re-introduced the antipattern.
// ────────────────────────────────────────────────────────────────────────

it('does not populate the removed Eloquent-collection cache keys', function () {
    $this->get('/sa-team')->assertOk();
    $this->get('/media')->assertOk();
    $this->get('/')->assertOk();

    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeFalse();
    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeFalse();
    expect(Cache::has(MediaItem::HOME_FEATURE_CACHE_KEY))->toBeFalse();
});
