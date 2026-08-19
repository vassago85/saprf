<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

it('serves the Google Search Console verification file from the site root', function () {
    expect(file_exists(public_path('google79bd43f041dd2a84.html')))->toBeTrue();
    expect(file_get_contents(public_path('google79bd43f041dd2a84.html')))
        ->toContain('google-site-verification: google79bd43f041dd2a84.html');
});

it('points robots.txt at the sitemap and keeps authenticated areas out of the crawl', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)
        ->toContain('Sitemap: https://saprf.co.za/sitemap.xml')
        ->toContain('https://saprf.co.za/llms.txt')
        ->toContain('Disallow: /dashboard')
        ->toContain('Disallow: /login')
        ->not->toContain('Disallow: /events');
});

it('renders homepage SEO tags and SportsOrganization JSON-LD', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('official SAPRF platform for PRS and PR22', false)
        ->assertSee('<meta property="og:title"', false)
        ->assertSee('<meta property="og:url"', false)
        ->assertSee('<link rel="canonical"', false)
        ->assertSee('application/ld+json', false)
        ->assertSee('SportsOrganization', false)
        ->assertSee('index, follow', false);
});

it('gives public listing pages their own meta descriptions', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('Browse upcoming SAPRF PRS and PR22 matches', false);

    $this->get(route('standings.public'))
        ->assertOk()
        ->assertSee('Official SAPRF national and provincial standings', false);

    $this->get(route('faq.index'))
        ->assertOk()
        ->assertSee('Answers to common SAPRF questions', false);
});

it('marks login and the authenticated app shell as noindex', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('noindex, nofollow', false);

    $user = User::factory()->create();
    $this->actingAs($user);

    $html = view('components.layouts.app', [
        'slot' => new Illuminate\Support\HtmlString('<div>test</div>'),
    ])->render();

    expect($html)->toContain('noindex, nofollow');
});

it('serves a sitemap that lists public pages and published matches only', function () {
    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $director = User::factory()->create();

    $published = MatchEvent::create([
        'name' => 'SEO Published Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => $director->name,
        'active_member_fee' => 500,
        'created_by' => $director->id,
    ]);

    $draft = MatchEvent::create([
        'name' => 'SEO Draft Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonths(2),
        'status' => 'draft',
        'published' => false,
        'match_director' => $director->name,
        'active_member_fee' => 500,
        'created_by' => $director->id,
    ]);

    $this->get(route('sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee(route('home'), false)
        ->assertSee(route('events.index'), false)
        ->assertSee(route('standings.public'), false)
        ->assertSee(route('events.show', $published), false)
        ->assertDontSee(route('events.show', $draft), false)
        ->assertDontSee('/dashboard', false)
        ->assertSee(route('llms'), false);
});

it('serves llms.txt as a curated public map and llms-full.txt with the FAQ', function () {
    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $director = User::factory()->create();

    $published = MatchEvent::create([
        'name' => 'LLM Published Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => $director->name,
        'active_member_fee' => 500,
        'created_by' => $director->id,
    ]);

    $draft = MatchEvent::create([
        'name' => 'LLM Draft Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => now()->addMonths(2),
        'status' => 'draft',
        'published' => false,
        'match_director' => $director->name,
        'active_member_fee' => 500,
        'created_by' => $director->id,
    ]);

    $this->get(route('llms'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('South African Precision Rifle Federation', false)
        ->assertSee(route('events.index'), false)
        ->assertSee(route('standings.public'), false)
        ->assertSee(route('faq.index'), false)
        ->assertSee(route('events.show', $published), false)
        ->assertDontSee('LLM Draft Match', false)
        ->assertSee(url('/llms-full.txt'), false);

    $this->get(route('llms.full'))
        ->assertOk()
        ->assertSee('Do I have to be a member of SAPRF', false)
        ->assertSee('LLM Published Match', false)
        ->assertDontSee('LLM Draft Match', false);
});

it('points public pages at llms.txt for language-model crawlers', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('rel="alternate"', false)
        ->assertSee('href="'.url('/llms.txt').'"', false);
});
