<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\View;

/**
 * Phase 2.2 guarantee: the status chip must render the discipline-rich
 * variants from the brief — "Entries open · Closes 11 Nov", "Not open yet",
 * "Closed", "Full" — and gracefully fall back to a short label when the
 * close date is absent or in the past.
 */
it('renders "Entries open · Closes <date>" when status is open with a future close date', function () {
    $html = View::make('components.status-chip', [
        'status' => 'open',
        'closeDate' => Carbon::parse('2026-11-11'),
    ])->render();

    expect($html)->toContain('Entries open');
    expect($html)->toContain('Closes 11 Nov');
})->skip(fn () => Carbon::parse('2026-11-11')->isPast(), 'close date must be in the future for this variant');

it('falls back to "Entries open" when the close date is not provided', function () {
    $html = View::make('components.status-chip', [
        'status' => 'open',
    ])->render();

    expect($html)->toContain('Entries open');
    expect($html)->not->toContain('Closes');
});

it('falls back to "Entries open" when the close date is in the past', function () {
    $html = View::make('components.status-chip', [
        'status' => 'open',
        'closeDate' => Carbon::now()->subDay(),
    ])->render();

    expect($html)->toContain('Entries open');
    expect($html)->not->toContain('Closes');
});

it('renders "Not open yet" for the upcoming state', function () {
    $html = View::make('components.status-chip', [
        'status' => 'upcoming',
    ])->render();

    expect($html)->toContain('Not open yet');
});

it('renders "Closed" for the closed state', function () {
    $html = View::make('components.status-chip', [
        'status' => 'closed',
    ])->render();

    expect($html)->toContain('Closed');
});

it('renders "Full" for the full state', function () {
    $html = View::make('components.status-chip', [
        'status' => 'full',
    ])->render();

    expect($html)->toContain('Full');
});
