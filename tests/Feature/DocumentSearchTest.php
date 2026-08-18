<?php

/**
 * Coverage for the cross-document search at /documents/search?q=…
 *
 * The corpus (defined in App\Support\DocumentSearch::CORPUS) is scanned
 * on every request, sections are split on H2 boundaries, and hits are
 * scored + snippet-extracted. This suite locks in that:
 *
 *   • the intended queries land on the intended documents/sections,
 *   • deep-link anchors match the ids MarkdownDocument generates,
 *   • the empty / no-result / result states each render correctly,
 *   • the endpoint is XSS-safe (query is auto-escaped in the header
 *     and highlighting runs on a pre-escaped string).
 */

use App\Support\DocumentSearch;

test('documents search page is reachable without authentication', function () {
    $this->get(route('documents.search'))
        ->assertOk()
        ->assertSee('Search')
        ->assertSee('SAPRF Publications');
});

test('the search box appears on the /documents index and points at the search endpoint', function () {
    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertSee('action="'.route('documents.search').'"', false)
        ->assertSee('name="q"', false)
        ->assertSee('Search all SAPRF documents', escape: false);
});

test('an empty query on the search page shows the try-these examples', function () {
    $response = $this->get(route('documents.search'))->assertOk();

    $response->assertSee('pr22 provincial requirements', escape: false)
        ->assertSee('prs provincial requirements', escape: false)
        ->assertSee('ammunition', escape: false);
});

test('a "pr22 provincial requirements" query hits the PR22 Rimfire rulebook', function () {
    $response = $this->get(route('documents.search', ['q' => 'pr22 provincial requirements']))
        ->assertOk();

    // The PR22 Rimfire Series Rules document has a "6. National Provincial
    // Series" H2 section — the query should surface it. Section headings
    // in the corpus use the PDF's numbered convention (## 6. …), so the
    // slug preserves the leading digit.
    $response->assertSee('PR22 Rimfire Series Rules');
    $response->assertSee(
        route('rules.pr22-rimfire') . '#6-national-provincial-series',
        escape: false
    );
    // And confirm the highlighter actually fired.
    $response->assertSee('<mark', escape: false);
});

test('a "prs" query surfaces the PRS selection policy', function () {
    $response = $this->get(route('documents.search', ['q' => 'prs 2026']))->assertOk();

    $response->assertSee('PRS Team Selection (Centrefire)', escape: false)
        ->assertSee(
            route('selection.policy.public', ['series' => 'prs', 'season' => '2026']),
            escape: false
        );
});

test('a query with no matches shows the no-results state', function () {
    $response = $this->get(route('documents.search', ['q' => 'zzzzznothingzzzz']))
        ->assertOk();

    $response->assertSee('No results for')
        ->assertSee('zzzzznothingzzzz');
});

test('search results deep-link anchors match MarkdownDocument slug ids', function () {
    // MarkdownDocument::assignHeadingIds() uses Str::slug on the heading
    // text — DocumentSearch does the same. If someone changes the id
    // strategy on one side without the other, deep-links break and this
    // test catches it.
    $search = app(DocumentSearch::class);
    $hits = $search->search('provincial');

    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit['section_id'])
            ->toBe(\Illuminate\Support\Str::slug($hit['section_heading']));
    }
});

test('the search corpus covers every markdown document currently on disk', function () {
    $search = app(DocumentSearch::class);

    // Every legal doc, selection policy, rulebook, and FAQ that's
    // rendered elsewhere on the site should be findable via search.
    // The count is fluid (rulebook conversion is in progress) so we
    // just assert a floor rather than a fixed number.
    expect($search->corpusSize())->toBeGreaterThanOrEqual(9);
});

test('a script tag in the query cannot escape auto-escaping on the search page', function () {
    $payload = '<script>alert(1)</script>';
    $response = $this->get(route('documents.search', ['q' => $payload]))->assertOk();

    // Blade's {{ $query }} escapes to entities; the raw tag must NOT
    // appear anywhere in the response body.
    expect($response->getContent())->not->toContain('<script>alert(1)</script>');
    $response->assertSee('&lt;script&gt;', false);
});
