<?php

/**
 * Coverage for the SAPRF governance documents published via LegalController:
 * the Constitution / MOI (v2.0), Code of Conduct (v1.0), and the Conflict of
 * Interest Policy (v1.2). Verifies each page renders verbatim content,
 * generates a jump-anchor TOC over its H2 sections, and appears in the public
 * Documents index alongside T&Cs / Privacy.
 */

beforeEach(function () {
    seedRoles();
});

test('the code of conduct page renders verbatim markdown', function () {
    $this->get(route('legal.code-of-conduct'))
        ->assertOk()
        ->assertSee('Code of Conduct')
        ->assertSee('South African Practical Precision Rifle Federation')
        ->assertSee('Values Statement')
        ->assertSee('Technical Officials / Range Officers', escape: false)
        ->assertSee('Coaches / Instructors', escape: false)
        ->assertSee('Administrators / Councillors / Staff', escape: false);
});

test('the code of conduct page has a TOC over its 13 numbered sections', function () {
    $response = $this->get(route('legal.code-of-conduct'))->assertOk();

    $response->assertSee('id="1-introduction"', false)
        ->assertSee('href="#1-introduction"', false)
        ->assertSee('id="7-morality"', false)
        ->assertSee('id="13-administrators-councillors-staff"', false)
        ->assertSee('On this page');
});

test('the conflict of interest page renders verbatim markdown', function () {
    $this->get(route('legal.conflict-of-interest'))
        ->assertOk()
        ->assertSee('Conflict of Interest Policy')
        ->assertSee('South African Precision Rifle Federation')
        ->assertSee('Foreword')
        ->assertSee('Real conflict of interest')
        ->assertSee('Perceived conflict of interest')
        ->assertSee('Representatives will NOT', escape: false)
        ->assertSee('Appendix A');
});

test('the conflict of interest page has a TOC over its major sections', function () {
    $response = $this->get(route('legal.conflict-of-interest'))->assertOk();

    $response->assertSee('id="foreword"', false)
        ->assertSee('href="#foreword"', false)
        ->assertSee('id="1-definitions"', false)
        ->assertSee('id="4-obligations"', false)
        ->assertSee('On this page');
});

test('the constitution page renders verbatim markdown', function () {
    $this->get(route('legal.constitution'))
        ->assertOk()
        ->assertSee('Memorandum of Incorporation')
        ->assertSee('South African Practical Precision Rifle Federation')
        ->assertSee('v2.0')
        ->assertSee('Preamble')
        ->assertSee('Membership Types')
        ->assertSee('National Council')
        ->assertSee('Provincial Federation')
        ->assertSee('Annexure A');
});

test('the constitution page has a TOC over its 33 numbered clauses', function () {
    $response = $this->get(route('legal.constitution'))->assertOk();

    // Anchor targets on the H2s.
    $response->assertSee('id="preamble"', false)
        ->assertSee('id="1-constitution-name-and-corporate-personality"', false)
        ->assertSee('id="14-membership"', false)
        ->assertSee('id="17-structure-of-sapprf"', false)
        ->assertSee('id="25-finance"', false)
        ->assertSee('id="33-amendments-to-the-constitution"', false)
        ->assertSee('id="annexure-a-federation-diagram"', false);

    // TOC sidebar links.
    $response->assertSee('href="#preamble"', false)
        ->assertSee('href="#14-membership"', false)
        ->assertSee('On this page');
});

test('the constitution page splits clause numbers into a gutter span with data-depth', function () {
    $response = $this->get(route('legal.constitution'))->assertOk();
    $body = $response->getContent();

    // Every paragraph starting with an N.N. number should be lifted into the
    // .clause structure. Spot-check across depths 2 → 5.
    expect($body)
        ->toContain('class="clause-num">1.1.</span>')                  // depth 2
        ->toContain('class="clause-num">2.1.1.</span>')                // depth 3
        ->toContain('class="clause-num">14.5.2.5.4.</span>')           // depth 5, from a paragraph
        ->toContain('data-depth="2"')
        ->toContain('data-depth="3"')
        ->toContain('data-depth="5"')
        ->toContain('class="clause-body"');

    // Clause markup applied to <li> too — the 14.6.2.1 bulleted list.
    expect($body)->toContain('class="clause-num">14.6.2.1.</span>');
});

test('the constitution page injects hover-revealed anchor links on every heading', function () {
    $response = $this->get(route('legal.constitution'))->assertOk();
    $body = $response->getContent();

    // At least one .heading-anchor per H2 id, pointing at its own #id.
    expect($body)
        ->toContain('class="heading-anchor" aria-label="Link to this section"')
        ->toContain('href="#14-membership" class="heading-anchor"');
});

test('the constitution page wraps tables in a horizontal-scroll container', function () {
    $response = $this->get(route('legal.constitution'))->assertOk();

    // The §3.7 Interpretation definitions table should sit inside .table-scroll
    // so it doesn't blow out the 68ch reading measure on narrow viewports.
    $response->assertSee('class="table-scroll"', false)
        ->assertSee('Administrative Officer'); // sanity: the actual table renders inside it
});

test('the constitution header pill row surfaces version and effective date', function () {
    $response = $this->get(route('legal.constitution'))->assertOk();

    $response->assertSee('Version 2.0')
        ->assertSee('Effective 2 November 2025')
        ->assertSee('Print / Save as PDF');
});

test('the constitution page renders sticky ToC + scroll-spy scaffolding', function () {
    $response = $this->get(route('legal.constitution'))->assertOk();
    $body = $response->getContent();

    // Alpine wiring: the wrapper component + progress bar + back-to-top +
    // ToC filter search box must all be present so client-side polish
    // doesn't silently regress.
    expect($body)
        ->toContain("Alpine.data('legalDoc'")
        ->toContain('legal-doc-progress')
        ->toContain('legal-doc-back-to-top')
        ->toContain('id="toc-search"')
        ->toContain('aria-label="Table of contents"');
});

test('the documents index lists all five legal + governance documents', function () {
    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertSee('Constitution &amp; Memorandum of Incorporation', false)
        ->assertSee('Foundational')
        ->assertSee('Terms &amp; Conditions', false)
        ->assertSee('Privacy Policy')
        ->assertSee('Code of Conduct')
        ->assertSee('Conflict of Interest Policy');

    // And every link should resolve — no dangling URLs in the catalog.
    $response->assertSee(route('legal.constitution'), false)
        ->assertSee(route('legal.terms'), false)
        ->assertSee(route('legal.privacy'), false)
        ->assertSee(route('legal.code-of-conduct'), false)
        ->assertSee(route('legal.conflict-of-interest'), false);
});
