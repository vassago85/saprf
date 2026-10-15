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

// ────────────────────────────────────────────────────────────────────────
// /sa-team: cached for guests, invalidated on any team/appearance write
// ────────────────────────────────────────────────────────────────────────

it('caches the /sa-team dataset for guests under PUBLIC_PAGE_CACHE_KEY', function () {
    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeFalse();

    $this->get('/sa-team')->assertOk();

    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeTrue();
});

it('does NOT cache the /sa-team dataset for authed users (POPIA junior visibility differs per viewer)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/sa-team')->assertOk();

    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeFalse();
});

it('invalidates the /sa-team cache when a NationalTeamAppearance is saved', function () {
    $this->get('/sa-team')->assertOk();
    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeTrue();

    $shooter = User::factory()->create();
    NationalTeamAppearance::create([
        'user_id' => $shooter->id,
        'year' => 2026,
        'championship_name' => '2026 Worlds',
        'appeared_at' => '2026-09-23',
    ]);

    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeFalse();
});

it('invalidates the /sa-team cache when a NationalTeam is saved', function () {
    $this->get('/sa-team')->assertOk();
    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeTrue();

    NationalTeam::create([
        'year' => 2027,
        'championship_name' => '2027 Worlds',
        'discipline' => 'centrefire',
    ]);

    expect(Cache::has(NationalTeamAppearance::PUBLIC_PAGE_CACHE_KEY))->toBeFalse();
});

// ────────────────────────────────────────────────────────────────────────
// /media: cached per category, invalidated on album/item writes
// ────────────────────────────────────────────────────────────────────────

it('caches the /media index per category under media.index.<category>', function () {
    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeFalse();
    expect(Cache::has(MediaAlbum::indexCacheKey('team_sa')))->toBeFalse();

    $this->get('/media')->assertOk();
    $this->get('/media?category=team_sa')->assertOk();

    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeTrue();
    expect(Cache::has(MediaAlbum::indexCacheKey('team_sa')))->toBeTrue();
});

it('invalidates every /media cache key on any album write', function () {
    $this->get('/media')->assertOk();
    $this->get('/media?category=team_sa')->assertOk();
    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeTrue();
    expect(Cache::has(MediaAlbum::indexCacheKey('team_sa')))->toBeTrue();

    MediaAlbum::create([
        'title' => 'Fresh album',
        'category' => 'nationals',
        'is_published' => true,
    ]);

    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeFalse();
    expect(Cache::has(MediaAlbum::indexCacheKey('team_sa')))->toBeFalse();
});

it('invalidates the /media cache + home-feature cache when a MediaItem is saved', function () {
    $album = MediaAlbum::create([
        'title' => 'An album', 'category' => 'team_sa', 'is_published' => true,
    ]);

    $this->get('/media')->assertOk();
    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeTrue();

    MediaItem::create([
        'media_album_id' => $album->id,
        'original_filename' => 'photo.jpg',
        'path' => 'media/1/photo.jpg',
        'disk' => 'public',
    ]);

    expect(Cache::has(MediaAlbum::indexCacheKey(null)))->toBeFalse();
    expect(Cache::has(MediaItem::HOME_FEATURE_CACHE_KEY))->toBeFalse();
});
