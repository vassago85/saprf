<?php

/**
 * Coverage for the sport rulebooks published at /rules, /divisions and
 * /pr22-rimfire. All three go through the same shared MarkdownDocument
 * pipeline + <x-legal-document> chrome as the legal and selection pages,
 * so this suite locks in that:
 *
 *   • every route returns 200 and renders the shared component,
 *   • the "Download original PDF" pill appears in the header meta strip
 *     and points at the correct file under /publications,
 *   • the shared markdown pipeline actually ran (heading anchors +
 *     Alpine scaffolding present),
 *   • distinctive content from each rulebook is verbatim in the output.
 */

test('the rules & regulations page renders through the shared legal-document component', function () {
    $response = $this->get(route('rules.regulations'))->assertOk();
    $body = $response->getContent();

    expect($body)
        ->toContain('class="legal-doc"')
        ->toContain('SAPRF · Sport Rules')
        ->toContain('SAPRF Rules &amp; Regulations')
        ->toContain('class="heading-anchor"')
        ->toContain("Alpine.data('legalDoc'");

    // The header pill row carries the version + effective date.
    $response->assertSee('Version 2.1')
        ->assertSee('Effective 19 February 2024');

    // The header meta slot renders the "Download original PDF" pill
    // pointing at the authoritative file.
    $response->assertSee('Download original PDF')
        ->assertSee(asset('publications/saprf-rules-and-regulations.pdf'), false);
});

test('the divisions page renders through the shared legal-document component', function () {
    $response = $this->get(route('rules.divisions'))->assertOk();
    $body = $response->getContent();

    expect($body)
        ->toContain('class="legal-doc"')
        ->toContain('SAPRF · Sport Rules')
        ->toContain('SAPRF Divisions')
        ->toContain('class="heading-anchor"');

    $response->assertSee('Effective August 2023')
        ->assertSee('Download original PDF')
        ->assertSee(asset('publications/saprf-divisions.pdf'), false);
});

test('the pr22 rimfire series page renders through the shared legal-document component', function () {
    $response = $this->get(route('rules.pr22-rimfire'))->assertOk();
    $body = $response->getContent();

    expect($body)
        ->toContain('class="legal-doc"')
        ->toContain('SAPRF · Sport Rules')
        ->toContain('PR22 Rimfire Series Rules')
        ->toContain('class="heading-anchor"');

    $response->assertSee('Version 1')
        ->assertSee('Effective 12 December 2025')
        ->assertSee('Download original PDF')
        ->assertSee(asset('publications/pr22-rimfire-series-rules.pdf'), false);
});

test('the divisions page contains verbatim division definitions', function () {
    $response = $this->get(route('rules.divisions'))->assertOk();

    // A distinctive phrase from each division section — this proves the
    // markdown was found on disk and the pipeline actually rendered it.
    $response->assertSee('Open Division is the anything goes rifle division', escape: false)
        ->assertSee('.308 Winchester', escape: false)
        ->assertSee('Factory Division', escape: false)
        ->assertSee('Classic Division', escape: false)
        ->assertSee('16 lbs / 7.25 kg', escape: false);
});

test('the pr22 page contains verbatim rimfire rules', function () {
    $response = $this->get(route('rules.pr22-rimfire'))->assertOk();

    $response->assertSee('40 gn lead bullets', escape: false)
        ->assertSee('National Provincial Series', escape: false)
        ->assertSee('Glen Clark', escape: false)
        ->assertSee('Dr Andries Lategan', escape: false);
});

test('each rulebook page links back to the documents index', function () {
    foreach ([
        route('rules.regulations'),
        route('rules.divisions'),
        route('rules.pr22-rimfire'),
    ] as $url) {
        $response = $this->get($url)->assertOk();
        $response->assertSee('Back to Documents', escape: false)
            ->assertSee(route('documents.index'), false);
    }
});

test('the /documents index links to every rulebook page', function () {
    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertSee('href="'.route('rules.regulations').'"', false)
        ->assertSee('href="'.route('rules.divisions').'"', false)
        ->assertSee('href="'.route('rules.pr22-rimfire').'"', false);
});
