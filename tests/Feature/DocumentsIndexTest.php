<?php

test('documents index is reachable without authentication', function () {
    $this->get(route('documents.index'))
        ->assertOk()
        ->assertSee('Documents')
        ->assertSee('SAPRF Publications');
});

test('documents index lists both selection policies with correct status badges', function () {
    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertSee('PR22 Team Selection (Rimfire)')
        ->assertSee('2027 Cycle')
        ->assertSee('Current')
        ->assertSee('PRS Team Selection (Centrefire)')
        ->assertSee('2026 Cycle')
        ->assertSee('Historical');
});

test('documents index lists the legal and governance documents', function () {
    $this->get(route('documents.index'))
        ->assertOk()
        ->assertSee('Constitution &amp; Memorandum of Incorporation', false)
        ->assertSee('Terms &amp; Conditions', false)
        ->assertSee('Privacy Policy')
        ->assertSee('POPIA compliance')
        ->assertSee('Code of Conduct')
        ->assertSee('Conflict of Interest Policy')
        ->assertSee('Judicial Code')
        ->assertSee('Social Media Policy');
});

test('every document link on the index resolves to a working page', function () {
    $this->get(route('rules.regulations'))->assertOk();
    $this->get(route('rules.divisions'))->assertOk();
    $this->get(route('rules.pr22-rimfire'))->assertOk();
    $this->get(route('selection.policy.public', ['series' => 'pr22']))->assertOk();
    $this->get(route('selection.policy.public', ['series' => 'prs', 'season' => '2026']))->assertOk();
    $this->get(route('legal.constitution'))->assertOk();
    $this->get(route('legal.terms'))->assertOk();
    $this->get(route('legal.privacy'))->assertOk();
    $this->get(route('legal.code-of-conduct'))->assertOk();
    $this->get(route('legal.conflict-of-interest'))->assertOk();
    $this->get(route('legal.judicial-code'))->assertOk();
    $this->get(route('legal.social-media-policy'))->assertOk();
});

test('judicial code page links the signed PDF and renders a distinctive section', function () {
    $response = $this->get(route('legal.judicial-code'))->assertOk();

    // Header pill points at the signed PDF original under public/publications.
    $response->assertSee('publications/saprf-judicial-code.pdf', escape: false)
        ->assertSee('Download original PDF');

    // Distinctive body content from the source markdown.
    $response->assertSee('Grievance / Mediation Process')
        ->assertSee('Arbitration Process');

    // Signed PDF exists on disk (linked from the page header).
    expect(is_file(public_path('publications/saprf-judicial-code.pdf')))->toBeTrue();
});

test('social media policy page links the signed PDF and renders a distinctive section', function () {
    $response = $this->get(route('legal.social-media-policy'))->assertOk();

    $response->assertSee('publications/saprf-social-media-policy.pdf', escape: false)
        ->assertSee('Download original PDF');

    // Distinctive body content — verbatim phrases only this policy carries.
    // assertSee escapes by default; the rendered HTML has a literal
    // apostrophe (CommonMark's html_input: escape only touches tags), so
    // we skip escaping here.
    $response->assertSee('Key Policy Principals')
        ->assertSee('Don\'t speak on our behalf', escape: false);

    expect(is_file(public_path('publications/saprf-social-media-policy.pdf')))->toBeTrue();
});

test('documents index lists the rules and regulations rulebooks', function () {
    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertSee('Rules &amp; Regulations', false)
        ->assertSee('SAPRF Rules &amp; Regulations', false)
        ->assertSee('SAPRF Divisions')
        ->assertSee('PR22 Rimfire Series Rules');

    // The Rules & Regulations cards link at the rendered HTML pages (not
    // the PDFs directly) so readers get the sticky-ToC, deep-link chrome.
    // The authoritative PDFs are exposed via the "Download original PDF"
    // pill inside those pages, not on the index.
    $response->assertSee('href="'.route('rules.regulations').'"', false)
        ->assertSee('href="'.route('rules.divisions').'"', false)
        ->assertSee('href="'.route('rules.pr22-rimfire').'"', false);

    // The signed PDF originals still exist on disk (they're linked from
    // the rulebook page headers).
    foreach ([
        'publications/saprf-rules-and-regulations.pdf',
        'publications/saprf-divisions.pdf',
        'publications/pr22-rimfire-series-rules.pdf',
    ] as $relPath) {
        expect(is_file(public_path($relPath)))->toBeTrue();
    }
});

test('documents link appears in the public nav and footer', function () {
    // Landing page hosts both the public nav and the footer, so we can
    // confirm the new link is present in both places at once.
    $response = $this->get('/')->assertOk();

    // Public nav (top of page) → link to /documents
    $response->assertSee('href="'.route('documents.index').'"', false);
    // Public footer → also has a "Documents" list item
    $response->assertSee('>Documents<', false);
});
