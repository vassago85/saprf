<?php

declare(strict_types=1);

use App\Support\ScoreFormat;

/**
 * Phase 2.3 guarantee: PRS and PR22 scores are impact counts — whole
 * integers — and should render without decimals. Everything else is a
 * precision discipline and keeps one decimal of resolution.
 */
it('formats PR22 scores as integers', function () {
    expect(ScoreFormat::format(145.0, 'PR22'))->toBe('145');
    expect(ScoreFormat::format(145, 'PR22'))->toBe('145');
    expect(ScoreFormat::format('145', 'PR22'))->toBe('145');
});

it('formats PRS scores as integers', function () {
    expect(ScoreFormat::format(132.0, 'PRS'))->toBe('132');
});

it('formats precision disciplines with one decimal', function () {
    expect(ScoreFormat::format(287.3, 'TR'))->toBe('287.3');
    expect(ScoreFormat::format(287, 'TR'))->toBe('287.0');
});

it('returns an em-dash for null and empty values', function () {
    expect(ScoreFormat::format(null))->toBe('—');
    expect(ScoreFormat::format(''))->toBe('—');
});

it('falls back to the series when match type is missing', function () {
    expect(ScoreFormat::format(145, null, 'PR22'))->toBe('145');
    expect(ScoreFormat::format(145, null, 'PRS'))->toBe('145');
    expect(ScoreFormat::format(287.3, null, 'TR'))->toBe('287.3');
});
