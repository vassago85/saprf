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
        ->assertSee('Conflict of Interest Policy');
});

test('every document link on the index resolves to a working page', function () {
    $this->get(route('selection.policy.public', ['series' => 'pr22']))->assertOk();
    $this->get(route('selection.policy.public', ['series' => 'prs', 'season' => '2026']))->assertOk();
    $this->get(route('legal.constitution'))->assertOk();
    $this->get(route('legal.terms'))->assertOk();
    $this->get(route('legal.privacy'))->assertOk();
    $this->get(route('legal.code-of-conduct'))->assertOk();
    $this->get(route('legal.conflict-of-interest'))->assertOk();
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
