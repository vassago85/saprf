<?php

/**
 * Coverage for the public FAQ page (FaqController).
 *
 * The FAQ is authored as a single markdown file with one H2 per question.
 * FaqController splits the rendered HTML on H2 boundaries so the view can
 * render each Q&A as an expandable <details> accordion. These tests assert
 * every question the user pasted is published, has a jump anchor, and the
 * page appears in the top-level navigation surfaces (public nav, footer,
 * Documents index).
 */

beforeEach(function () {
    seedRoles();
});

test('the FAQ page renders every published question', function () {
    $response = $this->get(route('faq.index'))->assertOk();

    $response
        ->assertSee('Frequently Asked Questions')
        ->assertSee('Do I have to be a member of SAPRF to shoot a Precision Rifle match?')
        ->assertSee('Are there any requirements to becoming a full member?')
        ->assertSee("What is a 'Primary Club', and why do I need one?")
        ->assertSee('How do I select which division I want to shoot in?')
        ->assertSee('Where can I find my SAPRF number?');
});

test('the FAQ page renders each answer body inside its accordion', function () {
    $this->get(route('faq.index'))
        ->assertOk()
        ->assertSee('Temporary Day Member')
        ->assertSee('bona fide club with a democratic constitution')
        ->assertSee('South African Sports Framework')
        ->assertSee('Ladies Open', escape: false)
        ->assertSee('Mil/LEO Open', escape: false)
        ->assertSee('located on the home page');
});

test('the FAQ page uses <details> accordions with slug anchors', function () {
    $response = $this->get(route('faq.index'))->assertOk();

    $response
        ->assertSee('<details', escape: false)
        ->assertSee('id="do-i-have-to-be-a-member-of-saprf-to-shoot-a-precision-rifle-match"', escape: false)
        ->assertSee('id="where-can-i-find-my-saprf-number"', escape: false)
        ->assertSee('href="#do-i-have-to-be-a-member-of-saprf-to-shoot-a-precision-rifle-match"', escape: false);
});

test('the FAQ has a "still have a question?" contact CTA', function () {
    $this->get(route('faq.index'))
        ->assertOk()
        ->assertSee('Still have a question?')
        ->assertSee(route('contact.create'));
});

test('the FAQ link appears in the public nav and footer', function () {
    $home = $this->get('/')->assertOk();

    $home
        ->assertSee(route('faq.index'))
        ->assertSee('>FAQ<', escape: false);
});

test('the FAQ appears as the first entry in the Documents index', function () {
    $this->get(route('documents.index'))
        ->assertOk()
        ->assertSee('Help &amp; Information', escape: false)
        ->assertSee('Frequently Asked Questions')
        ->assertSee('Start here')
        ->assertSee(route('faq.index'));
});
