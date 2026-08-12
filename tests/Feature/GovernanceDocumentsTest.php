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
