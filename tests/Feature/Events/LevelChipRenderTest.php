<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

/**
 * Phase 2.1 guarantee: when a match's series_level is NULL / empty, the
 * level-chip component must render nothing (previously it fell through to
 * an "Other" chip, which looked like real metadata on cards). An unknown
 * value should still render — only null is suppressed.
 */
it('renders nothing when the level is null', function () {
    $html = View::make('components.level-chip', [
        'level' => null,
        'matchName' => 'Some Match',
    ])->render();

    expect(trim($html))->toBe('');
});

it('renders nothing when the level is an empty string', function () {
    $html = View::make('components.level-chip', [
        'level' => '',
        'matchName' => 'Some Match',
    ])->render();

    expect(trim($html))->toBe('');
});

it('renders a chip for a known level', function () {
    $html = View::make('components.level-chip', [
        'level' => 'national',
        'matchName' => 'Some Match',
    ])->render();

    expect($html)->toContain('National');
});

it('suppresses the chip when the match name already ends with the level label', function () {
    // "Gauteng Provincial" already carries the label — the chip would be
    // visual duplication, so the component elides it.
    $html = View::make('components.level-chip', [
        'level' => 'provincial',
        'matchName' => 'Gauteng Provincial',
    ])->render();

    expect(trim($html))->toBe('');
});
