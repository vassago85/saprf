<?php

/**
 * Match directors can upload a listing banner and/or full poster from match setup.
 * Public event cards always show confirmed + pending entry counts,
 * with a capacity progress bar only when max_competitors is set.
 */

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();

    Storage::fake('public');

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');
});

function bannerMatchFormPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Banner Setup Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'match_date' => now()->addMonth()->format('Y-m-d'),
        'active_member_fee' => 250,
        'status' => 'open',
    ], $overrides);
}

function makePublicMatch(User $creator, Province $province, array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Public Entries Match',
        'match_type' => 'PRS',
        'series_level' => 'club',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => $creator->name,
        'active_member_fee' => 250,
        'non_member_fee' => 250,
        'lapsed_member_fee' => 250,
        'created_by' => $creator->id,
    ], $overrides));
}

it('lets a match director upload a banner when creating a match', function () {
    $banner = UploadedFile::fake()->image('flyer.jpg', 1200, 500);

    $this->actingAs($this->md)
        ->post(route('matches.store'), bannerMatchFormPayload([
            'banner' => $banner,
        ]))
        ->assertRedirect();

    $match = MatchEvent::query()->where('name', 'Banner Setup Match')->first();

    expect($match)->not->toBeNull()
        ->and($match->banner_path)->not->toBeNull()
        ->and($match->bannerUrl())->toContain('storage/');

    Storage::disk('public')->assertExists($match->banner_path);
});

it('lets a match director replace and remove a banner on update', function () {
    $match = makePublicMatch($this->md, $this->province, [
        'banner_path' => UploadedFile::fake()->image('old.jpg')->store('match-banners', 'public'),
    ]);
    $oldPath = $match->banner_path;

    $replacement = UploadedFile::fake()->image('new.png', 1200, 500);

    $this->actingAs($this->md)
        ->put(route('matches.update', $match), bannerMatchFormPayload([
            'name' => $match->name,
            'banner' => $replacement,
        ]))
        ->assertRedirect(route('matches.show', $match));

    $match->refresh();

    expect($match->banner_path)->not->toBe($oldPath)
        ->and($match->banner_path)->not->toBeNull();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($match->banner_path);

    $this->actingAs($this->md)
        ->put(route('matches.update', $match), bannerMatchFormPayload([
            'name' => $match->name,
            'remove_banner' => '1',
        ]))
        ->assertRedirect(route('matches.show', $match));

    $pathAfterRemove = $match->banner_path;
    $match->refresh();

    expect($match->banner_path)->toBeNull()
        ->and($match->bannerUrl())->toBeNull();

    Storage::disk('public')->assertMissing($pathAfterRemove);
});

it('shows entry counts without a capacity cap on the event card and show page', function () {
    $match = makePublicMatch($this->md, $this->province, [
        'name' => 'Unlimited Cap Match',
        'max_competitors' => null,
        'match_director' => 'Dirk Pio',
    ]);

    $shooter = User::factory()->create();
    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250.00,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $shooter2 = User::factory()->create();
    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter2->id,
        'shooter_name' => $shooter2->name,
        'email' => $shooter2->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250.00,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('2 entries', false);

    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertSee('Entries', false)
        ->assertSee('>2<', false);
});

it('shows capped entry counts with a progress label when max_competitors is set', function () {
    $match = makePublicMatch($this->md, $this->province, [
        'name' => 'Capped Match',
        'max_competitors' => 40,
    ]);

    $shooter = User::factory()->create();
    MatchRegistration::create([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 250.00,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('1/40 entries', false);
});

it('renders the match banner on the public event page when set', function () {
    $path = UploadedFile::fake()->image('banner.jpg', 1200, 500)->store('match-banners', 'public');

    $match = makePublicMatch($this->md, $this->province, [
        'name' => 'Bannered Public Match',
        'banner_path' => $path,
    ]);

    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertSee(asset('storage/'.$path), false);
});

it('lets a match director upload a poster when creating a match', function () {
    $poster = UploadedFile::fake()->image('flyer.png', 800, 1400);

    $this->actingAs($this->md)
        ->post(route('matches.store'), bannerMatchFormPayload([
            'name' => 'Poster Setup Match',
            'poster' => $poster,
        ]))
        ->assertRedirect();

    $match = MatchEvent::query()->where('name', 'Poster Setup Match')->first();

    expect($match)->not->toBeNull()
        ->and($match->poster_path)->not->toBeNull()
        ->and($match->posterUrl())->toContain('storage/');

    Storage::disk('public')->assertExists($match->poster_path);
});

it('prefers the full poster over the listing banner on the public event page', function () {
    $bannerPath = UploadedFile::fake()->image('banner.jpg', 1200, 500)->store('match-banners', 'public');
    $posterPath = UploadedFile::fake()->image('poster.png', 800, 1400)->store('match-posters', 'public');

    $match = makePublicMatch($this->md, $this->province, [
        'name' => 'Poster Preferred Match',
        'banner_path' => $bannerPath,
        'poster_path' => $posterPath,
    ]);

    $this->get(route('events.show', $match))
        ->assertOk()
        ->assertSee(asset('storage/'.$posterPath), false)
        ->assertSee('promotional poster', false)
        ->assertDontSee(asset('storage/'.$bannerPath), false);
});

it('uses the listing banner on event cards and falls back to the poster', function () {
    $bannerPath = UploadedFile::fake()->image('card.jpg', 1200, 500)->store('match-banners', 'public');
    $posterOnlyPath = UploadedFile::fake()->image('only-poster.png', 800, 1400)->store('match-posters', 'public');

    $withBanner = makePublicMatch($this->md, $this->province, [
        'name' => 'Card Banner Match',
        'banner_path' => $bannerPath,
        'poster_path' => UploadedFile::fake()->image('ignored.png', 800, 1400)->store('match-posters', 'public'),
    ]);

    $posterOnly = makePublicMatch($this->md, $this->province, [
        'name' => 'Poster Fallback Match',
        'poster_path' => $posterOnlyPath,
    ]);

    expect($withBanner->listingImageUrl())->toBe(asset('storage/'.$bannerPath))
        ->and($posterOnly->listingImageUrl())->toBe(asset('storage/'.$posterOnlyPath));

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee(asset('storage/'.$bannerPath), false)
        ->assertSee(asset('storage/'.$posterOnlyPath), false);
});

it('shows a quiet listing caption on national and championship cards with images', function () {
    $path = UploadedFile::fake()->image('banner.jpg', 1200, 500)->store('match-banners', 'public');

    $championship = makePublicMatch($this->md, $this->province, [
        'name' => 'Rimfire PR22 GP Championship',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'final',
        'match_date' => Carbon::today()->addMonths(2),
        'match_end_date' => Carbon::today()->addMonths(2)->addDay(),
        'banner_path' => $path,
    ]);

    expect($championship->listingCaption())->toBe('2-Day Championship');

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('2-Day Championship', false);
});

it('lets a match director replace and remove a poster on update', function () {
    $match = makePublicMatch($this->md, $this->province, [
        'poster_path' => UploadedFile::fake()->image('old-poster.jpg')->store('match-posters', 'public'),
    ]);
    $oldPath = $match->poster_path;

    $replacement = UploadedFile::fake()->image('new-poster.png', 800, 1400);

    $this->actingAs($this->md)
        ->put(route('matches.update', $match), bannerMatchFormPayload([
            'name' => $match->name,
            'poster' => $replacement,
        ]))
        ->assertRedirect(route('matches.show', $match));

    $match->refresh();

    expect($match->poster_path)->not->toBe($oldPath)
        ->and($match->poster_path)->not->toBeNull();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($match->poster_path);

    $this->actingAs($this->md)
        ->put(route('matches.update', $match), bannerMatchFormPayload([
            'name' => $match->name,
            'remove_poster' => '1',
        ]))
        ->assertRedirect(route('matches.show', $match));

    $pathAfterRemove = $match->poster_path;
    $match->refresh();

    expect($match->poster_path)->toBeNull()
        ->and($match->posterUrl())->toBeNull();

    Storage::disk('public')->assertMissing($pathAfterRemove);
});
