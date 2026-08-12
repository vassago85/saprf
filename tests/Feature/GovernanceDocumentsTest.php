<?php

/**
 * Coverage for the two SAPRF governance documents published via
 * LegalController: the Code of Conduct (v1.0) and the Conflict of Interest
 * Policy (v1.2). Verifies each page renders verbatim content, generates a
 * jump-anchor TOC over its H2 sections, and appears in the public Documents
 * index alongside T&Cs / Privacy.
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

test('the documents index lists all four legal + governance documents', function () {
    $response = $this->get(route('documents.index'))->assertOk();

    $response->assertSee('Terms &amp; Conditions', false)
        ->assertSee('Privacy Policy')
        ->assertSee('Code of Conduct')
        ->assertSee('Conflict of Interest Policy');

    // And every link should resolve — no dangling URLs in the catalog.
    $response->assertSee(route('legal.terms'), false)
        ->assertSee(route('legal.privacy'), false)
        ->assertSee(route('legal.code-of-conduct'), false)
        ->assertSee(route('legal.conflict-of-interest'), false);
});
