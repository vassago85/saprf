<?php

declare(strict_types=1);

use App\Support\NameNormalizer;

/**
 * NameNormalizer is the single canonical form every "is this the same
 * shooter?" comparison in the app routes through. These tests pin the
 * contract so a tweak to the regex / Unicode rules cannot quietly let
 * two spellings drift apart again (which spawns duplicate stub users).
 */
it('lower-cases, trims, and collapses interior whitespace', function (string $input, string $expected) {
    expect(NameNormalizer::canonical($input))->toBe($expected);
})->with([
    'trim' => ['  Jaco  ', 'jaco'],
    'already lower' => ['jaco', 'jaco'],
    'camel' => ['Jaco Van Der Merwe', 'jaco van der merwe'],
    'double space' => ['Jaco  van  der  Merwe', 'jaco van der merwe'],
    'tab + newline' => ["Jaco\tvan\nder Merwe", 'jaco van der merwe'],
    'leading + trailing spaces' => ['   Terblanche De Jager   ', 'terblanche de jager'],
]);

it('considers mixed-case particles the same shooter', function () {
    expect(NameNormalizer::equals('Terblanche De Jager', 'Terblanche de Jager'))->toBeTrue();
    expect(NameNormalizer::equals('terblanche de jager', 'TERBLANCHE DE JAGER'))->toBeTrue();
});

it('strips comma / period / underscore punctuation while leaving hyphens', function () {
    expect(NameNormalizer::canonical('Smith, John'))->toBe('smith john');
    expect(NameNormalizer::canonical('J.R.R. Tolkien'))->toBe('j r r tolkien');
    expect(NameNormalizer::canonical('john_doe'))->toBe('john doe');
    // A hyphen inside a surname must be preserved — collapsing it would merge
    // "Smith-Jones" and "Smith Jones" together, which are not the same person.
    expect(NameNormalizer::canonical('Mary Smith-Jones'))->toBe('mary smith-jones');
    expect(NameNormalizer::equals('Mary Smith-Jones', 'Mary Smith Jones'))->toBeFalse();
});

it('treats empty / null as non-matching', function () {
    expect(NameNormalizer::canonical(null))->toBe('');
    expect(NameNormalizer::canonical(''))->toBe('');
    expect(NameNormalizer::canonical('   '))->toBe('');

    // Two empty strings must NOT match — a blank CSV cell should never
    // land on a user by accident.
    expect(NameNormalizer::equals('', ''))->toBeFalse();
    expect(NameNormalizer::equals('   ', ''))->toBeFalse();
    expect(NameNormalizer::equals('Jaco', ''))->toBeFalse();
});

it('normalises composed vs decomposed unicode to the same canonical form', function () {
    // é as a single code point (U+00E9) vs e + combining acute (U+0065 U+0301).
    $composed = "Andr\u{00E9} Botha";
    $decomposed = "Andre\u{0301} Botha";

    expect(NameNormalizer::canonical($composed))->toBe(NameNormalizer::canonical($decomposed));
    expect(NameNormalizer::equals($composed, $decomposed))->toBeTrue();
})->skip(! class_exists(\Normalizer::class), 'intl extension required');
